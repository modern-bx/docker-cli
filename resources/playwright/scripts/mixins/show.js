const playwright = require('playwright');

const selectedBrowser = process.env.PLAYWRIGHT_BROWSER;
const showBrowser = process.env.PLAYWRIGHT_SHOW === '1';

if (selectedBrowser || showBrowser) {
  const launchers = Object.fromEntries(
    ['chromium', 'firefox', 'webkit'].map((browserName) => [
      browserName,
      playwright[browserName].launch.bind(playwright[browserName]),
    ]),
  );

  for (const browserName of Object.keys(launchers)) {
    playwright[browserName].launch = (options = {}) => {
      const launch = launchers[selectedBrowser || browserName];
      return launch(showBrowser ? { ...options, headless: false } : options);
    };
  }
}
