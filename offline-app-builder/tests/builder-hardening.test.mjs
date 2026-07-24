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
