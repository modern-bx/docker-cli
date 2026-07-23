const { chromium } = require('playwright');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  const url = process.env.PLAYWRIGHT_URL || process.env.PROJECT_URL;
  if (!url) {
    throw new Error('Set PLAYWRIGHT_URL or PROJECT_URL to the page that should be opened.');
  }

  const browser = await chromium.launch();
  const page = await browser.newPage();

  try {
    await page.goto(url, { waitUntil: 'load' });
    await delay(3000);
    console.log(await page.title());
    await delay(10000);
  } finally {
    await browser.close();
  }
})();
