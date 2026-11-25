#!/bin/bash
set -euo pipefail
nasr_root="$(cd -- "$(dirname -- "$0")" && pwd)"
if [[ "$(uname -s)" != "Darwin" ]]; then
  echo 'iOS preparation requires a Mac with Xcode 26 or newer.' >&2
  exit 1
fi
for nasr_command in node npm xcodebuild; do
  if ! command -v "$nasr_command" >/dev/null 2>&1; then
    echo "Required tool is missing: $nasr_command. See IOS-START-fa.md." >&2
    exit 1
  fi
done
node -e 'if(Number(process.versions.node.split(".")[0])<22){console.error("Node.js 22 or newer is required.");process.exit(1)}'
nasr_xcode_version="$(xcodebuild -version)"
nasr_xcode_major="$(printf '%s\n' "$nasr_xcode_version" | awk '/^Xcode / {split($2, parts, "."); print parts[1]}')"
if [[ ! "$nasr_xcode_major" =~ ^[0-9]+$ ]] || (( nasr_xcode_major < 26 )); then
  echo 'Select the full Xcode 26+ installation in Xcode > Settings > Locations.' >&2
  exit 1
fi
cd -- "$nasr_root/mobile-app"
npm ci
npm run build
npx cap sync ios
echo 'iOS project prepared. Select your signing Team and an iPhone in Xcode.'
npx cap open ios
