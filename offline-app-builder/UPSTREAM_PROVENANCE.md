# Offline App Builder Upstream Provenance

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
