import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import AdmZip from 'adm-zip';

// CVE-2026-76845 / GHSA-vwc7-r8mq-g2x9: lexical path validation
// does not stop an existing destination symlink from redirecting a write.
const extractors = {
  extractAllTo: (zip, root) => zip.extractAllTo(root, true),
  extractEntryTo: (zip, root) => zip.extractEntryTo('nested/sentinel.txt', root, true, true),
  extractAllToAsync: (zip, root) => new Promise((resolve, reject) => {
    zip.extractAllToAsync(root, true, false, (error) => error ? reject(error) : resolve());
  }),
};

function archive() {
  const zip = new AdmZip();
  zip.addFile('nested/sentinel.txt', Buffer.from('replacement'));
  return new AdmZip(zip.toBuffer());
}

for (const [name, extract] of Object.entries(extractors)) {
  test(`${name} rejects destination links without modifying their target`, async () => {
    const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-zip-security-'));
    const destination = path.join(tempRoot, 'destination');
    const outside = path.join(tempRoot, 'outside');
    const link = path.join(destination, 'nested');
    const sentinel = path.join(outside, 'sentinel.txt');
    try {
      fs.mkdirSync(destination);
      fs.mkdirSync(outside);
      fs.writeFileSync(sentinel, 'original', { mode: 0o600 });
      const originalMode = fs.statSync(sentinel).mode;
      // A Windows directory junction exercises the same lstat-based guard
      // without requiring the privileged file-symlink permission.
      fs.symlinkSync(outside, link, process.platform === 'win32' ? 'junction' : 'dir');
      assert.equal(fs.lstatSync(link).isSymbolicLink(), true);
      await assert.rejects(async () => extract(archive(), destination), /file in the way/i);
      assert.equal(fs.readFileSync(sentinel, 'utf8'), 'original');
      assert.equal(fs.statSync(sentinel).mode, originalMode);
      assert.deepEqual(fs.readdirSync(outside), ['sentinel.txt']);
    } finally {
      fs.rmSync(tempRoot, { recursive: true, force: true });
    }
  });

  test(`${name} still extracts ordinary nested files`, async () => {
    const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-zip-security-'));
    try {
      await extract(archive(), tempRoot);
      assert.equal(fs.readFileSync(path.join(tempRoot, 'nested', 'sentinel.txt'), 'utf8'), 'replacement');
    } finally {
      fs.rmSync(tempRoot, { recursive: true, force: true });
    }
  });
}
