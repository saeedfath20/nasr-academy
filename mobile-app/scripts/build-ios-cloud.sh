#!/bin/bash
set -euo pipefail
nasr_mode="${1:-simulator}"
case "$nasr_mode" in simulator|ipa) ;; *) echo 'Usage: build-ios-cloud.sh simulator|ipa' >&2; exit 2 ;; esac
if [[ "$(uname -s)" != "Darwin" ]]; then
  echo 'Xcode on a macOS build machine is required.' >&2
  exit 1
fi
nasr_app_root="$(cd -- "$(dirname -- "$0")/.." && pwd)"
nasr_project_root="$(cd -- "$nasr_app_root/.." && pwd)"
nasr_project="$nasr_app_root/ios/App/App.xcodeproj"
nasr_output_root="${CM_BUILD_DIR:-$nasr_project_root}"
cd -- "$nasr_project_root"
if [[ "$nasr_mode" == "simulator" ]]; then
  nasr_output="$nasr_output_root/build/ios-simulator"
  mkdir -p "$nasr_output"
  xcodebuild build -project "$nasr_project" -scheme App \
    -configuration Debug -sdk iphonesimulator \
    -destination 'generic/platform=iOS Simulator' \
    -derivedDataPath "$nasr_output/DerivedData" \
    CODE_SIGN_IDENTITY='' CODE_SIGNING_REQUIRED=NO CODE_SIGNING_ALLOWED=NO \
    2>&1 | tee "$nasr_output/xcodebuild.log"
  ditto -c -k --keepParent \
    "$nasr_output/DerivedData/Build/Products/Debug-iphonesimulator/App.app" \
    "$nasr_output/Nasr-iOS-simulator.zip"
  echo 'Simulator application built. This ZIP cannot be installed on an iPhone.'
else
  # Fresh CI workers receive signing identities from Codemagic's ios_signing block.
  xcode-project use-profiles
  nasr_ipa="$nasr_output_root/build/ios/ipa"
  nasr_archive="$nasr_output_root/build/ios/archive"
  xcode-project build-ipa --project "$nasr_project" --scheme App \
    --ipa-directory "$nasr_ipa" --archive-directory "$nasr_archive"
  echo 'Signed IPA built. App Store Connect upload and TestFlight distribution are separate steps.'
fi
