# Offline App Builder

This folder turns an `LL Offline App Export` bundle into an Android APK.

## Prerequisites

- Node.js
- Android Studio + Android SDK
- Java/Gradle tooling required by Capacitor

## Install

```bash
cd offline-app-builder
npm install
```

## Prepare a bundle

```bash
npm run prepare:bundle -- /absolute/path/to/ll-tools-offline-app.zip
```

This extracts the bundle into `workspace/bundle/` and writes `capacitor.config.json`.
On WSL, `/mnt/c/...` and `C:\...` bundle paths are both supported.
If the bundle includes an app icon, the build scripts use it for the Android launcher icon automatically.
If the bundle includes a wordset-specific offline STT bundle, it is kept under `workspace/bundle/www/content/stt-models/...` and packaged into the APK with the rest of the web assets.

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

## Notes

- The exported web app is the source of truth for the APK build. Rebuild and reinstall the APK for content updates.
- The builder keeps generated files out of git via `.gitignore`.
- If you prefer Android Studio for release signing, run `npm run open:android` after preparing the bundle.
- The offline app now carries the `Study` and `Games` views from the export bundle. `Speaking Practice` is only shown offline when the export includes a packaged STT bundle for that wordset.
- The STT bundle must be a mobile-ready runtime bundle. A desktop Python training checkpoint by itself is not enough for Android inference.
- The current offline web runtime expects an Android bridge exposing one of `Capacitor.Plugins.LLToolsOfflineStt.transcribe`, `transcribeSpeakingAttempt`, or `transcribeBase64`, plus optional `isEmbeddedSttAvailable`.
- Until that Android inference bridge is present in the app build, the packaged model files are bundled correctly but the offline speaking game will stay unavailable on-device.
