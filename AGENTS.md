# Nasr shared release source

- Keep the existing WordPress schema/data and mobile identity. Never uninstall the mobile package, clear app data, replace a user's keystore, change vault names, crypto aliases, localhost origin, app ID, or sign out users as part of an update.
- PHP/API and shared portal assets live in nasr-academy/. The PWA module is integrated in includes/class-webapp.php. The old standalone add-on is retired without deleting data.
- Canonical mobile CSS/UI/native authentication scripts live in nasr-academy/assets/mobile/. Do not edit mobile-app/public/ copies; prepare-core generates them from the canonical files. Browser and native authentication intentionally have separate adapters.
- Treat the source directories at the repository root as canonical. The two original ZIPs are historical inputs. Do not hand-edit or use them to overwrite working sources.
- WordPress and the integrated PWA share `webVersion` in `release.json`. Keep it aligned with the plugin header and `NASR_ACADEMY_VERSION`. Use `node scripts/release-web.mjs` to package WordPress and PWA without modifying the signed Android bundle.
- Keep Android/OTA on `version` in `release.json` (currently 1.6.0). Keep `mobileUpdatesPaused` enabled until the owner authorizes a mobile release. When authorized, advance the mobile version for changed code and use the shared signed release command.
- Use staging with synthetic records and sandbox or mocked payment/SMS providers. Do not deploy to production, send real messages, initiate real payments, or publish an APK as part of development or PR verification.
- Keep credentials, private signing material, user exports and database dumps outside the repository. The pinned public OTA keys are intentionally tracked.
- Validate web packaging and run `node tests/update-engine.mjs` with JDK 21. For mobile UI changes also run `npm ci` and `npm run build` in `mobile-app/`.
- Reuse the existing private OTA signing key and public trust. Never generate a replacement key during normal releases or include private keys/keystores in WordPress ZIPs, APKs or source archives.
- Native changes require an APK signed with the original installation's signing key. Interface updates must retain native compatibility checks, signature verification, digest checks, safe ZIP paths, atomic staging and startup rollback.
- Keep all academic writes online. Do not add offline queues or browser storage for credentials/academic data.
- Verify modified PHP syntax, integrated PWA login/roles and code-update state transitions as appropriate. Preserve the working mobile design, remember-login and biometric behavior.
