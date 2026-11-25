// swift-tools-version: 5.9
import PackageDescription
let package = Package(name: "NasrSecureStore", platforms: [.iOS(.v15)], products: [.library(name: "NasrSecureStore", targets: ["NasrSecureStore"])], dependencies: [.package(url: "https://github.com/ionic-team/capacitor-swift-pm.git", from: "8.0.0")], targets: [.target(name: "NasrSecureStore", dependencies: [.product(name: "Capacitor", package: "capacitor-swift-pm")], path: "ios/Sources/NasrSecureStorePlugin")])
