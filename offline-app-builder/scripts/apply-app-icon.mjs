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
const MAX_ICON_BYTES = 10 * 1024 * 1024;
const MAX_ICON_PIXELS = 64 * 1024 * 1024;
const ALLOWED_ICON_EXTENSIONS = new Set(['.jpg', '.jpeg', '.png', '.webp']);
const ALLOWED_ICON_FORMATS = new Set(['jpeg', 'png', 'webp']);

function isPathInside(rootPath, candidatePath) {
  const relative = path.relative(rootPath, candidatePath);
  return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
}

export function resolvePreparedIcon(state) {
  const bundleRoot = String(state?.bundleRoot || '');
  const bundlePath = String(state?.manifest?.app?.icon?.bundlePath || '');

  if (!bundleRoot || !bundlePath) {
    return null;
  }

  if (path.isAbsolute(bundlePath)) {
    throw new Error('The app icon path must be relative to the prepared bundle.');
  }

  const realBundleRoot = fs.realpathSync(bundleRoot);
  const absolutePath = path.resolve(realBundleRoot, bundlePath);
  if (!isPathInside(realBundleRoot, absolutePath)) {
    throw new Error('The app icon path escapes the prepared bundle.');
  }
  if (!fs.existsSync(absolutePath)) {
    return null;
  }

  const realIconPath = fs.realpathSync(absolutePath);
  if (!isPathInside(realBundleRoot, realIconPath)) {
    throw new Error('The app icon resolves outside the prepared bundle.');
  }

  const stats = fs.statSync(realIconPath);
  if (!stats.isFile()) {
    throw new Error('The app icon path must reference a file.');
  }
  if (stats.size > MAX_ICON_BYTES) {
    throw new Error(`The app icon exceeds the ${MAX_ICON_BYTES}-byte limit.`);
  }
  if (!ALLOWED_ICON_EXTENSIONS.has(path.extname(realIconPath).toLowerCase())) {
    throw new Error('The app icon must be a PNG, JPEG, or WebP file.');
  }

  return realIconPath;
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

  const metadata = await sharp(iconPath, { limitInputPixels: MAX_ICON_PIXELS, failOn: 'error' }).metadata();
  if (!ALLOWED_ICON_FORMATS.has(String(metadata.format || ''))) {
    throw new Error('The app icon contents must be PNG, JPEG, or WebP.');
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
