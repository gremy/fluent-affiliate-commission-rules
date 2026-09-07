import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const source = fs.readFileSync(new URL('../assets/admin/app.js', import.meta.url), 'utf8');

function harness() {
  let definition;
  const requests = [];
  const notices = [];
  let decision = Promise.resolve();
  const window = {
    innerWidth: 1200,
    location: { origin: 'http://example.test' },
    facrAdmin: { rest_url: 'http://example.test/wp-json/fa-commission-rules/v1', nonce: 'test', i18n: {} },
    facrHelpers: require('../assets/admin/helpers.js'),
    ElementPlus: { ElNotification: x => notices.push(x), ElMessageBox: { confirm: () => decision, alert: () => Promise.resolve() } },
    Vue: { createApp: value => { definition = value; return { use() {}, mount() {} }; } },
    fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve })),
  };
  vm.runInNewContext(source, { window, document: { getElementById: () => ({}) }, URL, Promise });
  const app = definition.data();
  Object.entries(definition.methods).forEach(([name, fn]) => { app[name] = fn.bind(app); });
  Object.entries(definition.computed).forEach(([name, fn]) => Object.defineProperty(app, name, { get: fn.bind(app) }));
  app.loading = false;
  app.optionsReady = true;
  app.revision = 'reviewed-revision';
  app.openEditor();
  const reply = (index, data, status = 200) => requests[index].resolve({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: () => Promise.resolve(data) });
  return { app, requests, reply, notices, decide: value => { decision = value; } };
}

test('clearing a query invalidates an older response and stops loading', async () => {
  const h = harness();
  const pending = h.app.searchProducts('abc');
  h.app.searchProducts('');
  h.reply(0, [{ id: 42, label: 'old result' }]);
  await pending;
  assert.equal(h.app.editor.productOptions.length, 0);
  assert.equal(h.app.editor.productLoading, false);
});

test('discard and keep editing share the drawer close path', async () => {
  const h = harness();
  h.app.editor.form.rate = 17;
  h.decide(Promise.reject(new Error('cancel')));
  await h.app.closeEditor();
  assert.equal(h.app.editor.open, true);
  assert.equal(h.app.editor.form.rate, 17);
  h.decide(Promise.resolve());
  await h.app.closeEditor();
  assert.equal(h.app.editor.open, false);
  h.app.openEditor();
  assert.equal(h.app.editor.form.rate, null);
  assert.equal(h.app.editor.productLoading, false);
});

test('pending save cannot be dismissed and successful persistence reloads the list', async () => {
  const h = harness();
  h.app.editor.form.rate = 17;
  const saving = h.app.save();
  assert.equal(h.requests[0].options.headers['If-Match'], 'reviewed-revision');
  assert.equal(new URL(h.requests[0].url).searchParams.get('_locale'), 'user');
  await h.app.closeEditor();
  assert.equal(h.app.editor.open, true);
  assert.equal(h.app.editor.saving, true);
  h.reply(0, { rule: { id: 'new', labels: { sentence: 'saved' } } });
  await saving;
  assert.equal(h.app.editor.open, false);
  assert.equal(h.requests.length, 2);
  h.reply(1, { rules: [], revision: 'new-revision' });
});

test('write conflict keeps the entered values and tells the user to reload', async () => {
  const h = harness();
  h.app.editor.form.rate = 17;
  const saving = h.app.save();
  h.reply(0, { message: 'Reload rules' }, 409);
  await saving;
  assert.equal(h.app.editor.open, true);
  assert.equal(h.app.editor.form.rate, 17);
  assert.equal(h.app.loadError, 'Reload rules');
  assert.equal(h.app.editor.submitError, 'Reload rules');
  assert.equal(h.app.editor.saving, false);
});

test('a stale list response cannot replace a newer revision', async () => {
  const h = harness();
  const first = h.app.load();
  const second = h.app.load();
  h.reply(1, { rules: [], revision: 'new' });
  await second;
  h.reply(0, { rules: [], revision: 'old' });
  await first;
  assert.equal(h.app.revision, 'new');
});

test('closing during product search does not carry loading state into a new editor', async () => {
  const h = harness();
  const search = h.app.searchProducts('abc');
  await h.app.closeEditor();
  h.app.openEditor();
  h.reply(0, [{ id: 42, label: 'old result' }]);
  await search;
  assert.equal(h.app.editor.productLoading, false);
  assert.equal(h.app.editor.productOptions.length, 0);
});

test('customer type defaults to Any, survives editing and is sent on save', async () => {
  const h = harness();
  assert.equal(h.app.editor.form.customer_type, 'all');
  h.app.openEditor({ id: 'b2b-rule', scope_type: 'all', target_type: 'all', customer_type: 'b2b', rate: 3, rate_type: 'percentage', status: 'active' });
  assert.equal(h.app.editor.form.customer_type, 'b2b');
  const saving = h.app.save();
  assert.equal(JSON.parse(h.requests[0].options.body).customer_type, 'b2b');
  h.reply(0, { rule: { id: 'b2b-rule' } });
  await saving;
});
