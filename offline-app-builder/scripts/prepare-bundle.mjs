import fs from 'fs-extra';
import path from 'node:path';
import process from 'node:process';
import AdmZip from 'adm-zip';
import { fileURLToPath } from 'node:url';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const WORKSPACE_DIR = path.join(ROOT_DIR, 'workspace');
const BUNDLE_DIR = path.join(WORKSPACE_DIR, 'bundle');
const STATE_PATH = path.join(WORKSPACE_DIR, 'bundle-state.json');
const CAPACITOR_CONFIG_PATH = path.join(ROOT_DIR, 'capacitor.config.json');

function sanitizeSegment(value, fallback = 'app') {
  const clean = String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9_.]+/g, '')
    .replace(/\.+/g, '.')
    .replace(/^\.|\.$/g, '');
  if (!clean) {
    return fallback;
  }
  return clean
    .split('.')
    .filter(Boolean)
    .map((segment) => {
      const trimmed = segment.replace(/[^a-z0-9_]+/g, '');
      if (!trimmed) {
        return fallback;
      }
      return /^[a-z_]/.test(trimmed) ? trimmed : `app${trimmed}`;
    })
    .join('.');
}

function readManifest(bundleRoot) {
  const manifestPath = path.join(bundleRoot, 'bundle-manifest.json');
  if (!fs.existsSync(manifestPath)) {
    throw new Error(`Missing bundle-manifest.json in ${bundleRoot}`);
  }
  return fs.readJsonSync(manifestPath);
}

function writeCapacitorConfig(manifest) {
  const appId = sanitizeSegment(manifest?.android?.appId, 'com.lltools.offline.app');
  const appName = String(manifest?.app?.name || 'LL Tools Offline Quiz');
  const config = {
    appId,
    appName,
    webDir: 'workspace/bundle/www',
    bundledWebRuntime: false,
    android: {
      buildOptions: {
        releaseType: 'APK'
      }
    }
  };

  const keystorePath = process.env.LL_OFFLINE_KEYSTORE_PATH || '';
  const keystorePassword = process.env.LL_OFFLINE_KEYSTORE_PASSWORD || '';
  const keystoreAlias = process.env.LL_OFFLINE_KEY_ALIAS || '';
  const keystoreAliasPassword = process.env.LL_OFFLINE_KEY_ALIAS_PASSWORD || '';
  if (keystorePath && keystorePassword && keystoreAlias && keystoreAliasPassword) {
    config.android.buildOptions = {
      ...config.android.buildOptions,
      keystorePath,
      keystorePassword,
      keystoreAlias,
      keystoreAliasPassword,
      releaseType: 'APK'
    };
  }

  fs.writeJsonSync(CAPACITOR_CONFIG_PATH, config, { spaces: 2 });
}

export function prepareBundle(inputPath) {
  if (!inputPath) {
    throw new Error('Provide a path to an LL Tools offline app bundle zip or extracted bundle directory.');
  }

  const resolvedInput = path.resolve(process.cwd(), inputPath);
  if (!fs.existsSync(resolvedInput)) {
    throw new Error(`Bundle input not found: ${resolvedInput}`);
  }

  fs.removeSync(BUNDLE_DIR);
  fs.ensureDirSync(WORKSPACE_DIR);

  if (fs.statSync(resolvedInput).isDirectory()) {
    fs.copySync(resolvedInput, BUNDLE_DIR);
  } else {
    const zip = new AdmZip(resolvedInput);
    zip.extractAllTo(BUNDLE_DIR, true);
  }

  const manifest = readManifest(BUNDLE_DIR);
  const webRoot = path.join(BUNDLE_DIR, 'www');
  if (!fs.existsSync(path.join(webRoot, 'index.html'))) {
    throw new Error(`Prepared bundle does not contain www/index.html: ${webRoot}`);
  }

  writeCapacitorConfig(manifest);
  const state = {
    preparedAt: new Date().toISOString(),
    bundleRoot: BUNDLE_DIR,
    webRoot,
    manifest
  };
  fs.writeJsonSync(STATE_PATH, state, { spaces: 2 });
  return state;
}

if (path.resolve(process.argv[1] || '') === SCRIPT_PATH) {
  try {
    const state = prepareBundle(process.argv[2]);
    process.stdout.write(`Prepared offline bundle in ${state.bundleRoot}\n`);
    process.stdout.write(`Capacitor config written to ${CAPACITOR_CONFIG_PATH}\n`);
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exit(1);
  }
}
