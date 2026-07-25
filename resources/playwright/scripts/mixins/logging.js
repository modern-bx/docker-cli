const fs = require('fs');
const path = require('path');

/**
 * Writes Playwright scenario diagnostics to every supported channel.
 *
 * The helper always writes a timestamped line with a severity level to stdout
 * and mirrors the same plain-text line into a per-run log file. docker-cli
 * passes PLAYWRIGHT_LOG_DIR to point at
 * ~/.config/docker-cli/projects/<project>/logs/playwright on the host.
 *
 * Public API:
 *   dockerCli.logging.info(message)
 *   dockerCli.logging.warn(message)
 *   dockerCli.logging.error(message)
 *   dockerCli.logging.debug(message)
 */
class PlaywrightLoggingHelper {
  constructor(
    logDirectory = process.env.PLAYWRIGHT_LOG_DIR || path.join(process.cwd(), '.docker-cli', 'playwright', 'logs'),
    scriptId = process.env.PLAYWRIGHT_SCRIPT_ID || 'playwright',
  ) {
    this.logDirectory = logDirectory;
    this.scriptName = this.normalizeScriptName(scriptId);
    this.logFile = path.join(this.logDirectory, `${this.scriptName}-${this.timestamp().replace(/[:.]/g, '-')}.log`);
    this.colors = {
      INFO: '\x1b[32m',
      WARN: '\x1b[33m',
      ERROR: '\x1b[31m',
      DEBUG: '\x1b[36m',
    };
    this.resetColor = '\x1b[0m';
  }

  /** @param {string} message Human-readable informational message. */
  info(message) {
    this.write('INFO', message);
  }

  /** @param {string} message Human-readable warning message. */
  warn(message) {
    this.write('WARN', message);
  }

  /** @param {string} message Human-readable error message. */
  error(message) {
    this.write('ERROR', message);
  }

  /** @param {string} message Human-readable debug message. */
  debug(message) {
    this.write('DEBUG', message);
  }

  write(level, message) {
    const line = `[${this.timestamp()}] [${level}] ${message}`;
    console.log(this.colorize(level, line));
    this.writeTextFile(line);
  }

  colorize(level, line) {
    if (!process.stdout.isTTY || !this.colors[level]) {
      return line;
    }

    return `${this.colors[level]}${line}${this.resetColor}`;
  }

  timestamp() {
    return new Date().toISOString();
  }

  normalizeScriptName(scriptId) {
    return String(scriptId)
      .replace(/\.js$/i, '')
      .replace(/[^a-z0-9]+/gi, '-')
      .replace(/^-+|-+$/g, '')
      .toLowerCase() || 'playwright';
  }

  writeTextFile(line) {
    try {
      fs.mkdirSync(this.logDirectory, { recursive: true });
      fs.appendFileSync(this.logFile, `${line}\n`);
    } catch (error) {
      console.warn(this.colorize('WARN', `[${this.timestamp()}] [WARN] Не удалось записать файл лога ${this.logFile}: ${error.message}`));
    }
  }
}

globalThis.dockerCli = globalThis.dockerCli || {};
globalThis.dockerCli.logging = globalThis.dockerCli.logging || new PlaywrightLoggingHelper();
globalThis.dockerCli.logging.info(`Файл лога: ${globalThis.dockerCli.logging.logFile}`);
