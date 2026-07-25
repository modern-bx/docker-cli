const { chromium } = require('playwright');

const IS_VISUAL_MODE = process.env.PLAYWRIGHT_SHOW === '1';
const ACTION_DELAY_MS = Number(globalThis.wizard.action_delay[IS_VISUAL_MODE ? 'visual' : 'headless']);
const SITE_MANAGER_EDITION_TITLES = new Set([
  'Установка «1С-Битрикс: Управление сайтом: Старт»',
  'Установка «1С-Битрикс: Управление сайтом: Стандарт»',
  'Установка «1С-Битрикс: Управление сайтом: Малый бизнес»',
  'Установка «1С-Битрикс: Управление сайтом: Бизнес»',
  'Установка «1С-Битрикс: Управление сайтом: Энтерпрайз»',
]);
const logging = globalThis.dockerCli.logging;

if (!Number.isFinite(ACTION_DELAY_MS) || ACTION_DELAY_MS < 0) {
  throw new Error('Задержка между действиями в wizard.action_delay должна быть неотрицательным числом.');
}

const normalizeTitle = (title) => title.trim().replace(/\s+/g, ' ');

const installationError = (page) => page.locator('.inst-note-block.inst-note-block-red').first();

const stopOnInstallationError = async (page) => {
  const errorBlock = installationError(page);
  if (!await errorBlock.isVisible()) {
    return false;
  }

  const errorText = (await errorBlock.textContent() || '').trim();
  const message = errorText || 'Неизвестная ошибка установки.';
  logging.error(`Ошибка установки: ${message}`);
  return true;
};

const pauseAfterAction = async () => {
  logging.debug(`Ожидание ${ACTION_DELAY_MS} мс перед следующим действием.`);
  await new Promise((resolve) => setTimeout(resolve, ACTION_DELAY_MS));
};

const click = async (page, selector, description) => {
  logging.info(`${description}: клик по ${selector}.`);
  await page.locator(selector).click();
  logging.info(`${description}: клик выполнен.`);
  await pauseAfterAction();
};

const clickAndWaitForPage = async (page, selector, description) => {
  logging.info(`${description}: клик по ${selector} и ожидание следующей страницы.`);
  const navigation = page.waitForNavigation({ waitUntil: 'load' }).then(() => 'navigation');
  const errorAppeared = installationError(page).waitFor({ state: 'visible', timeout: 0 }).then(() => 'error');
  await page.locator(selector).click();
  const result = await Promise.race([navigation, errorAppeared]);
  if (result === 'error') {
    await stopOnInstallationError(page);
    return false;
  }
  if (await stopOnInstallationError(page)) {
    return false;
  }
  logging.info(`${description}: следующая страница загружена.`);
  await pauseAfterAction();
  return true;
};

const fill = async (page, selector, value, description, logValue = true) => {
  const valueDescription = logValue ? ` значением "${value}"` : '';
  logging.info(`${description}: заполнение ${selector}${valueDescription}.`);
  await page.locator(selector).fill(value);
  logging.info(`${description}: поле заполнено.`);
  await pauseAfterAction();
};

