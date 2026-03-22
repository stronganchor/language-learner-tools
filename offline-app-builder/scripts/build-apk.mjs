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

function run(cmd, args, options = {}) {
  execFileSync(cmd, args, {
    cwd: ROOT_DIR,
    stdio: 'inherit',
    shell: process.platform === 'win32' && /\.(cmd|bat)$/i.test(cmd),
    ...options
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
    ? path.join('android', 'gradlew.bat')
    : path.join('android', 'gradlew');
  run(gradle, ['assembleDebug']);
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
