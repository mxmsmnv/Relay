import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../assets/relay.js', import.meta.url), 'utf8');
const hooks = {};
const document = {
  documentElement: {lang: 'en-US'},
  addEventListener() {},
  querySelectorAll() { return []; }
};
const window = {
  RelayTestHooks: hooks,
  clearTimeout,
  requestAnimationFrame(callback) { callback(); },
  setTimeout
};

vm.runInNewContext(source, {Array, Date, Event, Intl, Math, Number, Object, String, document, window});

test('dense month-day state retains three visible actions', () => {
  assert.deepEqual({...hooks.monthDayState(0)}, {hasEvents: false, hiddenCount: 0});
  assert.deepEqual({...hooks.monthDayState(3)}, {hasEvents: true, hiddenCount: 0});
  assert.deepEqual({...hooks.monthDayState(8)}, {hasEvents: true, hiddenCount: 5});
});

test('active mobile navigation is centred and clamped', () => {
  assert.equal(hooks.activeNavigationScrollLeft(365, 0, 70), 0);
  assert.equal(hooks.activeNavigationScrollLeft(365, 788, 70), 640.5);
});

test('drag-and-drop date labels use a readable localized format', () => {
  assert.equal(hooks.formatLocalDateTimeLabel('2026-08-14T09:24'), 'Aug 14, 2026 · 09:24');
});

test('time presets preserve the selected date and reject malformed times', () => {
  assert.equal(hooks.withPresetTime('2026-08-16T09:00', '15:30'), '2026-08-16T15:30');
  assert.equal(hooks.withPresetTime('2026-08-16T09:00', '25:00'), '2026-08-16T09:00');
  assert.equal(hooks.withPresetTime('', '15:30'), '');
});

test('quick rule presets keep end dates relative to the selected start date', () => {
  assert.equal(hooks.datePlusDays('2026-08-31', 1), '2026-09-01');
  assert.equal(hooks.datePlusDays('2028-02-28', 1), '2028-02-29');
  assert.equal(hooks.datePlusDays('not-a-date', 7), 'not-a-date');
});

test('quick cadence presets change only the interval and unit', () => {
  const controls = {
    frequency: {value: 'week', dispatchEvent() {}},
    interval: {value: '1'},
    action: {value: 'publish'},
    template: {value: 'blog-post'}
  };
  const form = {querySelector(selector) { return controls[selector.match(/name="([^"]+)"/)?.[1]] || null; }};
  assert.equal(hooks.applyQuickPreset(form, {frequency: 'minute', interval: 69, action: 'unpublish', template: 'event'}), true);
  assert.equal(controls.frequency.value, 'minute');
  assert.equal(controls.interval.value, '69');
  assert.equal(controls.action.value, 'publish');
  assert.equal(controls.template.value, 'blog-post');
});

test('settings anchors scroll and focus again when the hash is already active', () => {
  const calls = [];
  const target = {
    scrollIntoView(options) { calls.push(['scroll', {...options}]); },
    focus(options) { calls.push(['focus', {...options}]); }
  };
  assert.equal(hooks.focusRelayTarget(target), true);
  assert.deepEqual(calls, [
    ['scroll', {block: 'start', behavior: 'auto'}],
    ['focus', {preventScroll: true}]
  ]);
  assert.equal(hooks.focusRelayTarget(null), false);
});

test('full calendar rows only become draggable from their handle', () => {
  assert.match(source, /var dragHandle = source\.querySelector\('\.RelayEvent__drag'\)/);
  assert.match(source, /source\.setAttribute\('draggable', 'false'\)/);
  assert.match(source, /dragHandle\.addEventListener\('pointerdown'/);
  assert.match(source, /event\.target\.closest\('\.RelayEvent__drag'\)/);
});
