import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash, createPublicKey, verify} from 'node:crypto';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {tree, zip} from './zip.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));

export async function packageWeb() {
    const {webVersion, version, protocol, appId} = JSON.parse(
        await fs.readFile(path.join(root, 'release.json'), 'utf8'),
    );
    if (typeof webVersion !== 'string' || !/^\d+\.\d+\.\d+$/.test(webVersion)) {
        throw new Error('A numeric WordPress webVersion is required');
    }

    const plugin = path.join(root, 'nasr-academy');
    const php = await fs.readFile(path.join(plugin, 'nasr-academy.php'), 'utf8');
    const headerVersion = php.match(/^\s*\* Version:\s*(\S+)/m)?.[1];
    const runtimeVersion = php.match(/define\('NASR_ACADEMY_VERSION', '([^']+)'\)/)?.[1];
    if (headerVersion !== webVersion || runtimeVersion !== webVersion) {
        throw new Error('WordPress header and runtime version must match webVersion');
    }

    const ota = path.join(plugin, 'mobile-release');
    const [manifest, bundle, publicPem, pinnedPem] = await Promise.all([
        fs.readFile(path.join(ota, 'manifest.json'), 'utf8'),
        fs.readFile(path.join(ota, 'bundle.zip')),
        fs.readFile(path.join(ota, 'public.pem')),
        fs.readFile(path.join(root, 'release/public.pem')),
    ]);
    const {payload, signature} = JSON.parse(manifest);
    const publicKey = createPublicKey(publicPem);
    const pinnedKey = createPublicKey(pinnedPem);
    if (!publicKey.equals(pinnedKey) ||
        !verify('RSA-SHA256', Buffer.from(payload), publicKey, Buffer.from(signature, 'hex'))) {
        throw new Error('The bundled mobile release does not match the pinned signing key');
    }
    const release = JSON.parse(payload);
    if (release.version !== version || release.protocol !== protocol || release.appId !== appId ||
        release.size !== bundle.length || release.sha256 !== createHash('sha256').update(bundle).digest('hex')) {
        throw new Error('The bundled mobile release does not match release.json or its signed digest');
    }

    const included = new Set([
        'assets', 'includes', 'mobile-release', 'index.php', 'nasr-academy.php',
        'PAYMENT-GATEWAYS-fa.md', 'README-fa.md', 'UNIFIED-fa.md', 'WEB-1.6.1-fa.md',
    ]);
    const files = await tree(plugin, 'nasr-academy/', (name, item) =>
        included.has(name.split('/')[0]) &&
        (item.isDirectory() || /\.(php|js|css|png|woff|md)$/i.test(name) ||
            ['mobile-release/bundle.zip', 'mobile-release/manifest.json', 'mobile-release/public.pem'].includes(name)),
    );
    const output = path.join(root, 'dist-release');
    await fs.mkdir(output, {recursive: true});
    const filename = path.join(output, `nasr-academy-plugin-${webVersion}.zip`);
    await fs.writeFile(filename, zip(files));
    return filename;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    console.log(await packageWeb());
}
