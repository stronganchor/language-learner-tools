# Offline App Builder

This folder turns an `LL Offline App Export` bundle into an Android APK.

## Source boundaries and navigation

| Task | Start here |
| --- | --- |
| Export selection, resumable category/word/media/data/zip jobs | `../includes/admin/offline-app-export.php` |
| Wordset manager export controls | `../js/wordset-offline-export.js` and the offline-export helpers in `../includes/pages/wordset-pages.php` |
| Exported web shell and Study/Games launcher | `../templates/offline-app-shell-template.php`, `../offline-app/offline-app.js` |
| Local progress queue and server synchronization | `../js/flashcard-widget/progress-tracker.js`, `../includes/offline-app-sync.php` |
| Archive preparation and generated Capacitor configuration | `scripts/prepare-bundle.mjs` |
| Android project, package/version properties, toolchain detection and signing | `scripts/build-apk.mjs` |
| Optional STT injection and launcher icon | `scripts/inject-stt-bundle.mjs`, `scripts/apply-app-icon.mjs` |
| First-party Android STT plugin, model resolution and PCM checks | `android-overrides/app/src/main/java/com/lltools/offline/offline/quiz/stt/` and `android-overrides/app/src/main/jni/lltools_whisper_jni.c` |
| Vendored whisper.cpp/ggml boundary | `UPSTREAM_PROVENANCE.md` and `android-overrides/app/src/main/jni/w/` |

Exporting from WordPress, preparing a web bundle, building an APK, and verifying
the installed Android app are separate steps. A passing export or Node test
does not prove native compilation or on-device inference. The vendored `w/`
tree is upstream code; keep LL Tools integration changes outside it.

## Prerequisites

- Node.js 22 or newer
- Android Studio + Android SDK
- Java/Gradle tooling required by Capacitor
- Android NDK + CMake (required for the packaged offline STT runtime)

## Install

```bash
cd offline-app-builder
npm install
```

## Prepare a bundle

```bash
npm run prepare:bundle -- /absolute/path/to/ll-tools-offline-app.zip
```

This extracts the bundle into `workspace/bundle/`, records
`workspace/bundle-state.json`, and writes `capacitor.config.json`. An extracted
bundle directory is also accepted. Its root must contain `bundle-manifest.json`
and `www/index.html`, with no symbolic links or directory junctions. Preparation
validates a staged copy before publishing the workspace, configuration and
state with rollback on publication errors. A validation,
copy or publication failure preserves the previous preparation; if the
filesystem also refuses rollback, the error identifies the retained backups.
Keep the original export outside `workspace/bundle/`; preparation rejects a
source inside that destination or an input directory containing the workspace.

WordPress export steps hold an OS file lock before rereading their checkpoint.
The lock remains owned for the whole step without a five-minute takeover and
is released automatically if its process exits. Lock files under the export
storage's `.locks/` directory must remain in place, including during job
cleanup. A lost HTTP response can resume from a completed checkpoint. If a
worker stops between appending output and publishing the next checkpoint, the
export fails with a request to start a new export; replaying that uncertain
append would corrupt the bundle.
On WSL, `/mnt/c/...` and `C:\...` bundle paths are both supported.
If the bundle includes an app icon, the build scripts use it for the Android launcher icon automatically.
If the bundle includes a wordset-specific offline STT bundle, it is kept under `workspace/bundle/www/content/stt-models/...` and packaged into the APK with the rest of the web assets.
Archive preparation rejects compressed zip files larger than 2 GiB before opening them, absolute/traversal paths, symbolic links, more than 20,000 entries, entries larger than 2 GiB, and archives larger than 4 GiB uncompressed. Code-level overrides of the compressed-file limit remain hard-capped at 4 GiB. IPA training `data.json` entries are capped at 128 MiB before they are read into memory.

If you already have an offline app export zip and want to inject a mobile-ready STT bundle plus offline `Speaking Practice` metadata after the fact, run:

```bash
npm run inject:stt -- --bundle /absolute/path/to/ll-tools-offline-app.zip --stt-source /absolute/path/to/mobile-stt-bundle --ipa-zips-dir /absolute/path/to/ipa-training-zips
```

