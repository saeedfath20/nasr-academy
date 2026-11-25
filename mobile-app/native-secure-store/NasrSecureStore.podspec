Pod::Spec.new do |s|
 s.name = 'NasrSecureStore'
 s.version = '1.1.0'
 s.summary = 'Private session vault for Nasr Academy'
 s.license = 'GPL-2.0-or-later'
 s.homepage = 'https://capacitorjs.com'
 s.author = 'Nasr Academy'
 s.source = { :path => '.' }
 s.source_files = 'ios/Sources/NasrSecureStorePlugin/**/*.{swift,h,m}'
 s.ios.deployment_target = '15.0'
 s.dependency 'Capacitor'
 s.swift_version = '5.9'
end
