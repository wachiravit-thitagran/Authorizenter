const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Loading questions JS on a page must leave every native login widget alone.
const listeners = [];
const widget = {
  parentElement: { querySelector() { return null; } },
  addEventListener(type, handler) { listeners.push([type, handler]); },
};
vm.runInNewContext(fs.readFileSync('plugins/authorizenter-ui/assets/authorizenter.js', 'utf8'), {
  window: {},
  document: {
    readyState: 'complete',
    getElementById(id) { return id === 'authorizenter-login-form' ? widget : null; },
  },
});
assert.equal(listeners.length, 0, 'Login forms must use native POST navigation even when questions JS is present');
console.log('PASS: questions JS preserves native login form navigation');