This updates the prepared bundle in `workspace/bundle/`, copies the STT model under `www/content/stt-models/...`, rebuilds the offline speaking-game catalog from the supplied IPA training zips, and writes a new `-with-stt.zip` next to the original export.

## Build a debug APK

```bash
npm run build:debug
```

Or prepare + build in one step:

```bash
npm run build:debug -- /absolute/path/to/ll-tools-offline-app.zip
```

The script creates the Android project on first run with `npx cap add android`, syncs the web assets, and builds a debug APK.

## Windows batch shortcut

From the plugin root on Windows, you can run:

```bat
build-offline-app-apk.bat
```

The batch script:

- prompts for the offline app export zip path
- accepts pasted paths with or without surrounding quotes
- installs `offline-app-builder` dependencies on first run
- builds a debug APK
- copies the generated APK next to the selected zip as `<app-name>-<version>.apk`

You can also drag a zip file onto `build-offline-app-apk.bat` or pass the zip path as the first argument.

## Build a signed release APK

Set these environment variables first:

```bash
export LL_OFFLINE_KEYSTORE_PATH=/absolute/path/to/keystore.jks
export LL_OFFLINE_KEYSTORE_PASSWORD=...
export LL_OFFLINE_KEY_ALIAS=...
export LL_OFFLINE_KEY_ALIAS_PASSWORD=...
```

Then run:

```bash
npm run build:release -- /absolute/path/to/ll-tools-offline-app.zip
```

The signing configuration is written to the ignored `capacitor.config.json`
for the release command and rewritten without signing secrets in its `finally`
handler. Keep that file private during the build. Successful debug and release
commands report their respective output directories under
`android/app/build/outputs/apk/`; the Windows batch shortcut also copies the APK
next to the input archive.

## Focused verification

From this directory, run `npm test` (`npm.cmd test` in PowerShell when needed)
for the Node archive/icon/configuration checks in
`tests/builder-hardening.test.mjs`. Android PCM unit coverage is in
`android-overrides/app/src/test/java/com/lltools/offline/offline/quiz/stt/PcmAudioUtilsTest.java`;
it needs a prepared Android project and toolchain.

WordPress-side coverage lives outside the builder: `OfflineAppExportTest` and
`OfflineAppSyncTest` under `../tests/Integration/`, plus
`offline-app-export-job-progress.spec.js`,
`wordset-offline-export-job-progress.spec.js`,
`offline-app-shell-launcher.spec.js`, and `offline-app-sync-error-wp.spec.js`
under `../tests/e2e/specs/`. Follow `../tests/AI_TESTING_PLAYBOOK.md` before
running the WordPress or browser tests.

## Notes

- The exported web app is the source of truth for the APK build. Rebuild and reinstall the APK for content updates.
- Native Android overrides for the offline STT bridge live under `offline-app-builder/android-overrides/` and are copied into the generated Capacitor Android project during the build.
- The builder keeps generated files out of git via `.gitignore`.
- If you prefer Android Studio for release signing, run `npm run open:android` after preparing the bundle.
- The offline app now carries the `Study` and `Games` views from the export bundle. `Speaking Practice` is only shown offline when the export includes a packaged STT bundle for that wordset.
- Android offline STT now uses a bundled native `whisper.cpp` runtime exposed through `Capacitor.Plugins.LLToolsOfflineStt`.
- Offline STT input is capped at 15 seconds. The web shell rejects oversized blobs before PCM encoding, and the Android bridge, model session, and JNI layer enforce matching byte/sample ceilings before inference.
- The STT bundle must be a mobile-ready `whisper.cpp` bundle. A desktop Python training checkpoint by itself is not enough for Android inference.
- The simplest supported bundle is either:
  - a single `.bin` or `.gguf` Whisper model file, or
  - a directory with a `manifest.json` like:

```json
{
  "engine": "whisper.cpp",
  "modelPath": "model.bin",
  "language": "auto",
  "task": "transcribe"
}
```

- The runtime currently expects 16kHz mono PCM from the offline web app and resolves the model from the exported `embedded_model` metadata.
