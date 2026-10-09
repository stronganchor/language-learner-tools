# Offline App Builder Upstream Provenance

## Capacitor and sharp security baseline

- Verified October 9, 2026.
- Capacitor core, Android, and CLI packages are pinned together at `8.4.3`, a
  patched same-major backport of
  [GHSA-rvm3-566m-v7fv](https://github.com/ionic-team/capacitor/security/advisories/GHSA-rvm3-566m-v7fv).
  The [8.4.3 release record](https://github.com/ionic-team/capacitor/blob/8.4.3/CHANGELOG.md)
  includes main-frame and subframe internal-proxy navigation blocking. This
  baseline avoids the affected iOS `xcode`/`uuid` dependency introduced by the
  8.5.1 CLI. The Android integration remains in `android-overrides/`.
- `sharp` requires `0.35.5` or newer for
  [GHSA-wq5f-xc86-pv6w](https://github.com/lovell/sharp/security/advisories/GHSA-wq5f-xc86-pv6w).
  The icon reader rejects unsupported byte signatures before native parsing,
  disables SVG loading, and passes one bounded byte snapshot through metadata
  validation and every launcher render.
- The Windows Node builder suite passes 21 tests and the final dependency
  advisory check reports no vulnerabilities. These checks cover archive
  rollback, icon input isolation, and generated configuration; they do not
  establish installed-app or physical-device behavior.
- The existing prepared bundle builds successfully as a debug APK with JDK 21,
  the Android 36 SDK, and all three configured native ABIs (`arm64-v8a`,
  `armeabi-v7a`, `x86_64`). The existing Android PCM unit suite passes three
  tests. Generated debug artifacts remain local and ignored.
- Capacitor fixes require an APK rebuild and a separately coordinated update
  of installed apps. Release signing, distribution, installed-app navigation,
  microphone behavior, offline STT inference and sync remain device/rollout
  acceptance checks. No release APK was signed or distributed in this pass.

## whisper.cpp

- Upstream: `https://github.com/ggml-org/whisper.cpp`
- Upstream commit: `95ea8f9bfb03a15db08a8989966fd1ae3361e20d`
- Upstream version at that commit: `1.8.4`
- Vendored path: `android-overrides/app/src/main/jni/w`
- Local integration path: `android-overrides/app/src/main/jni/CMakeLists.txt`
- License: MIT, retained at `android-overrides/app/src/main/jni/w/LICENSE`
- Verification date: 2026-07-10

The normalized vendored tree was compared with the upstream commit during the
2026-07-10 codebase audit and matched exactly. LL Tools integration code lives
outside the vendored `w` directory. The native build exposes the short upstream
commit in `WHISPER_VERSION` as `android-bundled-95ea8f9`.

When updating the dependency, replace the entire vendored tree from one named
upstream commit, retain its license, update this record and the compiled version
string, and compare the resulting tree before running the Android build tests.
