import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const DEFAULT_STATE_PATH = path.join(ROOT_DIR, 'workspace', 'bundle-state.json');

function slugifySegment(value, fallback) {
  const normalized = String(value || '')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');

  return normalized || fallback;
}

export function getSuggestedApkBaseName(manifest) {
  const appName = slugifySegment(manifest?.app?.name, 'offline-app');
  const versionName = slugifySegment(manifest?.app?.versionName, '');

  return versionName !== ''
    ? `${appName}-${versionName}`
    : appName;
}

function loadManifest(statePath) {
  if (!fs.existsSync(statePath)) {
    throw new Error(`Prepared bundle state not found: ${statePath}`);
  }

  const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
  if (!state || typeof state !== 'object' || !state.manifest || typeof state.manifest !== 'object') {
    throw new Error(`Prepared bundle state is missing manifest metadata: ${statePath}`);
  }

  return state.manifest;
}

function main() {
  const statePath = process.argv[2]
    ? path.resolve(process.cwd(), process.argv[2])
    : DEFAULT_STATE_PATH;

  const manifest = loadManifest(statePath);
  process.stdout.write(`${getSuggestedApkBaseName(manifest)}\n`);
}

if (path.resolve(process.argv[1] || '') === SCRIPT_PATH) {
  try {
    main();
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exit(1);
  }
}
