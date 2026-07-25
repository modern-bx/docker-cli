const playwright = require('playwright');

if (process.env.PLAYWRIGHT_SHOW === '1') {
  for (const browserName of ['chromium', 'firefox', 'webkit']) {
    const browserType = playwright[browserName];
    const launch = browserType.launch.bind(browserType);

    browserType.launch = (options = {}) => launch({ ...options, headless: false });
  }
}
