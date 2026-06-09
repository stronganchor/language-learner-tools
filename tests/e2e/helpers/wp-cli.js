const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const PLUGIN_ROOT = path.resolve(__dirname, '..', '..', '..');
const WP_ROOT = path.resolve(PLUGIN_ROOT, '..', '..', '..');

class WpCliUnavailableError extends Error {
  constructor(message, cause = null) {
    super(message);
    this.name = 'WpCliUnavailableError';
    this.cause = cause;
    this.isWpCliUnavailable = true;
  }
}

function firstExistingPath(candidates) {
  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate)) {
      return candidate;
    }
  }
  return '';
}

function resolveWpCliCommand() {
  const explicitWpCli = process.env.WP_CLI || '';
  if (explicitWpCli) {
    return {
      command: explicitWpCli,
      baseArgs: [],
      mode: 'wp'
    };
  }

  const wpCliPhar = firstExistingPath([
    process.env.WP_CLI_PHAR || '',
    path.join(process.env.USERPROFILE || '', 'AppData', 'Local', 'Programs', 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
    '/mnt/c/Users/messy/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar'
  ]);
  const phpBin = firstExistingPath([
    process.env.PHP_BIN || '',
    'C:\\php\\8.4\\php.exe',
    'C:\\php\\8.3\\php.exe',
    '/mnt/c/php/8.4/php.exe',
    '/mnt/c/php/8.3/php.exe'
  ]) || 'php';

  if (wpCliPhar) {
    return {
      command: phpBin,
      baseArgs: [wpCliPhar],
      mode: 'php-phar'
    };
  }

  return {
    command: 'wp',
    baseArgs: [],
    mode: 'wp'
  };
}

function runWpCli(args, options = {}) {
  const resolved = resolveWpCliCommand();
  const finalArgs = [
    ...resolved.baseArgs,
    `--path=${options.wpRoot || WP_ROOT}`,
    ...args
  ];

  try {
    return execFileSync(resolved.command, finalArgs, {
      cwd: options.cwd || PLUGIN_ROOT,
      env: Object.assign({}, process.env, options.env || {}),
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: options.timeoutMs || 120000
    });
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      throw new WpCliUnavailableError(`WP-CLI command is not available: ${resolved.command}`, error);
    }
    throw error;
  }
}

function runWpCliJson(args, options = {}) {
  const output = runWpCli(args, options).trim();
  const lastLine = output.split(/\r?\n/).filter(Boolean).pop() || '';
  if (!lastLine) {
    throw new Error('WP-CLI command did not return JSON output.');
  }
  return JSON.parse(lastLine);
}

module.exports = {
  PLUGIN_ROOT,
  WP_ROOT,
  runWpCli,
  runWpCliJson,
  WpCliUnavailableError
};
