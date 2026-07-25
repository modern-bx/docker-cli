const { chromium } = require('playwright');

const ACTION_DELAY_MS = 250;
const START_EDITION_TITLE = 'Установка  «1С-Битрикс: Управление сайтом: Старт»';
const logging = globalThis.dockerCli.logging;

const pauseAfterAction = async () => {
  logging.debug(`Waiting ${ACTION_DELAY_MS} ms before the next action.`);
  await new Promise((resolve) => setTimeout(resolve, ACTION_DELAY_MS));
};

const click = async (page, selector, description) => {
  logging.info(`${description}: clicking ${selector}.`);
  await page.locator(selector).click();
  logging.info(`${description}: click completed.`);
  await pauseAfterAction();
};

const clickAndWaitForPage = async (page, selector, description) => {
  logging.info(`${description}: clicking ${selector} and waiting for the next page.`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.locator(selector).click(),
  ]);
  logging.info(`${description}: next page loaded.`);
  await pauseAfterAction();
};

const fill = async (page, selector, value, description, logValue = true) => {
  const valueDescription = logValue ? ` with "${value}"` : '';
  logging.info(`${description}: filling ${selector}${valueDescription}.`);
  await page.locator(selector).fill(value);
  logging.info(`${description}: field filled.`);
  await pauseAfterAction();
};

const installStartEdition = async (page) => {
  logging.info('Starting installation scenario for the Start edition.');

  await clickAndWaitForPage(page, 'input[name=StepNext]', 'Opening the license agreement step');
  await click(page, 'label[for=agree_license_id]', 'Accepting the license agreement');
  await clickAndWaitForPage(page, 'input[name=StepNext]', 'Opening the license key step');
  await click(page, 'label[for=lic_key_variant]', 'Selecting the license key option');
  await clickAndWaitForPage(page, 'input[name=StepNext]', 'Opening the next installation step');
  await clickAndWaitForPage(page, 'input[name=StepNext]', 'Opening the database settings step');

  const mysql = globalThis.project.databases.mysql;
  await fill(page, 'input[name=__wiz_host]', 'mysql', 'Setting the MySQL host');
  await fill(page, 'input[name=__wiz_user]', mysql.username, 'Setting the MySQL username');
  await fill(page, 'input[name=__wiz_password]', mysql.password, 'Setting the MySQL password', false);
  await fill(page, 'input[name=__wiz_database]', mysql.database, 'Setting the MySQL database');

  logging.info('Database settings for the Start edition have been filled successfully.');
};

(async () => {
  const url = process.env.PLAYWRIGHT_URL || process.env.PROJECT_URL;
  if (!url) {
    throw new Error('Set PLAYWRIGHT_URL or PROJECT_URL to the page that should be opened.');
  }

  logging.info(`Opening ${url}.`);

  const browser = await chromium.launch();
  const page = await browser.newPage();

  try {
    await page.goto(url, { waitUntil: 'load' });
    logging.info('Installation page loaded.');

    const title = await page.title();
    logging.info(`Installation page title: ${title}.`);

    if (title === START_EDITION_TITLE) {
      await installStartEdition(page);
    } else {
      logging.warn(`No installation scenario is available for page title: ${title}.`);
    }
  } finally {
    await browser.close();
    logging.info('Browser closed.');
  }
})();
