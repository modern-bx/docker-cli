const fs = require('fs');
const path = require('path');

/**
 * Writes Playwright scenario diagnostics to every supported channel.
 *
 * The helper always writes a timestamped line to stdout and also mirrors the
 * same line into a per-run text file. docker-cli passes PLAYWRIGHT_LOG_DIR to
 * point at ~/.config/docker-cli/projects/<project>/logs/playwright on the host.
 *
 * Public API:
 *   dockerCli.logging.log(message)
 */
class PlaywrightLoggingHelper {
  constructor(
    logDirectory = process.env.PLAYWRIGHT_LOG_DIR || path.join(process.cwd(), '.docker-cli', 'playwright', 'logs'),
    scriptId = process.env.PLAYWRIGHT_SCRIPT_ID || 'playwright',
  ) {
    this.logDirectory = logDirectory;
    this.scriptName = this.normalizeScriptName(scriptId);
    this.logFile = path.join(this.logDirectory, `${this.scriptName}-${this.timestamp().replace(/[:.]/g, '-')}.log`);
  }

  /**
   * Write one message to stdout and to the text log file.
   *
   * @param {string} message Human-readable diagnostic message.
   */
  log(message) {
    const line = `[${this.timestamp()}] ${message}`;
    console.log(line);
    this.writeTextFile(line);
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
      console.warn(`[${this.timestamp()}] Unable to write log file ${this.logFile}: ${error.message}`);
    }
  }
}

globalThis.dockerCli = globalThis.dockerCli || {};
globalThis.dockerCli.logging = globalThis.dockerCli.logging || new PlaywrightLoggingHelper();
globalThis.dockerCli.logging.log(`Log file: ${globalThis.dockerCli.logging.logFile}`);
