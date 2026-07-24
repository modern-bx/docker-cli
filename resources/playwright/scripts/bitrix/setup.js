const { chromium } = require('playwright');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const logging = globalThis.dockerCli.logging;

(async () => {
  const url = process.env.PLAYWRIGHT_URL || process.env.PROJECT_URL;
  if (!url) {
    throw new Error('Set PLAYWRIGHT_URL or PROJECT_URL to the page that should be opened.');
  }

  logging.info(`Opening ${url}`);

  const browser = await chromium.launch();
  const page = await browser.newPage();

  page.on('console', (message) => logging.debug(`browser console ${message.type()}: ${message.text()}`));
  page.on('pageerror', (error) => logging.error(`browser page error: ${error.message}`));

  try {
    await page.goto(url, { waitUntil: 'load' });
    logging.info('Page load event has fired; waiting 3 seconds before reading title.');
    await delay(3000);

    const title = await page.title();
    logging.info(`Page title: ${title}`);

    logging.info('Waiting 10 seconds before finishing.');
    await delay(10000);
    logging.info('Scenario finished successfully.');
  } finally {
    await browser.close();
    logging.info('Browser closed.');
  }
})();
