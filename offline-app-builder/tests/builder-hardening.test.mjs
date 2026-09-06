import assert from 'node:assert/strict';
import fs from 'fs-extra';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import AdmZip from 'adm-zip';
import {
  buildCapacitorConfig,
  openValidatedArchive,
  validateArchiveEntries,
  validateArchiveFileSize,
  prepareBundle,
} from '../scripts/prepare-bundle.mjs';
import { resolveAndroidPackageConfig } from '../scripts/build-apk.mjs';
import { resolvePreparedIcon } from '../scripts/apply-app-icon.mjs';
import { readTrainingBundleData } from '../scripts/inject-stt-bundle.mjs';

test('persistent Capacitor config excludes release signing secrets', () => {
  const previous = {
    path: process.env.LL_OFFLINE_KEYSTORE_PATH,
    password: process.env.LL_OFFLINE_KEYSTORE_PASSWORD,
    alias: process.env.LL_OFFLINE_KEY_ALIAS,
    aliasPassword: process.env.LL_OFFLINE_KEY_ALIAS_PASSWORD
  };
  process.env.LL_OFFLINE_KEYSTORE_PATH = '/private/release.keystore';
  process.env.LL_OFFLINE_KEYSTORE_PASSWORD = 'secret-one';
  process.env.LL_OFFLINE_KEY_ALIAS = 'release';
  process.env.LL_OFFLINE_KEY_ALIAS_PASSWORD = 'secret-two';

  try {
    const persistent = buildCapacitorConfig({ android: { appId: 'org.example.quiz' } });
    assert.deepEqual(persistent.android.buildOptions, { releaseType: 'APK' });

    const transient = buildCapacitorConfig(
      { android: { appId: 'org.example.quiz' } },
      { includeSigning: true }
    );
    assert.equal(transient.android.buildOptions.keystorePassword, 'secret-one');
    assert.equal(transient.android.buildOptions.keystoreAliasPassword, 'secret-two');
  } finally {
    for (const [key, value] of Object.entries({
      LL_OFFLINE_KEYSTORE_PATH: previous.path,
      LL_OFFLINE_KEYSTORE_PASSWORD: previous.password,
      LL_OFFLINE_KEY_ALIAS: previous.alias,
      LL_OFFLINE_KEY_ALIAS_PASSWORD: previous.aliasPassword
    })) {
      if (value === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = value;
      }
    }
  }
});

test('Android package identity and version come from the bundle manifest', () => {
  assert.deepEqual(resolveAndroidPackageConfig({
    android: { appId: 'org.example.zaza' },
    app: { versionCode: 42, versionName: '6.8.1' }
  }), {
    applicationId: 'org.example.zaza',
    versionCode: 42,
    versionName: '6.8.1'
  });
});

test('prepared icon resolver rejects paths outside the bundle', () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-icon-'));
  const bundleRoot = path.join(tempRoot, 'bundle');
  fs.ensureDirSync(bundleRoot);
  fs.writeFileSync(path.join(tempRoot, 'outside.png'), 'not-an-image');

  try {
    assert.throws(() => resolvePreparedIcon({
      bundleRoot,
      manifest: { app: { icon: { bundlePath: '../outside.png' } } }
    }), /escapes the prepared bundle/);
  } finally {
    fs.removeSync(tempRoot);
  }
});

test('prepared icon resolver enforces type and size limits', () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-icon-'));
  const bundleRoot = path.join(tempRoot, 'bundle');
  fs.ensureDirSync(bundleRoot);
  fs.writeFileSync(path.join(bundleRoot, 'icon.txt'), 'not-an-image');
  const largeIcon = path.join(bundleRoot, 'large.png');
  fs.closeSync(fs.openSync(largeIcon, 'w'));
  fs.truncateSync(largeIcon, (10 * 1024 * 1024) + 1);

  try {
    assert.throws(() => resolvePreparedIcon({
      bundleRoot,
      manifest: { app: { icon: { bundlePath: 'icon.txt' } } }
    }), /must be a PNG, JPEG, or WebP/);
    assert.throws(() => resolvePreparedIcon({
      bundleRoot,
      manifest: { app: { icon: { bundlePath: 'large.png' } } }
    }), /exceeds the .* limit/);
  } finally {
    fs.removeSync(tempRoot);
  }
});

