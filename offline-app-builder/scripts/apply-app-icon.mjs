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
const ICON_INPUT_OPTIONS = { limitInputPixels: MAX_ICON_PIXELS, failOn: 'error' };
const PNG_SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

// Icons never need SVG decoding. Keep its native loader disabled as defense in
// depth, including for metadata reads (GHSA-wq5f-xc86-pv6w).
sharp.block({ operation: ['VipsForeignLoadSvg'] });

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

function readRasterIconInput(iconPath) {
  const descriptor = fs.openSync(iconPath, 'r');
  try {
    const stats = fs.fstatSync(descriptor);
    if (!stats.isFile() || stats.size > MAX_ICON_BYTES) {
      throw new Error(`The app icon must be a file within the ${MAX_ICON_BYTES}-byte limit.`);
    }

    // Read one bounded snapshot for validation and every render. A concurrent
    // file replacement must not swap validated raster bytes for another format.
    const input = Buffer.alloc(stats.size + 1);
    let length = 0;
    while (length < input.length) {
      const count = fs.readSync(descriptor, input, length, input.length - length, null);
      if (count === 0) break;
      length += count;
    }
    if (length !== stats.size) {
      throw new Error('The app icon changed while it was being read. Retry the build.');
    }
    const bytes = input.subarray(0, length);
    const png = bytes.length >= 8 && bytes.subarray(0, 8).equals(PNG_SIGNATURE);
    const jpeg = bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff;
    const webp = bytes.length >= 12 && bytes.subarray(0, 4).equals(Buffer.from('RIFF')) && bytes.subarray(8, 12).equals(Buffer.from('WEBP'));
    if (!png && !jpeg && !webp) {
      throw new Error('The app icon contents must be PNG, JPEG, or WebP.');
    }
    return bytes;
  } finally {
    fs.closeSync(descriptor);
  }
}

async function renderSquareIcon(sourceInput, size, destinationPath) {
  await fs.ensureDir(path.dirname(destinationPath));
  await sharp(sourceInput, ICON_INPUT_OPTIONS)
    .resize(size, size, {
      fit: 'cover',
      position: 'centre'
    })
    .png()
    .toFile(destinationPath);
}

async function renderAdaptiveForegroundIcon(sourceInput, size, destinationPath) {
  const innerSize = Math.max(1, Math.round(size * ADAPTIVE_FOREGROUND_SCALE));
  const foregroundBuffer = await sharp(sourceInput, ICON_INPUT_OPTIONS)
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

export async function applyBundledAppIcon(state, options = {}) {
  const iconPath = resolvePreparedIcon(state);
  if (!iconPath) {
    return { applied: false, reason: 'no-icon' };
  }

  const androidResDir = options.androidResDir || ANDROID_RES_DIR;
  if (!fs.existsSync(androidResDir)) {
    return { applied: false, reason: 'android-missing', iconPath };
  }

  const iconInput = readRasterIconInput(iconPath);
  const metadata = await sharp(iconInput, ICON_INPUT_OPTIONS).metadata();
  if (!ALLOWED_ICON_FORMATS.has(String(metadata.format || ''))) {
    throw new Error('The app icon contents must be PNG, JPEG, or WebP.');
  }

  for (const [density, size] of Object.entries(LEGACY_ICON_SIZES)) {
    const dir = path.join(androidResDir, `mipmap-${density}`);
    await renderSquareIcon(iconInput, size, path.join(dir, 'ic_launcher.png'));
    await renderSquareIcon(iconInput, size, path.join(dir, 'ic_launcher_round.png'));
  }

  for (const [density, size] of Object.entries(ADAPTIVE_FOREGROUND_SIZES)) {
    const dir = path.join(androidResDir, `mipmap-${density}`);
    await renderAdaptiveForegroundIcon(iconInput, size, path.join(dir, 'ic_launcher_foreground.png'));
  }

  return { applied: true, iconPath };
}
