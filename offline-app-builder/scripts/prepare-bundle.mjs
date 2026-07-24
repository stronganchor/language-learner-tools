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
const DEFAULT_ARCHIVE_LIMITS = Object.freeze({
  maxEntries: 20000,
  maxEntryBytes: 2 * 1024 * 1024 * 1024,
  maxTotalBytes: 4 * 1024 * 1024 * 1024,
});

function repairOfflineShellIndexHtml(webRoot) {
  const indexPath = path.join(webRoot, 'index.html');
  if (!fs.existsSync(indexPath)) {
    return;
  }

  const original = fs.readFileSync(indexPath, 'utf8');
  const repaired = original
    .replace(/(src=|href=)"https?:\/\/\.\//g, '$1"./')
    .replace(/(src=|href=)'https?:\/\/\.\//g, "$1'./");

  if (repaired !== original) {
    fs.writeFileSync(indexPath, repaired, 'utf8');
  }
}

function normalizeInputPath(inputPath) {
  const raw = String(inputPath || '');
  if (!raw) {
    return raw;
  }

  if (process.platform === 'win32') {
    const wslMatch = raw.match(/^\/mnt\/([a-z])\/(.*)$/i);
    if (!wslMatch) {
      return raw;
    }

    const driveLetter = wslMatch[1].toUpperCase();
    const relativePath = wslMatch[2].replace(/\//g, '\\');
    return `${driveLetter}:\\${relativePath}`;
  }

  const windowsMatch = raw.match(/^([a-z]):[\\/](.*)$/i);
  if (!windowsMatch) {
    return raw;
  }

  const driveLetter = windowsMatch[1].toLowerCase();
  const relativePath = windowsMatch[2].replace(/\\/g, '/');
  return `/mnt/${driveLetter}/${relativePath}`;
}

export function sanitizeSegment(value, fallback = 'app') {
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

function positiveLimit(value, fallback) {
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function archiveEntryIsSymlink(entry) {
  const attributes = Number(entry?.attr ?? entry?.header?.attr ?? 0);
  const unixMode = (attributes >>> 16) & 0o170000;
  return unixMode === 0o120000;
}

export function validateArchiveEntries(zip, destinationRoot, limits = {}) {
  const destination = path.resolve(destinationRoot);
  const maxEntries = positiveLimit(limits.maxEntries, DEFAULT_ARCHIVE_LIMITS.maxEntries);
  const maxEntryBytes = positiveLimit(limits.maxEntryBytes, DEFAULT_ARCHIVE_LIMITS.maxEntryBytes);
  const maxTotalBytes = positiveLimit(limits.maxTotalBytes, DEFAULT_ARCHIVE_LIMITS.maxTotalBytes);
  const entries = zip.getEntries();

  if (entries.length > maxEntries) {
    throw new Error(`Bundle archive contains ${entries.length} entries; the limit is ${maxEntries}.`);
  }

  let totalBytes = 0;
  for (const entry of entries) {
    const entryName = String(entry?.entryName || '').replace(/\\/g, '/');
    const segments = entryName.split('/');
    if (
      entryName.includes('\0')
      || entryName.startsWith('/')
      || /^[a-z]:\//i.test(entryName)
      || segments.includes('..')
    ) {
      throw new Error(`Bundle archive entry escapes the destination: ${entryName || '(empty)'}`);
    }
    if (archiveEntryIsSymlink(entry)) {
      throw new Error(`Bundle archive contains an unsupported symbolic link: ${entryName}`);
    }

    const resolvedEntry = path.resolve(destination, ...segments.filter(Boolean));
    if (resolvedEntry !== destination && !resolvedEntry.startsWith(`${destination}${path.sep}`)) {
      throw new Error(`Bundle archive entry escapes the destination: ${entryName || '(empty)'}`);
    }

    const entryBytes = Number(entry?.header?.size ?? 0);
    if (!Number.isSafeInteger(entryBytes) || entryBytes < 0) {
      throw new Error(`Bundle archive entry has an invalid size: ${entryName}`);
    }
    if (entryBytes > maxEntryBytes) {
      throw new Error(`Bundle archive entry exceeds the ${maxEntryBytes}-byte limit: ${entryName}`);
    }
    totalBytes += entryBytes;
    if (!Number.isSafeInteger(totalBytes) || totalBytes > maxTotalBytes) {
      throw new Error(`Bundle archive exceeds the ${maxTotalBytes}-byte uncompressed limit.`);
    }
  }

  return {
    entries: entries.length,
    totalBytes,
  };
}

export function buildCapacitorConfig(manifest, options = {}) {
  const includeSigning = options.includeSigning === true;
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
  if (includeSigning && keystorePath && keystorePassword && keystoreAlias && keystoreAliasPassword) {
    config.android.buildOptions = {
      ...config.android.buildOptions,
      keystorePath,
      keystorePassword,
      keystoreAlias,
      keystoreAliasPassword,
      releaseType: 'APK'
    };
  }

  return config;
}

export function writeCapacitorConfig(manifest, options = {}) {
  const config = buildCapacitorConfig(manifest, options);
  fs.writeJsonSync(CAPACITOR_CONFIG_PATH, config, { spaces: 2 });
}

export function prepareBundle(inputPath) {
  if (!inputPath) {
    throw new Error('Provide a path to an LL Tools offline app bundle zip or extracted bundle directory.');
  }

  const normalizedInput = normalizeInputPath(inputPath);
  const resolvedInput = path.isAbsolute(normalizedInput)
    ? normalizedInput
    : path.resolve(process.cwd(), normalizedInput);
  if (!fs.existsSync(resolvedInput)) {
    throw new Error(`Bundle input not found: ${resolvedInput}`);
  }

  fs.removeSync(BUNDLE_DIR);
  fs.ensureDirSync(WORKSPACE_DIR);

  if (fs.statSync(resolvedInput).isDirectory()) {
    fs.copySync(resolvedInput, BUNDLE_DIR);
  } else {
    const zip = new AdmZip(resolvedInput);
    validateArchiveEntries(zip, BUNDLE_DIR);
    zip.extractAllTo(BUNDLE_DIR, true);
  }

  const manifest = readManifest(BUNDLE_DIR);
  const webRoot = path.join(BUNDLE_DIR, 'www');
  if (!fs.existsSync(path.join(webRoot, 'index.html'))) {
    throw new Error(`Prepared bundle does not contain www/index.html: ${webRoot}`);
  }

  repairOfflineShellIndexHtml(webRoot);
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
