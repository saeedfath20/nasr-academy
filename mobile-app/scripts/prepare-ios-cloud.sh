#!/bin/bash
set -euo pipefail
if [[ "$(uname -s)" != "Darwin" ]]; then
  echo 'This workflow must run on a macOS build machine.' >&2
  exit 1
fi
nasr_app_root="$(cd -- "$(dirname -- "$0")/.." && pwd)"
node -e 'if(Number(process.versions.node.split(".")[0])<22){console.error("Node.js 22 or newer is required.");process.exit(1)}'
nasr_xcode_version="$(xcodebuild -version)"
nasr_xcode_major="$(printf '%s\n' "$nasr_xcode_version" | awk '/^Xcode / {split($2, parts, "."); print parts[1]}')"
if [[ ! "$nasr_xcode_major" =~ ^[0-9]+$ ]] || (( nasr_xcode_major < 26 )); then
  echo 'Xcode 26 or newer is required.' >&2
  exit 1
fi
cd -- "$nasr_app_root"
npm ci
npm run build
npx cap sync ios
