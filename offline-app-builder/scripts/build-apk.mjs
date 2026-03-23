import fs from 'fs-extra';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { prepareBundle } from './prepare-bundle.mjs';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const WORKSPACE_DIR = path.join(ROOT_DIR, 'workspace');
const STATE_PATH = path.join(WORKSPACE_DIR, 'bundle-state.json');
const ANDROID_DIR = path.join(ROOT_DIR, 'android');

function commandName(base) {
  return process.platform === 'win32' ? `${base}.cmd` : base;
}

function existingDir(candidatePath) {
  return candidatePath && fs.existsSync(candidatePath) ? candidatePath : '';
}

function detectJavaHome() {
  if (process.env.JAVA_HOME && existingDir(process.env.JAVA_HOME)) {
    return process.env.JAVA_HOME;
  }

  const programFiles = process.env.ProgramFiles || 'C:\\Program Files';
  const candidates = [
    path.join(programFiles, 'Android', 'Android Studio', 'jbr'),
    path.join(programFiles, 'Android', 'Android Studio', 'jre')
  ];

  for (const candidate of candidates) {
    if (existingDir(candidate) && fs.existsSync(path.join(candidate, 'bin', 'java.exe'))) {
      return candidate;
    }
  }

  return '';
}

function detectAndroidSdkRoot() {
  const existingAndroidHome = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT || '';
  if (existingAndroidHome && existingDir(existingAndroidHome)) {
    return existingAndroidHome;
  }

  const localAppData = process.env.LOCALAPPDATA || path.join(process.env.USERPROFILE || '', 'AppData', 'Local');
  const candidate = path.join(localAppData, 'Android', 'Sdk');
  if (existingDir(candidate)) {
    return candidate;
  }

  return '';
}

function buildCommandEnv(extraEnv = {}) {
  const env = {
    ...process.env,
    ...extraEnv
  };

  if (process.platform === 'win32') {
    const javaHome = detectJavaHome();
    if (javaHome && !env.JAVA_HOME) {
      env.JAVA_HOME = javaHome;
    }

    const androidSdkRoot = detectAndroidSdkRoot();
    if (androidSdkRoot) {
      if (!env.ANDROID_HOME) {
        env.ANDROID_HOME = androidSdkRoot;
      }
      if (!env.ANDROID_SDK_ROOT) {
        env.ANDROID_SDK_ROOT = androidSdkRoot;
      }
    }

    const pathEntries = [];
    if (env.JAVA_HOME) {
      pathEntries.push(path.join(env.JAVA_HOME, 'bin'));
    }
    if (env.ANDROID_HOME) {
      pathEntries.push(path.join(env.ANDROID_HOME, 'platform-tools'));
    }
    if (pathEntries.length) {
      const currentPath = env.Path || env.PATH || '';
      env.Path = `${pathEntries.join(';')};${currentPath}`;
      env.PATH = env.Path;
    }
  }

  return env;
}

function run(cmd, args, options = {}) {
  const { env: extraEnv, ...rest } = options;
  execFileSync(cmd, args, {
    cwd: ROOT_DIR,
    stdio: 'inherit',
    shell: process.platform === 'win32' && /\.(cmd|bat)$/i.test(cmd),
    env: buildCommandEnv(extraEnv),
    ...rest
  });
}

function loadPreparedState() {
  if (!fs.existsSync(STATE_PATH)) {
    throw new Error('No prepared bundle found. Run `npm run prepare:bundle -- /path/to/bundle.zip` first or pass the bundle path to this command.');
  }
  return fs.readJsonSync(STATE_PATH);
}

function ensureAndroidPlatform() {
  if (fs.existsSync(ANDROID_DIR)) {
    return;
  }
  run(commandName('npx'), ['cap', 'add', 'android']);
}

function ensureBundlePrepared(explicitInput) {
  if (explicitInput) {
    return prepareBundle(explicitInput);
  }
  return loadPreparedState();
}

function getSigningEnvReady() {
  return !!(
    process.env.LL_OFFLINE_KEYSTORE_PATH &&
    process.env.LL_OFFLINE_KEYSTORE_PASSWORD &&
    process.env.LL_OFFLINE_KEY_ALIAS &&
    process.env.LL_OFFLINE_KEY_ALIAS_PASSWORD
  );
}

function printOutputHints(mode) {
  const outputDir = mode === 'release'
    ? path.join(ANDROID_DIR, 'app', 'build', 'outputs', 'apk', 'release')
    : path.join(ANDROID_DIR, 'app', 'build', 'outputs', 'apk', 'debug');
  process.stdout.write(`APK output directory: ${outputDir}\n`);
}

function buildDebug() {
  run(commandName('npx'), ['cap', 'sync', 'android']);
  const gradle = process.platform === 'win32'
    ? path.join(ANDROID_DIR, 'gradlew.bat')
    : path.join(ANDROID_DIR, 'gradlew');
  run(gradle, ['assembleDebug'], { cwd: ANDROID_DIR });
  printOutputHints('debug');
}

function buildRelease() {
  if (!getSigningEnvReady()) {
    throw new Error(
      'Release build requires LL_OFFLINE_KEYSTORE_PATH, LL_OFFLINE_KEYSTORE_PASSWORD, LL_OFFLINE_KEY_ALIAS, and LL_OFFLINE_KEY_ALIAS_PASSWORD.'
    );
  }

  run(commandName('npx'), ['cap', 'build', 'android', '--androidreleasetype', 'APK']);
  printOutputHints('release');
}

function main() {
  const args = process.argv.slice(2);
  const mode = args.includes('--release') ? 'release' : 'debug';
  const explicitInput = args.find((arg) => !arg.startsWith('--')) || '';

  ensureBundlePrepared(explicitInput);
  ensureAndroidPlatform();

  if (mode === 'release') {
    buildRelease();
  } else {
    buildDebug();
  }
}

if (path.resolve(process.argv[1] || '') === SCRIPT_PATH) {
  try {
    main();
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exit(1);
  }
}
