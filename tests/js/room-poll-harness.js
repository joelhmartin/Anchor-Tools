/*
 * Node harness for anchor-events-manager/assets/room.js (driven by
 * Test_Assets). No DOM library is installed here, so this is a minimal fake
 * jQuery covering exactly the calls room.js makes: one `.anchor-room-state`
 * element with attributes, replaceWith(), find(), text(), append(), and an
 * $.ajax() whose done() fires synchronously with the scenario's response.
 *
 * Usage: node room-poll-harness.js <room.js path> '<scenario JSON>'
 *   scenario = { attrs: {data-state: ...}, response: {...}, trigger: 'poll'|'tick' }
 * Prints { replaced: bool, attrs: {...} } as JSON.
 */
'use strict';
const fs = require('fs');
const vm = require('vm');

const [, , roomPath, scenarioJson] = process.argv;
const scenario = JSON.parse(scenarioJson);

let block = { attrs: Object.assign({}, scenario.attrs), replacedWith: null };
let replaced = false;
const intervals = [];

function wrap(el) {
  const api = {
    length: el ? 1 : 0,
    first() { return api; },
    attr(name, value) {
      if (value === undefined) { return el && el.attrs[name] !== undefined ? String(el.attrs[name]) : undefined; }
      el.attrs[name] = String(value);
      return api;
    },
    find() { return wrap(null); },
    text() { return api; },
    append() { return api; },
    replaceWith(html) {
      replaced = true;
      el.replacedWith = html;
      // The new block's attributes, as the fresh markup would carry them.
      const next = {};
      String(html).replace(/(data-[a-z-]+)="([^"]*)"/g, (m, k, v) => { next[k] = v; return m; });
      block = { attrs: next, replacedWith: null };
      return api;
    }
  };
  return api;
}

function $(arg) {
  if (typeof arg === 'function') { arg(); return; }
  if (arg === '.anchor-room-state') { return wrap(block); }
  return wrap(null);
}
$.ajax = function () {
  return {
    done(fn) { this._done = fn; return this; },
    fail(fn) {
      if (scenario.response === null) { fn(); } else { this._done(scenario.response); }
      return this;
    }
  };
};

const window = {
  ANCHOR_EVENTS_ROOM: { restUrl: '/wp-json/anchor-events/v1/events/', eventId: 1, nonce: 'n', pollSeconds: 300 },
  setInterval(fn) { intervals.push(fn); return intervals.length; },
  clearInterval() {}
};

vm.runInNewContext(fs.readFileSync(roomPath, 'utf8'), { window, jQuery: $, Date, Math, String, parseInt });

// room.js started on "DOM ready"; fire the live poll (or the countdown tick) once.
const last = intervals[intervals.length - 1];
if (last) { last(); }

process.stdout.write(JSON.stringify({ replaced, attrs: block.attrs }));
