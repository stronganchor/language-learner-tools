import fs from 'fs-extra';
import path from 'node:path';
import process from 'node:process';
import AdmZip from 'adm-zip';
import { fileURLToPath } from 'node:url';
import { prepareBundle } from './prepare-bundle.mjs';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const ROOT_DIR = path.resolve(path.dirname(SCRIPT_PATH), '..');
const PLUGIN_ROOT_DIR = path.resolve(ROOT_DIR, '..');
const WORKSPACE_DIR = path.join(ROOT_DIR, 'workspace');
const BUNDLE_DIR = path.join(WORKSPACE_DIR, 'bundle');
const STATE_PATH = path.join(WORKSPACE_DIR, 'bundle-state.json');

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

function resolveExistingPath(inputPath) {
  const normalizedInput = normalizeInputPath(inputPath);
  const resolvedInput = path.isAbsolute(normalizedInput)
    ? normalizedInput
    : path.resolve(process.cwd(), normalizedInput);
  if (!fs.existsSync(resolvedInput)) {
    throw new Error(`Path not found: ${resolvedInput}`);
  }
  return resolvedInput;
}

function parseArgs(argv) {
  const args = {
    bundle: '',
    sttSource: '',
    ipaZipsDir: '',
    wordsetSlug: '',
    outputZip: '',
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--bundle') {
      args.bundle = argv[index + 1] || '';
      index += 1;
      continue;
    }
    if (arg === '--stt-source') {
      args.sttSource = argv[index + 1] || '';
      index += 1;
      continue;
    }
    if (arg === '--ipa-zips-dir') {
      args.ipaZipsDir = argv[index + 1] || '';
      index += 1;
      continue;
    }
    if (arg === '--wordset-slug') {
      args.wordsetSlug = argv[index + 1] || '';
      index += 1;
      continue;
    }
    if (arg === '--output-zip') {
      args.outputZip = argv[index + 1] || '';
      index += 1;
      continue;
    }
    if (!arg.startsWith('--') && !args.bundle) {
      args.bundle = arg;
    }
  }

  if (!args.bundle) {
    throw new Error('Provide --bundle /path/to/ll-tools-offline-app.zip');
  }
  if (!args.sttSource) {
    throw new Error('Provide --stt-source /path/to/mobile-ready-stt-bundle');
  }

  return args;
}

function readJsonFile(filePath) {
  return fs.readJsonSync(filePath);
}

function writeJsonFile(filePath, value) {
  fs.writeJsonSync(filePath, value, { spaces: 2 });
}

function normalizeWhitespace(value) {
  return String(value || '')
    .normalize('NFC')
    .trim()
    .replace(/\s+/gu, ' ');
}

function normalizeLookupText(value) {
  return normalizeWhitespace(value).toLowerCase();
}

function slugifyLoose(value) {
  const replacements = new Map([
    ['ı', 'i'],
    ['İ', 'i'],
    ['ş', 's'],
    ['Ş', 's'],
    ['ğ', 'g'],
    ['Ğ', 'g'],
    ['ç', 'c'],
    ['Ç', 'c'],
    ['ö', 'o'],
    ['Ö', 'o'],
    ['ü', 'u'],
    ['Ü', 'u'],
    ['â', 'a'],
    ['Â', 'a'],
    ['ê', 'e'],
    ['Ê', 'e'],
    ['î', 'i'],
    ['Î', 'i'],
    ['û', 'u'],
    ['Û', 'u'],
    ['’', ''],
    ["'", ''],
  ]);

  let text = normalizeLookupText(value);
  for (const [needle, replacement] of replacements.entries()) {
    text = text.split(needle).join(replacement);
  }

  return text
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

function parseOfflineData(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  const match = raw.match(/^window\.llToolsOfflineData\s*=\s*(.*);\s*$/s);
  if (!match) {
    throw new Error(`Could not parse offline data payload: ${filePath}`);
  }

  return JSON.parse(match[1]);
}

function writeOfflineData(filePath, data) {
  const payload = `window.llToolsOfflineData = ${JSON.stringify(data)};\n`;
  fs.writeFileSync(filePath, payload, 'utf8');
}

function normalizeRelativeBundlePath(value) {
  return String(value || '')
    .replace(/\\/g, '/')
    .split('/')
    .filter((segment) => segment && segment !== '.' && segment !== '..')
    .join('/');
}

function normalizeSttEngine(value) {
  const engine = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[ _]+/g, '.');

  if (['whisper.cpp', 'whispercpp', 'ggml', 'gguf'].includes(engine)) {
    return 'whisper.cpp';
  }
  if (engine === 'onnx') {
    return 'onnx';
  }
  if (engine === 'tflite') {
    return 'tflite';
  }

  return engine;
}

