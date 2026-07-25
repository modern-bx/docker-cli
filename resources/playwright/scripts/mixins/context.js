const fs = require('node:fs');

const contextFile = process.env.PLAYWRIGHT_CONTEXT_FILE;

if (contextFile) {
  const context = JSON.parse(fs.readFileSync(contextFile, 'utf8'));

  for (const [name, value] of Object.entries(context)) {
    Object.defineProperty(globalThis, name, {
      configurable: true,
      enumerable: true,
      value,
      writable: false,
    });
  }
}
