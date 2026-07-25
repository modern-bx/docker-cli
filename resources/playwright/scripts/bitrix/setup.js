const { chromium } = require('playwright');
const { randomInt } = require('node:crypto');

const ACTION_DELAY_MS = 2000;
const START_EDITION_TITLE = 'Установка «1С-Битрикс: Управление сайтом: Старт»';
const logging = globalThis.dockerCli.logging;

const normalizeTitle = (title) => title.trim().replace(/\s+/g, ' ');

const generatePassword = () => {
  const lowerCaseLetters = 'abcdefghijklmnopqrstuvwxyz';
  const upperCaseLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const digits = '0123456789';
  const alphabet = lowerCaseLetters + upperCaseLetters + digits;
  const characters = [
    lowerCaseLetters[randomInt(lowerCaseLetters.length)],
    upperCaseLetters[randomInt(upperCaseLetters.length)],
    digits[randomInt(digits.length)],
  ];

  while (characters.length < 24) {
    characters.push(alphabet[randomInt(alphabet.length)]);
  }

  for (let index = characters.length - 1; index > 0; index -= 1) {
    const swapIndex = randomInt(index + 1);
    [characters[index], characters[swapIndex]] = [characters[swapIndex], characters[index]];
  }

  return characters.join('');
};

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

  const mysql = globalThis.project.data.databases.mysql;
  await fill(page, 'input[name=__wiz_host]', 'mysql', 'Setting the MySQL host');
  await fill(page, 'input[name=__wiz_user]', mysql.username, 'Setting the MySQL username');
  await fill(page, 'input[name=__wiz_password]', mysql.password, 'Setting the MySQL password', false);
  await fill(page, 'input[name=__wiz_database]', mysql.database, 'Setting the MySQL database');

  logging.info('Database settings for the Start edition have been filled successfully.');

  await click(page, 'input[name=StepNext]', 'Starting the database installation');
  logging.info('Waiting for database installation and the administrator creation page.');
  await page.locator('.inst-cont-title', { hasText: 'Создание администратора' }).waitFor({
    state: 'visible',
    timeout: 0,
  });
  logging.info('Administrator creation page loaded.');

  const adminLogin = 'admin';
  const adminPassword = generatePassword();
  await fill(page, 'input[name=__wiz_login]', adminLogin, 'Setting the administrator login', false);
  await fill(page, 'input[name=__wiz_admin_password]', adminPassword, 'Setting the administrator password', false);
  await fill(page, 'input[name=__wiz_admin_password_confirm]', adminPassword, 'Confirming the administrator password', false);
  await fill(page, 'input[name=__wiz_email]', 'test@test.test', 'Setting the administrator email');
  await fill(page, 'input[name=__wiz_user_name]', 'Иван', 'Setting the administrator first name');
  await fill(page, 'input[name=__wiz_user_surname]', 'Иванов', 'Setting the administrator surname');

  console.log(`Bitrix administrator login: ${adminLogin}`);
  console.log(`Bitrix administrator password: ${adminPassword}`);
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