function guessModelFileFromDirectory(sourcePath) {
  const entries = fs.readdirSync(sourcePath)
    .filter((entry) => fs.statSync(path.join(sourcePath, entry)).isFile())
    .filter((entry) => ['.bin', '.gguf'].includes(path.extname(entry).toLowerCase()))
    .sort((left, right) => left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' }));

  return entries.length ? normalizeRelativeBundlePath(entries[0]) : '';
}

function ensureLocalBundleManifest(sourcePath) {
  if (!fs.statSync(sourcePath).isDirectory()) {
    return;
  }

  const manifestPath = path.join(sourcePath, 'manifest.json');
  if (fs.existsSync(manifestPath)) {
    return;
  }

  const modelPath = guessModelFileFromDirectory(sourcePath);
  if (!modelPath) {
    throw new Error(`Could not infer a .bin or .gguf model file in ${sourcePath}`);
  }

  writeJsonFile(manifestPath, {
    engine: 'whisper.cpp',
    modelPath,
    language: 'auto',
    task: 'transcribe',
  });
}

function buildRuntimeManifest(sourcePath, isDirectory) {
  const runtime = {
    engine: '',
    task: 'transcribe',
    language: 'auto',
    modelPath: '',
    manifestPath: '',
    androidSupported: false,
  };

  if (isDirectory) {
    const manifestPath = path.join(sourcePath, 'manifest.json');
    if (fs.existsSync(manifestPath)) {
      const manifest = readJsonFile(manifestPath);
      runtime.manifestPath = 'manifest.json';
      runtime.engine = normalizeSttEngine(
        manifest.engine
        || manifest.runtime
        || manifest.backend
        || manifest.format
        || ''
      );
      runtime.task = ['translate'].includes(String(manifest.task || manifest.mode || '').trim())
        ? 'translate'
        : 'transcribe';
      runtime.language = String(manifest.language || 'auto').trim() || 'auto';
      runtime.modelPath = normalizeRelativeBundlePath(
        manifest.modelPath
        || manifest.model_path
        || manifest.model
        || manifest.modelFile
        || manifest.model_file
        || manifest.file
        || ''
      );
    }

    if (!runtime.modelPath) {
      runtime.modelPath = guessModelFileFromDirectory(sourcePath);
    }
  } else {
    runtime.modelPath = normalizeRelativeBundlePath(path.basename(sourcePath));
  }

  if (!runtime.engine && runtime.modelPath) {
    const extension = path.extname(runtime.modelPath).toLowerCase();
    if (['.bin', '.gguf'].includes(extension)) {
      runtime.engine = 'whisper.cpp';
    }
  }

  runtime.androidSupported = runtime.engine === 'whisper.cpp' && !!runtime.modelPath;
  return runtime;
}

function buildBundleManifest(bundleManifest, sourcePath, wordsetSlugOverride = '') {
  const wordset = bundleManifest.wordset || {};
  const wordsetId = Number(wordset.id || 0);
  const wordsetSlug = normalizeRelativeBundlePath(wordsetSlugOverride || wordset.slug || '');
  if (!wordsetSlug) {
    throw new Error('Could not infer wordset slug from bundle-manifest.json or --wordset-slug');
  }

  const sourceName = path.basename(sourcePath);
  const relativePath = `content/stt-models/${wordsetSlug}/${sourceName}`;
  const isDirectory = fs.statSync(sourcePath).isDirectory();
  const runtime = buildRuntimeManifest(sourcePath, isDirectory);
  const manifest = {
    wordsetId,
    wordsetSlug,
    sourceName,
    entryType: isDirectory ? 'directory' : 'file',
    bundlePath: `www/${relativePath}`,
    webPath: `./${relativePath}`,
    androidAssetPath: `public/${relativePath}`,
    androidSupported: !!runtime.androidSupported,
    engine: runtime.engine || '',
    task: runtime.task || 'transcribe',
    language: runtime.language || 'auto',
  };

  if (runtime.manifestPath) {
    const manifestRelativePath = `${relativePath}/${runtime.manifestPath}`;
    manifest.manifestPath = `www/${manifestRelativePath}`;
    manifest.webManifestPath = `./${manifestRelativePath}`;
    manifest.androidAssetManifestPath = `public/${manifestRelativePath}`;
  }

  if (runtime.modelPath) {
    const modelRelativePath = isDirectory
      ? `${relativePath}/${runtime.modelPath}`
      : relativePath;
    manifest.modelPath = runtime.modelPath;
    manifest.modelBundlePath = `www/${modelRelativePath}`;
    manifest.webModelPath = `./${modelRelativePath}`;
    manifest.androidAssetModelPath = `public/${modelRelativePath}`;
  }

  return {
    manifest,
    relativePath,
    isDirectory,
  };
}

function copySttBundleIntoPreparedBundle(sourcePath, relativePath) {
  const destination = path.join(BUNDLE_DIR, 'www', relativePath);
  fs.removeSync(destination);
  fs.ensureDirSync(path.dirname(destination));

  if (fs.statSync(sourcePath).isDirectory()) {
    fs.copySync(sourcePath, destination, {
      overwrite: true,
      errorOnExist: false,
    });
  } else {
    fs.copyFileSync(sourcePath, destination);
  }
}

function readTrainingBundleData(zipFilePath) {
  const zip = new AdmZip(zipFilePath);
  const entry = zip.getEntry('data.json');
  if (!entry) {
    return null;
  }
  return JSON.parse(zip.readAsText(entry, 'utf8'));
}

function buildIpaLookup(ipaZipsDir) {
  const zipFiles = fs.readdirSync(ipaZipsDir)
    .filter((entry) => entry.toLowerCase().endsWith('.zip'))
    .sort((left, right) => left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' }));

  const byCategoryAndTitle = new Map();
  const byCategoryAndTranslation = new Map();

  zipFiles.forEach((fileName) => {
    const bundleData = readTrainingBundleData(path.join(ipaZipsDir, fileName));
    if (!bundleData || !Array.isArray(bundleData.words)) {
      return;
    }

    bundleData.words.forEach((word) => {
      const categorySlugs = Array.isArray(word.categories) ? word.categories.map(slugifyLoose).filter(Boolean) : [];
      if (!categorySlugs.length || !Array.isArray(word.audio_entries)) {
        return;
      }

      const isolationEntry = word.audio_entries.find((entry) => {
        const recordingTypes = Array.isArray(entry && entry.recording_types) ? entry.recording_types : [];
        return recordingTypes.some((recordingType) => slugifyLoose(recordingType) === 'isolation');
      });
      if (!isolationEntry || typeof isolationEntry !== 'object') {
        return;
      }

      const meta = isolationEntry.meta && typeof isolationEntry.meta === 'object' ? isolationEntry.meta : {};
      const ipa = normalizeWhitespace(Array.isArray(meta.recording_ipa) ? meta.recording_ipa[0] : '');
      const recordingText = normalizeLookupText(Array.isArray(meta.recording_text) ? meta.recording_text[0] : '');
      const recordingTranslation = normalizeLookupText(
        (Array.isArray(meta.recording_translation) ? meta.recording_translation[0] : '')
        || ((word.meta && Array.isArray(word.meta.word_english_meaning)) ? word.meta.word_english_meaning[0] : '')
      );
      if (!ipa) {
        return;
      }

      categorySlugs.forEach((categorySlug) => {
        if (recordingText) {
          byCategoryAndTitle.set(`${categorySlug}::${recordingText}`, ipa);
        }
        if (recordingTranslation) {
          byCategoryAndTranslation.set(`${categorySlug}::${recordingTranslation}`, ipa);
        }
      });
    });
  });

  return {
    byCategoryAndTitle,
    byCategoryAndTranslation,
  };
}

function getSpeakingPromptText(word) {
  if (!String(word.image || '').trim()) {
    const translation = normalizeWhitespace(word.translation || '');
    if (translation) {
      return translation;
    }
  }

  return normalizeWhitespace(
    word.prompt_label
    || word.label
    || word.title
    || ''
  );
}

function findIsolationAudio(word) {
  const audioFiles = Array.isArray(word.audio_files) ? word.audio_files : [];
  return audioFiles.find((entry) => slugifyLoose(entry && entry.recording_type) === 'isolation') || null;
}

function buildSpeakingWords(offlineData, ipaLookup) {
  const categories = Array.isArray(offlineData.flashcards && offlineData.flashcards.categories)
    ? offlineData.flashcards.categories
    : [];
  const categoryMap = new Map();
  categories.forEach((category) => {
    if (!category || typeof category !== 'object') {
      return;
    }
    categoryMap.set(normalizeLookupText(category.name), {
      id: Number(category.id || 0),
      slug: String(category.slug || ''),
      name: String(category.name || ''),
    });
  });

  const rowsByCategory = offlineData.flashcards && offlineData.flashcards.offlineCategoryData
    && typeof offlineData.flashcards.offlineCategoryData === 'object'
    ? offlineData.flashcards.offlineCategoryData
    : {};

  const wordsById = new Map();

  Object.values(rowsByCategory).forEach((rows) => {
    if (!Array.isArray(rows)) {
      return;
    }

    rows.forEach((row) => {
      if (!row || typeof row !== 'object') {
        return;
      }

      const isolationAudio = findIsolationAudio(row);
      const isolationAudioUrl = normalizeWhitespace(
        (isolationAudio && isolationAudio.url)
        || ''
      );
      if (!isolationAudioUrl) {
        return;
      }

      const categoryRefs = Array.isArray(row.all_categories) ? row.all_categories : [];
      const categoryDetails = categoryRefs.map((name) => categoryMap.get(normalizeLookupText(name))).filter(Boolean);
      const categorySlugs = categoryDetails.map((detail) => detail.slug).filter(Boolean);
      if (!categorySlugs.length) {
        return;
      }

      const titleKey = normalizeLookupText(row.title || '');
      const translationKey = normalizeLookupText(row.translation || '');
      let ipa = '';
      for (const categorySlug of categorySlugs) {
        if (!ipa && titleKey) {
          ipa = ipaLookup.byCategoryAndTitle.get(`${categorySlug}::${titleKey}`) || '';
        }
        if (!ipa && translationKey) {
          ipa = ipaLookup.byCategoryAndTranslation.get(`${categorySlug}::${translationKey}`) || '';
        }
        if (ipa) {
          break;
        }
      }

      if (!ipa) {
        return;
      }

      const wordId = Number(row.id || 0) || `${titleKey}::${translationKey}::${categorySlugs.join('|')}`;
      const existing = wordsById.get(wordId);
      const baseWord = existing ? { ...existing } : { ...row };
      const categoryIds = new Set([...(Array.isArray(existing && existing.category_ids) ? existing.category_ids : [])]);
      categoryDetails.forEach((detail) => {
        if (Number(detail.id || 0) > 0) {
          categoryIds.add(Number(detail.id));
        }
      });

      baseWord.category_ids = Array.from(categoryIds.values());
      baseWord.speaking_target_field = 'recording_ipa';
      baseWord.speaking_target_label = 'IPA';
      baseWord.speaking_target_text = ipa;
      baseWord.speaking_prompt_text = getSpeakingPromptText(row);
      baseWord.speaking_prompt_type = normalizeWhitespace(row.image || '') ? 'image' : 'text';
      baseWord.speaking_display_texts = {
        title: normalizeWhitespace(row.title || ''),
        ipa,
        target_text: ipa,
        target_field: 'recording_ipa',
        target_label: 'IPA',
      };
      baseWord.speaking_best_correct_audio_url = isolationAudioUrl;

      wordsById.set(wordId, baseWord);
    });
  });

  return Array.from(wordsById.values());
}

function buildSpeakingCatalogEntry(offlineData, speakingWords, embeddedModelManifest) {
  const minimumWordCount = Number(offlineData.games && offlineData.games.minimumWordCount) || 5;
  const launchWordCap = Math.max(
    minimumWordCount,
    Number(offlineData.games && offlineData.games.speakingPractice && offlineData.games.speakingPractice.maxLoadedWords) || 60
  );
  const categoryIds = Array.from(new Set(speakingWords.flatMap((word) => Array.isArray(word.category_ids) ? word.category_ids : [])));

  return {
    slug: 'speaking-practice',
    title: 'Speaking Practice',
    description: 'Say the word aloud. Compare what you said to the target text.',
    minimum_word_count: minimumWordCount,
    available_word_count: speakingWords.length,
    launch_word_cap: launchWordCap,
    launch_word_count: Math.min(speakingWords.length, launchWordCap),
    launchable: speakingWords.length >= minimumWordCount,
    reason_code: speakingWords.length >= minimumWordCount ? '' : 'not_enough_words',
    category_ids: categoryIds,
    words: speakingWords.slice(0, launchWordCap),
    target_field: 'recording_ipa',
    target_label: 'IPA',
    provider: 'embedded_model',
    provider_label: 'Bundled offline model',
    local_endpoint: '',
    embedded_model: embeddedModelManifest,
    offline_stt: embeddedModelManifest,
  };
}

function updatePreparedBundle(bundleManifest, offlineData, embeddedModelManifest, speakingWords) {
  const speakingEntry = buildSpeakingCatalogEntry(offlineData, speakingWords, embeddedModelManifest);
  if (!offlineData.games || typeof offlineData.games !== 'object') {
    throw new Error('Offline data payload does not include games configuration.');
  }

  offlineData.games.enabled = true;
  offlineData.games.runtimeMode = 'offline';
  if (!offlineData.games.catalog || typeof offlineData.games.catalog !== 'object') {
    offlineData.games.catalog = {};
  }
  offlineData.games.catalog['speaking-practice'] = speakingEntry;
  if (!offlineData.games.offlineBridge || typeof offlineData.games.offlineBridge !== 'object') {
    offlineData.games.offlineBridge = {};
  }
  offlineData.games.offlineBridge.usesEmbeddedModel = true;

  if (!offlineData.app || typeof offlineData.app !== 'object') {
    offlineData.app = {};
  }
  if (!offlineData.app.speechToText || typeof offlineData.app.speechToText !== 'object') {
    offlineData.app.speechToText = { bundles: [] };
  }
  offlineData.app.speechToText.bundles = [embeddedModelManifest];

  if (!bundleManifest.speechToText || typeof bundleManifest.speechToText !== 'object') {
    bundleManifest.speechToText = { bundles: [] };
  }
  bundleManifest.speechToText.bundles = [embeddedModelManifest];
}

function addDirectoryToZip(zip, sourceDir, zipRoot = '') {
  const entries = fs.readdirSync(sourceDir);
  entries.forEach((entry) => {
    const sourcePath = path.join(sourceDir, entry);
    const stats = fs.statSync(sourcePath);
    const zipPath = path.posix.join(zipRoot, entry);
    if (stats.isDirectory()) {
      addDirectoryToZip(zip, sourcePath, zipPath);
    } else if (stats.isFile()) {
      zip.addLocalFile(sourcePath, path.posix.dirname(zipPath) === '.' ? '' : path.posix.dirname(zipPath), path.posix.basename(zipPath));
    }
  });
}

function writeBundleZip(outputZipPath) {
  const zip = new AdmZip();
  addDirectoryToZip(zip, BUNDLE_DIR, '');
  fs.ensureDirSync(path.dirname(outputZipPath));
  zip.writeZip(outputZipPath);
}

function defaultOutputZip(bundleInputPath) {
  const parsed = path.parse(bundleInputPath);
  return path.join(parsed.dir, `${parsed.name}-with-stt.zip`);
}

function syncPluginAssetIntoPreparedBundle(sourceRelativePath, bundleRelativePath) {
  const sourcePath = path.join(PLUGIN_ROOT_DIR, sourceRelativePath);
  const targetPath = path.join(BUNDLE_DIR, bundleRelativePath);
  if (!fs.existsSync(sourcePath)) {
    return;
  }

  fs.ensureDirSync(path.dirname(targetPath));
  fs.copyFileSync(sourcePath, targetPath);
}

function syncCurrentPluginAssetsIntoPreparedBundle() {
  syncPluginAssetIntoPreparedBundle(path.join('js', 'wordset-games.js'), path.join('www', 'plugin', 'js', 'wordset-games.js'));
  syncPluginAssetIntoPreparedBundle(path.join('css', 'wordset-games.css'), path.join('www', 'plugin', 'css', 'wordset-games.css'));
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const bundleInputPath = resolveExistingPath(args.bundle);
  const sttSourcePath = resolveExistingPath(args.sttSource);
  const ipaZipsDir = resolveExistingPath(args.ipaZipsDir || path.resolve(path.dirname(sttSourcePath), '..', 'zip files from zazacaogren'));

  if (!fs.statSync(ipaZipsDir).isDirectory()) {
    throw new Error(`IPA zip directory is not a directory: ${ipaZipsDir}`);
  }

  prepareBundle(bundleInputPath);
  ensureLocalBundleManifest(sttSourcePath);

  const bundleManifestPath = path.join(BUNDLE_DIR, 'bundle-manifest.json');
  const offlineDataPath = path.join(BUNDLE_DIR, 'www', 'data', 'offline-data.js');
  const bundleManifest = readJsonFile(bundleManifestPath);
  const offlineData = parseOfflineData(offlineDataPath);

  const { manifest: embeddedModelManifest, relativePath } = buildBundleManifest(bundleManifest, sttSourcePath, args.wordsetSlug);
  copySttBundleIntoPreparedBundle(sttSourcePath, relativePath);

  const ipaLookup = buildIpaLookup(ipaZipsDir);
  const speakingWords = buildSpeakingWords(offlineData, ipaLookup);
  if (speakingWords.length < 5) {
    throw new Error(`Only ${speakingWords.length} speaking words could be matched to IPA targets. Need at least 5.`);
  }

  updatePreparedBundle(bundleManifest, offlineData, embeddedModelManifest, speakingWords);
  writeJsonFile(bundleManifestPath, bundleManifest);
  writeOfflineData(offlineDataPath, offlineData);
  syncCurrentPluginAssetsIntoPreparedBundle();

  const state = {
    preparedAt: new Date().toISOString(),
    bundleRoot: BUNDLE_DIR,
    webRoot: path.join(BUNDLE_DIR, 'www'),
    manifest: bundleManifest,
  };
  writeJsonFile(STATE_PATH, state);

  const outputZipPath = (() => {
    if (!args.outputZip) {
      return defaultOutputZip(bundleInputPath);
    }

    const normalizedOutput = normalizeInputPath(args.outputZip);
    const resolvedOutput = path.isAbsolute(normalizedOutput)
      ? normalizedOutput
      : path.resolve(process.cwd(), normalizedOutput);
    const outputDir = path.dirname(resolvedOutput);
    if (!fs.existsSync(outputDir)) {
      throw new Error(`Output directory not found: ${outputDir}`);
    }

    return resolvedOutput;
  })();
  writeBundleZip(outputZipPath);

  process.stdout.write(`Injected STT bundle into prepared app: ${BUNDLE_DIR}\n`);
  process.stdout.write(`Speaking words matched to IPA: ${speakingWords.length}\n`);
  process.stdout.write(`Output zip: ${outputZipPath}\n`);
}

if (path.resolve(process.argv[1] || '') === SCRIPT_PATH) {
  try {
    main();
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exit(1);
  }
}
