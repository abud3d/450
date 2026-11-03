const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
test('project includes a Saudi Arabic RTL game entry point', () => {
  const html = fs.readFileSync('public/index.html','utf8');
  assert.match(html, /lang="ar" dir="rtl"/);
  assert.match(html, /بلوت/);
});
test('server exposes a health endpoint', () => {
  const server = fs.readFileSync('server.js','utf8');
  assert.match(server, /\/health/);
  assert.match(server, /new Server\(server\)/);
});
