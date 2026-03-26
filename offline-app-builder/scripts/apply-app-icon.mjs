import fs from 'fs-extra';
import path from 'node:path';
import sharp from 'sharp';
import { fileURLToPath } from 'node:url';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const ANDROID_RES_DIR = path.join(ROOT_DIR, 'android', 'app', 'src', 'main', 'res');
const LEGACY_ICON_SIZES = {
  mdpi: 48,
  hdpi: 72,
  xhdpi: 96,
  xxhdpi: 144,
  xxxhdpi: 192
};
const ADAPTIVE_FOREGROUND_SIZES = {
  mdpi: 108,
  hdpi: 162,
  xhdpi: 216,
  xxhdpi: 324,
  xxxhdpi: 432
};
const ADAPTIVE_FOREGROUND_SCALE = 0.6666667;

function resolvePreparedIcon(state) {
  const bundleRoot = String(state?.bundleRoot || '');
  const bundlePath = String(state?.manifest?.app?.icon?.bundlePath || '').replace(/^[/\\]+/, '');

  if (!bundleRoot || !bundlePath) {
    return null;
  }

  const absolutePath = path.join(bundleRoot, bundlePath);
  if (!fs.existsSync(absolutePath)) {
    return null;
  }

  return absolutePath;
}

async function renderSquareIcon(sourcePath, size, destinationPath) {
  await fs.ensureDir(path.dirname(destinationPath));
  await sharp(sourcePath)
    .resize(size, size, {
      fit: 'cover',
      position: 'centre'
    })
    .png()
    .toFile(destinationPath);
}

async function renderAdaptiveForegroundIcon(sourcePath, size, destinationPath) {
  const innerSize = Math.max(1, Math.round(size * ADAPTIVE_FOREGROUND_SCALE));
  const foregroundBuffer = await sharp(sourcePath)
    .resize(innerSize, innerSize, {
      fit: 'cover',
      position: 'centre'
    })
    .png()
    .toBuffer();

  await fs.ensureDir(path.dirname(destinationPath));
  await sharp({
    create: {
      width: size,
      height: size,
      channels: 4,
      background: { r: 0, g: 0, b: 0, alpha: 0 }
    }
  })
    .composite([{ input: foregroundBuffer, gravity: 'centre' }])
    .png()
    .toFile(destinationPath);
}

export async function applyBundledAppIcon(state) {
  const iconPath = resolvePreparedIcon(state);
  if (!iconPath) {
    return { applied: false, reason: 'no-icon' };
  }

  if (!fs.existsSync(ANDROID_RES_DIR)) {
    return { applied: false, reason: 'android-missing', iconPath };
  }

  for (const [density, size] of Object.entries(LEGACY_ICON_SIZES)) {
    const dir = path.join(ANDROID_RES_DIR, `mipmap-${density}`);
    await renderSquareIcon(iconPath, size, path.join(dir, 'ic_launcher.png'));
    await renderSquareIcon(iconPath, size, path.join(dir, 'ic_launcher_round.png'));
  }

  for (const [density, size] of Object.entries(ADAPTIVE_FOREGROUND_SIZES)) {
    const dir = path.join(ANDROID_RES_DIR, `mipmap-${density}`);
    await renderAdaptiveForegroundIcon(iconPath, size, path.join(dir, 'ic_launcher_foreground.png'));
  }

  return { applied: true, iconPath };
}
