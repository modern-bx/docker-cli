const { chromium } = require('playwright');

const ACTION_DELAY_MS = 2000;
const START_EDITION_TITLE = 'Установка «1С-Битрикс: Управление сайтом: Старт»';
const logging = globalThis.dockerCli.logging;

const normalizeTitle = (title) => title.trim().replace(/\s+/g, ' ');

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

  const databaseHost = globalThis.wizard.db.host;
  if (!['mysql', 'postgres'].includes(databaseHost)) {
    throw new Error(`Unsupported database host in wizard.db.host: ${databaseHost}. Expected mysql or postgres.`);
  }

  const database = globalThis.project.data.databases[databaseHost];
  await fill(page, 'input[name=__wiz_host]', databaseHost, `Setting the ${databaseHost} host`);
  await fill(page, 'input[name=__wiz_user]', database.username, `Setting the ${databaseHost} username`);
  await fill(page, 'input[name=__wiz_password]', database.password, `Setting the ${databaseHost} password`, false);
  await fill(page, 'input[name=__wiz_database]', database.database, `Setting the ${databaseHost} database`);

  logging.info('Database settings for the Start edition have been filled successfully.');

  await click(page, 'input[name=StepNext]', 'Starting the database installation');
  logging.info('Waiting for database installation and the administrator creation page.');
  await page.locator('.inst-cont-title', { hasText: 'Создание администратора' }).waitFor({
    state: 'visible',
    timeout: 0,
  });
  logging.info('Administrator creation page loaded.');

  const admin = globalThis.wizard.admin;
  const adminEmail = admin.email.replace('<project-host>', new URL(page.url()).hostname);
  await fill(page, 'input[name=__wiz_login]', admin.login, 'Setting the administrator login', false);
  await fill(page, 'input[name=__wiz_admin_password]', admin.password, 'Setting the administrator password', false);
  await fill(page, 'input[name=__wiz_admin_password_confirm]', admin.password, 'Confirming the administrator password', false);
  await fill(page, 'input[name=__wiz_email]', adminEmail, 'Setting the administrator email');
  await fill(page, 'input[name=__wiz_user_name]', admin.name, 'Setting the administrator first name');
  await fill(page, 'input[name=__wiz_user_surname]', admin.last_name, 'Setting the administrator surname');

  console.log(`Bitrix administrator login: ${admin.login}`);
  console.log(`Bitrix administrator password: ${admin.password}`);
  logging.info('Administrator fields have been filled; credentials were printed to stdout only.');

  await clickAndWaitForPage(page, 'input[name=StepNext]', 'Submitting the administrator details');
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

    if (normalizeTitle(title) === START_EDITION_TITLE) {
      await installStartEdition(page);
    } else {
      logging.warn(`No installation scenario is available for page title: ${title}.`);
    }
  } finally {
    await browser.close();
    logging.info('Browser closed.');
  }
})();
