import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
const app=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..'),root=path.dirname(app);
export const hash=data=>crypto.createHash('sha256').update(data).digest('hex');
export async function prepare(){
 const release=JSON.parse(await fs.readFile(path.join(root,'release.json'),'utf8'));
 if(!/^\d+\.\d+\.\d+$/.test(release.version))throw new Error('Use a numeric major.minor.patch release version.');
 const parts=release.version.split('.').map(Number);if(parts.some(x=>x>999))throw new Error('Version component exceeds limit');
 release.sequence=parts[0]*1000000+parts[1]*1000+parts[2];
 const site=new URL(release.site);if(site.protocol!=='https:'||site.username||site.password||site.search||site.hash)throw new Error('A clean HTTPS site URL is required');
 const pub=crypto.createPublicKey(await fs.readFile(path.join(root,'release/public.pem'))),der=pub.export({type:'spki',format:'der'}).toString('hex');
 const java=path.join(app,'android/app/src/main/java/com/nasr/academy'),fingerprint=[];
 async function add(dir,prefix){for(const f of (await fs.readdir(dir,{withFileTypes:true})).sort((a,b)=>a.name.localeCompare(b.name))){if(['ReleaseTrust.java','build','node_modules','.gradle','.DS_Store'].includes(f.name))continue;if(f.isDirectory())await add(path.join(dir,f.name),prefix+f.name+'/');else if(f.isFile())fingerprint.push(prefix+f.name+':'+hash(await fs.readFile(path.join(dir,f.name))));}}
 await add(java,'app/');await add(path.join(app,'native-secure-store'),'vault/');
 const lock=JSON.parse(await fs.readFile(path.join(app,'package-lock.json'),'utf8'));
 const nativePackages=Object.entries(lock.packages||{}).filter(([name])=>name.startsWith('node_modules/@capacitor/')).map(([name,p])=>[name,p.version]).sort(([a],[b])=>a.localeCompare(b));
 for(const file of ['android/app/src/main/AndroidManifest.xml','android/variables.gradle'])fingerprint.push(file+':'+hash(await fs.readFile(path.join(app,file))));
 const gradleFile=path.join(app,'android/app/build.gradle');let gradle=await fs.readFile(gradleFile,'utf8');
 fingerprint.push(gradle.split('\n').filter(line=>/^\s*implementation /.test(line)).map(line=>line.trim()).join('\n'));
 fingerprint.push(JSON.stringify(nativePackages),JSON.stringify({appId:release.appId,site:site.href,protocol:release.protocol}));
 gradle=gradle.replace(/versionName "[^"]+"/,`versionName "${release.version}"`).replace(/versionCode (\d+)/,(_,value)=>'versionCode '+Math.max(Number(value),release.sequence));await fs.writeFile(gradleFile,gradle);
 const abi=hash(fingerprint.join('\n')),endpoint=new URL('?nasr_mobile_release=manifest',site).href;
 await fs.writeFile(path.join(java,'ReleaseTrust.java'),`package com.nasr.academy;\n// Generated. The private signing key is never packaged.\npublic final class ReleaseTrust {\n public static final String PUBLIC_KEY="${der}";\n public static final String ABI="${abi}";\n public static final String ENDPOINT="${endpoint}";\n public static final long SEQUENCE=${release.sequence}L;\n private ReleaseTrust(){}\n}\n`);
 const source=path.join(root,'nasr-academy/assets'),target=path.join(app,'public/core');await fs.rm(target,{recursive:true,force:true});await fs.mkdir(target,{recursive:true});
 const manifest={};for(const file of ['online.js','portal.js','portal-features.js','portal.css','nasr-logo.png','nasr-font.woff']){const data=await fs.readFile(path.join(source,file));await fs.writeFile(path.join(target,file),data);manifest[file]=hash(data);}
 for(const file of ['app.css','app-ui.js','app-auth.js'])await fs.copyFile(path.join(source,'mobile',file),path.join(app,'public',file));
 const api=new URL('wp-json/nasr/v1/',site).href;
 await fs.writeFile(path.join(app,'public/app-config.js'),`// Public configuration only.\nwindow.NasrAppConfig={version:${JSON.stringify(release.version)},api:${JSON.stringify(api)},assets:new URL('./core/',location.href).href};\n`);
 await fs.writeFile(path.join(app,'public/core-manifest.json'),JSON.stringify(manifest,null,2)+'\n');
 const files={};for(const f of ['app-config.js','app.css','app-ui.js','app-auth.js'])files[f]=hash(await fs.readFile(path.join(app,'public',f)));
 await fs.writeFile(path.join(app,'public/app-build.json'),JSON.stringify({version:release.version,files},null,2)+'\n');

 return {...release,shellAbi:abi};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){const r=await prepare();console.log('Shared interface prepared: '+r.version);}
