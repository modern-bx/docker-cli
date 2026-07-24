const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const timestamp = () => new Date().toISOString();
const logDirectory = process.env.PLAYWRIGHT_LOG_DIR || path.join(process.cwd(), '.docker-cli', 'playwright', 'logs');
const logFile = path.join(logDirectory, `bitrix-setup-${timestamp().replace(/[:.]/g, '-')}.log`);

const writeLogFile = (message) => {
  try {
    fs.mkdirSync(logDirectory, { recursive: true });
    fs.appendFileSync(logFile, `${message}\n`);
  } catch (error) {
    console.warn(`[${timestamp()}] Unable to write log file ${logFile}: ${error.message}`);
  }
};

const log = (message) => {
  const line = `[${timestamp()}] ${message}`;
  console.log(line);
  writeLogFile(line);
};

(async () => {
  const url = process.env.PLAYWRIGHT_URL || process.env.PROJECT_URL;
  if (!url) {
    throw new Error('Set PLAYWRIGHT_URL or PROJECT_URL to the page that should be opened.');
  }

  log(`Log file: ${logFile}`);
  log(`Opening ${url}`);

  const browser = await chromium.launch();
  const page = await browser.newPage();

  page.on('console', (message) => log(`browser console ${message.type()}: ${message.text()}`));
  page.on('pageerror', (error) => log(`browser page error: ${error.message}`));

  try {
    await page.goto(url, { waitUntil: 'load' });
    log('Page load event has fired; waiting 3 seconds before reading title.');
    await delay(3000);

    const title = await page.title();
    log(`Page title: ${title}`);

    log('Waiting 10 seconds before finishing.');
    await delay(10000);
    log('Scenario finished successfully.');
  } finally {
    await browser.close();
    log('Browser closed.');
  }
})();