test('bundle archive validation rejects traversal and absolute entry paths', () => {
  const destination = path.join(os.tmpdir(), 'll-tools-bundle-destination');
  const traversal = {
    getEntries: () => [{
      entryName: '../outside.txt',
      attr: 0,
      header: { size: 6 },
    }],
  };
  assert.throws(
    () => validateArchiveEntries(traversal, destination),
    /escapes the destination/
  );

  const absolute = {
    getEntries: () => [{
      entryName: 'C:/outside.txt',
      attr: 0,
      header: { size: 6 },
    }],
  };
  assert.throws(
    () => validateArchiveEntries(absolute, destination),
    /escapes the destination/
  );
});

test('bundle archive validation enforces entry count and uncompressed size limits', () => {
  const destination = path.join(os.tmpdir(), 'll-tools-bundle-destination');
  const zip = new AdmZip();
  zip.addFile('bundle-manifest.json', Buffer.from('{}'));
  zip.addFile('www/index.html', Buffer.from('<!doctype html>'));

  assert.throws(
    () => validateArchiveEntries(zip, destination, { maxEntries: 1 }),
    /contains 2 entries/
  );
  assert.throws(
    () => validateArchiveEntries(zip, destination, { maxEntryBytes: 4 }),
    /entry exceeds/
  );
  assert.throws(
    () => validateArchiveEntries(zip, destination, { maxTotalBytes: 8 }),
    /uncompressed limit/
  );
});

test('bundle archive validation rejects oversized compressed input before opening it', () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-archive-'));
  const archivePath = path.join(tempRoot, 'bundle.zip');
  fs.writeFileSync(archivePath, Buffer.alloc(32, 1));
  let opened = false;

  try {
    assert.throws(
      () => openValidatedArchive(
        archivePath,
        { maxArchiveBytes: 16 },
        () => {
          opened = true;
          return {};
        }
      ),
      /compressed-file limit/
    );
    assert.equal(opened, false);
    assert.deepEqual(
      validateArchiveFileSize(archivePath, { maxArchiveBytes: 64 }),
      {
        archiveBytes: 32,
        maxArchiveBytes: 64,
      }
    );
  } finally {
    fs.removeSync(tempRoot);
  }
});

test('training data reader rejects oversized data.json before parsing', () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-training-'));
  const archivePath = path.join(tempRoot, 'training.zip');
  const zip = new AdmZip();
  zip.addFile('data.json', Buffer.from(JSON.stringify({ words: [{ title: 'example' }] })));
  zip.writeZip(archivePath);

  try {
    assert.throws(
      () => readTrainingBundleData(archivePath, { maxBytes: 8 }),
      /data\.json exceeds the 8-byte limit/
    );
    assert.deepEqual(
      readTrainingBundleData(archivePath, { maxBytes: 1024 }),
      { words: [{ title: 'example' }] }
    );
  } finally {
    fs.removeSync(tempRoot);
  }
});

function preparationFixture() {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-prepare-'));
  const rootDir = path.join(tempRoot, 'builder');
  const input = path.join(tempRoot, 'source');
  fs.ensureDirSync(path.join(input, 'www'));
  fs.writeJsonSync(path.join(input, 'bundle-manifest.json'), { app: { name: 'Previous app' } });
  fs.writeFileSync(path.join(input, 'www', 'index.html'), '<!doctype html><title>Previous app</title>');
  prepareBundle(input, { rootDir });
  const outputs = [
    path.join(rootDir, 'workspace', 'bundle', 'www', 'index.html'),
    path.join(rootDir, 'workspace', 'bundle', 'bundle-manifest.json'),
    path.join(rootDir, 'capacitor.config.json'),
    path.join(rootDir, 'workspace', 'bundle-state.json'),
  ];
  const before = outputs.map((output) => fs.readFileSync(output, 'utf8'));
  return {
    tempRoot, rootDir, input,
    assertPreserved() {
      assert.deepEqual(outputs.map((output) => fs.readFileSync(output, 'utf8')), before);
      assert.deepEqual(fs.readdirSync(path.join(rootDir, 'workspace')).sort(), ['bundle', 'bundle-state.json']);
    },
  };
}