const installBitrixSiteManager = async (page) => {
  logging.info('Запуск сценария установки «1С-Битрикс: Управление сайтом».');

  if (!await clickAndWaitForPage(page, 'input[name=StepNext]', 'Переход к лицензионному соглашению')) {
    return false;
  }
  await click(page, 'label[for=agree_license_id]', 'Принятие лицензионного соглашения');
  if (!await clickAndWaitForPage(page, 'input[name=StepNext]', 'Переход к выбору лицензионного ключа')) {
    return false;
  }
  await click(page, 'label[for=lic_key_variant]', 'Выбор варианта лицензионного ключа');
  if (!await clickAndWaitForPage(page, 'input[name=StepNext]', 'Переход к следующему шагу установки')) {
    return false;
  }
  if (!await clickAndWaitForPage(page, 'input[name=StepNext]', 'Переход к настройкам базы данных')) {
    return false;
  }

  const databaseHost = globalThis.wizard.db.host;
  if (!['mysql', 'postgres'].includes(databaseHost)) {
    throw new Error(`Неподдерживаемый хост БД в wizard.db.host: ${databaseHost}. Ожидается mysql или postgres.`);
  }

  const database = globalThis.project.data.databases[databaseHost];
  await fill(page, 'input[name=__wiz_host]', databaseHost, `Указание хоста ${databaseHost}`);
  await fill(page, 'input[name=__wiz_user]', database.username, `Указание пользователя ${databaseHost}`);
  await fill(page, 'input[name=__wiz_password]', database.password, `Указание пароля ${databaseHost}`, false);
  await fill(page, 'input[name=__wiz_database]', database.database, `Указание базы данных ${databaseHost}`);

  logging.info('Настройки базы данных для «1С-Битрикс: Управление сайтом» успешно заполнены.');

  logging.info('Запуск установки базы данных: клик по input[name=StepNext].');
  const administratorPage = page.locator('.inst-cont-title', { hasText: 'Создание администратора' }).waitFor({
    state: 'visible',
    timeout: 0,
  }).then(() => 'administrator');
  const installationFailed = installationError(page).waitFor({ state: 'visible', timeout: 0 }).then(() => 'error');
  await page.locator('input[name=StepNext]').click();
  logging.info('Запуск установки базы данных: клик выполнен.');
  await pauseAfterAction();
  logging.info('Ожидание завершения установки базы данных и страницы создания администратора.');
  const installationResult = await Promise.race([administratorPage, installationFailed]);
  if (installationResult === 'error') {
    await stopOnInstallationError(page);
    return false;
  }
  if (await stopOnInstallationError(page)) {
    return false;
  }
  logging.info('Страница создания администратора загружена.');

  const admin = globalThis.wizard.admin;
  const adminEmail = admin.email.replace('<project-host>', new URL(page.url()).hostname);
  await fill(page, 'input[name=__wiz_login]', admin.login, 'Указание логина администратора', false);
  await fill(page, 'input[name=__wiz_admin_password]', admin.password, 'Указание пароля администратора', false);
  await fill(page, 'input[name=__wiz_admin_password_confirm]', admin.password, 'Подтверждение пароля администратора', false);
  await fill(page, 'input[name=__wiz_email]', adminEmail, 'Указание email администратора');
  await fill(page, 'input[name=__wiz_user_name]', admin.name, 'Указание имени администратора');
  await fill(page, 'input[name=__wiz_user_surname]', admin.last_name, 'Указание фамилии администратора');

  console.log(`Логин администратора Bitrix: ${admin.login}`);
  logging.info('Поля администратора заполнены; логин выведен только в stdout.');

  return clickAndWaitForPage(page, 'input[name=StepNext]', 'Отправка данных администратора');
};

(async () => {
  const url = process.env.PLAYWRIGHT_URL || process.env.PROJECT_URL;
  if (!url) {
    throw new Error('Задайте PLAYWRIGHT_URL или PROJECT_URL с адресом открываемой страницы.');
  }

  logging.info(`Открытие ${url}.`);
  logging.info(`Режим браузера: ${IS_VISUAL_MODE ? 'визуальный' : 'без интерфейса'}; задержка между действиями: ${ACTION_DELAY_MS} мс.`);

  const browser = await chromium.launch();
  const page = await browser.newPage();

  try {
    await page.goto(url, { waitUntil: 'load' });
    logging.info('Страница установки загружена.');

    const title = await page.title();
    logging.info(`Заголовок страницы установки: ${title}.`);

    if (SITE_MANAGER_EDITION_TITLES.has(normalizeTitle(title))) {
      const installed = await installBitrixSiteManager(page);
      if (!installed) {
        process.exitCode = 1;
      }
    } else {
      logging.warn(`Для страницы с заголовком «${title}» сценарий установки не найден.`);
    }
  } finally {
    await browser.close();
    logging.info('Браузер закрыт.');
  }
})();
