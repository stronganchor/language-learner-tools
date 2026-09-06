import fs from 'fs-extra';
import path from 'node:path';
import process from 'node:process';
import AdmZip from 'adm-zip';
import { fileURLToPath } from 'node:url';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const CAPACITOR_CONFIG_PATH = path.join(ROOT_DIR, 'capacitor.config.json');
export const DEFAULT_ARCHIVE_LIMITS = Object.freeze({
  maxArchiveBytes: 2 * 1024 * 1024 * 1024,
  maxEntries: 20000,
  maxEntryBytes: 2 * 1024 * 1024 * 1024,
  maxTotalBytes: 4 * 1024 * 1024 * 1024,
});
const HARD_MAX_ARCHIVE_BYTES = 4 * 1024 * 1024 * 1024;

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
  const manifest = fs.readJsonSync(manifestPath);
  if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
    throw new Error(`Invalid bundle-manifest.json in ${bundleRoot}`);
  }
  return manifest;
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

export function validateArchiveFileSize(archivePath, limits = {}) {
  const maxArchiveBytes = Math.min(
    positiveLimit(limits.maxArchiveBytes, DEFAULT_ARCHIVE_LIMITS.maxArchiveBytes),
    HARD_MAX_ARCHIVE_BYTES
  );
  const stats = fs.statSync(archivePath);
  if (!stats.isFile() || !Number.isSafeInteger(stats.size) || stats.size < 0) {
    throw new Error(`Bundle archive has an invalid compressed size: ${archivePath}`);
  }
  if (stats.size > maxArchiveBytes) {
    throw new Error(`Bundle archive exceeds the ${maxArchiveBytes}-byte compressed-file limit.`);
  }

  return {
    archiveBytes: stats.size,
    maxArchiveBytes,
  };
}

export function openValidatedArchive(archivePath, limits = {}, createArchive = (input) => new AdmZip(input)) {
  validateArchiveFileSize(archivePath, limits);
  return createArchive(archivePath);
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

function pathContains(parent, candidate) {
  const normalize = (value) => process.platform === 'win32' ? value.toLowerCase() : value;
  const base = normalize(path.resolve(parent));
  const target = normalize(path.resolve(candidate));
  return target === base || target.startsWith(`${base}${path.sep}`);
}

function canonicalPath(candidate) {
  if (fs.existsSync(candidate)) {
    return fs.realpathSync(candidate);
  }
  return path.join(canonicalPath(path.dirname(candidate)), path.basename(candidate));
}

// Publish only fully prepared replacements. Keep the previous three outputs
// until every rename succeeds, and restore them together if publication fails.
function publishPreparedBundle(stageDir, replacements) {
  const moved = [];
  try {
    for (const [index, { source, target }] of replacements.entries()) {
      const backup = path.join(stageDir, `previous-${index}`);
      const entry = { source, target, backup, backedUp: false, installed: false };
      moved.push(entry);
      if (fs.existsSync(target)) {
        fs.renameSync(target, backup);
        entry.backedUp = true;
      }
      fs.renameSync(source, target);
      entry.installed = true;
    }
  } catch (error) {
    let rollbackError = null;
    for (const entry of moved.reverse()) {
      try {
        if (entry.installed) {
          fs.removeSync(entry.target);
        }
        if (entry.backedUp) {
          fs.renameSync(entry.backup, entry.target);
        }
      } catch (restoreError) {
        rollbackError = restoreError;
      }
    }
    if (rollbackError) {
      // Retain backups if the filesystem also refuses restoration.
      error.preserveStage = true;
      error.message += ` Previous preparation backups remain in ${stageDir}: ${rollbackError.message}`;
    }
    throw error;
  }
}

export function prepareBundle(inputPath, options = {}) {
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

  const inputStats = fs.statSync(resolvedInput);
  // A separate root also lets callers prepare independent builder workspaces.
  const rootDir = path.resolve(options.rootDir || ROOT_DIR);
  const workspaceDir = path.join(rootDir, 'workspace');
  const bundleDir = path.join(workspaceDir, 'bundle');
  const statePath = path.join(workspaceDir, 'bundle-state.json');
  const configPath = path.join(rootDir, 'capacitor.config.json');
  const inputReal = canonicalPath(resolvedInput);
  const bundleReal = canonicalPath(bundleDir);
  const workspaceReal = canonicalPath(workspaceDir);
  if (pathContains(bundleReal, inputReal)
      || (inputStats.isDirectory() && pathContains(inputReal, workspaceReal))) {
    throw new Error('Keep the source bundle outside the prepared bundle and its workspace ancestors.');
  }
  const archiveLimits = options?.archiveLimits && typeof options.archiveLimits === 'object'
    ? options.archiveLimits
    : {};
  let zip = null;
  if (!inputStats.isDirectory()) {
    // Validate the compressed input before AdmZip can read it into memory and
    // before replacing a previously prepared workspace.
    zip = openValidatedArchive(resolvedInput, archiveLimits);
  }

  fs.ensureDirSync(workspaceDir);
  const stageDir = fs.mkdtempSync(path.join(workspaceDir, '.prepare-'));
  const stagedBundle = path.join(stageDir, 'bundle');
  let preserveStage = false;
  try {
    if (inputStats.isDirectory()) {
      fs.copySync(resolvedInput, stagedBundle, {
        filter(source) {
          // Otherwise a copied www junction or index.html symlink could let
          // shell repair modify the previous bundle outside this staged copy.
          if (fs.lstatSync(source).isSymbolicLink()) {
            throw new Error(`Extracted bundles cannot contain symbolic links or junctions: ${source}`);
          }
          return true;
        },
      });
    } else {
      validateArchiveEntries(zip, stagedBundle, archiveLimits);
      zip.extractAllTo(stagedBundle, true);
    }

    const manifest = readManifest(stagedBundle);
    const stagedWebRoot = path.join(stagedBundle, 'www');
    if (!fs.existsSync(path.join(stagedWebRoot, 'index.html'))
        || !fs.statSync(path.join(stagedWebRoot, 'index.html')).isFile()) {
      throw new Error(`Prepared bundle does not contain www/index.html: ${stagedWebRoot}`);
    }
    repairOfflineShellIndexHtml(stagedWebRoot);
    const state = {
      preparedAt: new Date().toISOString(),
      bundleRoot: bundleDir,
      webRoot: path.join(bundleDir, 'www'),
      manifest
    };
    const stagedConfig = path.join(stageDir, 'capacitor.config.json');
    const stagedState = path.join(stageDir, 'bundle-state.json');
    fs.writeJsonSync(stagedConfig, buildCapacitorConfig(manifest), { spaces: 2 });
    fs.writeJsonSync(stagedState, state, { spaces: 2 });
    publishPreparedBundle(stageDir, [
      { source: stagedBundle, target: bundleDir },
      { source: stagedConfig, target: configPath },
      { source: stagedState, target: statePath },
    ]);
    return state;
  } catch (error) {
    preserveStage = error.preserveStage === true;
    throw error;
  } finally {
    if (!preserveStage) {
      try {
        fs.removeSync(stageDir);
      } catch (_) {
        // Cleanup is outside publication: a valid committed preparation must
        // not be reported as failed just because an old scratch file is busy.
        process.stderr.write(`Could not remove temporary preparation directory: ${stageDir}\n`);
      }
    }
  }
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