test('invalid archive entries and manifest failures preserve all prepared outputs', () => {
  const fixture = preparationFixture();
  try {
    const zipPath = path.join(fixture.tempRoot, 'replacement.zip');
    const zip = new AdmZip();
    zip.addFile('bundle-manifest.json', Buffer.from('{}'));
    zip.addFile('www/index.html', Buffer.from('<title>Replacement</title>'));
    zip.writeZip(zipPath);
    assert.throws(() => prepareBundle(zipPath, { rootDir: fixture.rootDir, archiveLimits: { maxEntries: 1 } }), /limit/);
    fixture.assertPreserved();
    for (const manifest of ['broken JSON', 'null', '[]']) {
      fs.writeFileSync(path.join(fixture.input, 'bundle-manifest.json'), manifest);
      assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }));
      fixture.assertPreserved();
    }
    fs.removeSync(path.join(fixture.input, 'bundle-manifest.json'));
    assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }), /Missing bundle-manifest/);
    fixture.assertPreserved();
    fs.writeJsonSync(path.join(fixture.input, 'bundle-manifest.json'), {});
    fs.removeSync(path.join(fixture.input, 'www', 'index.html'));
    assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }), /www.index.html/);
    fixture.assertPreserved();
  } finally {
    fs.removeSync(fixture.tempRoot);
  }
});

test('publication and staging failures restore bundle, config and state together', (t) => {
  const fixture = preparationFixture();
  const rename = fs.renameSync;
  try {
    fs.writeJsonSync(path.join(fixture.input, 'bundle-manifest.json'), { app: { name: 'Replacement' } });
    fs.writeFileSync(path.join(fixture.input, 'www', 'index.html'), '<title>Replacement</title>');
    for (const failingTarget of ['capacitor.config.json', 'bundle-state.json']) {
      const mocked = t.mock.method(fs, 'renameSync', (source, target) => {
        if (path.basename(source) === failingTarget && path.basename(target) === failingTarget) {
          throw new Error('Injected publication failure');
        }
        return rename(source, target);
      });
      assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }), /Injected publication failure/);
      mocked.mock.restore();
      fixture.assertPreserved();
    }
    const mocked = t.mock.method(fs, 'copySync', () => { throw new Error('Injected copy failure'); });
    assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }), /Injected copy failure/);
    mocked.mock.restore();
    fixture.assertPreserved();
  } finally {
    t.mock.restoreAll();
    fs.removeSync(fixture.tempRoot);
  }
});

test('preparation rejects source-inside-destination and ancestor inputs without deleting either', () => {
  const fixture = preparationFixture();
  try {
    const bundle = path.join(fixture.rootDir, 'workspace', 'bundle');
    for (const input of [bundle, path.join(bundle, 'www'), path.join(bundle, 'www', 'index.html'), fixture.rootDir]) {
      assert.throws(() => prepareBundle(input, { rootDir: fixture.rootDir }), /Keep the source bundle outside/);
      fixture.assertPreserved();
    }
  } finally {
    fs.removeSync(fixture.tempRoot);
  }
});

test('successful replacement publishes matching bundle, state and secret-free configuration', () => {
  const fixture = preparationFixture();
  try {
    const zipPath = path.join(fixture.tempRoot, 'replacement.zip');
    const zip = new AdmZip();
    zip.addFile('bundle-manifest.json', Buffer.from(JSON.stringify({ app: { name: 'Replacement' } })));
    zip.addFile('www/index.html', Buffer.from('<title>Replacement</title>'));
    zip.writeZip(zipPath);
    const state = prepareBundle(zipPath, { rootDir: fixture.rootDir });
    assert.equal(fs.readFileSync(path.join(state.webRoot, 'index.html'), 'utf8'), '<title>Replacement</title>');
    assert.deepEqual(fs.readJsonSync(path.join(fixture.rootDir, 'workspace', 'bundle-state.json')), state);
    assert.equal(fs.readJsonSync(path.join(fixture.rootDir, 'capacitor.config.json')).appName, 'Replacement');
    assert.deepEqual(fs.readdirSync(path.join(fixture.rootDir, 'workspace')).sort(), ['bundle', 'bundle-state.json']);
    assert.equal(fs.existsSync(zipPath), true);
  } finally {
    fs.removeSync(fixture.tempRoot);
  }
});

test('a directory junction back into the prior bundle cannot escape staging or modify prior assets', () => {
  const fixture = preparationFixture();
  try {
    const originalWebRoot = path.join(fixture.rootDir, 'workspace', 'bundle', 'www');
    const sourceWebRoot = path.join(fixture.input, 'www');
    fs.removeSync(sourceWebRoot);
    fs.symlinkSync(originalWebRoot, sourceWebRoot, process.platform === 'win32' ? 'junction' : 'dir');
    assert.throws(() => prepareBundle(fixture.input, { rootDir: fixture.rootDir }), /symbolic links or junctions/);
    fixture.assertPreserved();
    assert.equal(fs.lstatSync(sourceWebRoot).isSymbolicLink(), true);
  } finally {
    fs.removeSync(fixture.tempRoot);
  }
});
