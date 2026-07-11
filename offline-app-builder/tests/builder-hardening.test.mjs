import assert from 'node:assert/strict';
import fs from 'fs-extra';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { buildCapacitorConfig } from '../scripts/prepare-bundle.mjs';
import { resolveAndroidPackageConfig } from '../scripts/build-apk.mjs';
import { resolvePreparedIcon } from '../scripts/apply-app-icon.mjs';

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
