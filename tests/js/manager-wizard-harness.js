// Runs manager-wizard.js against a tiny fake DOM. Argv: script path.
// Prints JSON: whether Next advanced past step 1 with an invalid field inside a hidden wrapper.
var fs = require('fs');
function el(attrs, kids) {
  var e = { attrs: attrs || {}, style: {}, kids: kids || [], parentNode: null, listeners: {}, classList: { add() {}, remove() {}, toggle: function (c, off) { e.off = off; }, contains: function () { return false; } },
    getAttribute: function (k) { return this.attrs[k] === undefined ? null : this.attrs[k]; }, setAttribute() {}, removeAttribute() {},
    addEventListener: function (t, f) { this.listeners[t] = f; }, querySelector: function () { return null; },
    querySelectorAll: function (sel) { var o = []; (function w(n) { n.kids.forEach(function (k) { if (sel.indexOf('[data-step]') === 0 ? k.attrs['data-step'] : (k.isField)) { o.push(k); } w(k); }); })(this); return o; },
    closest: function () { return null; }, appendChild() {}, removeChild() {}, scrollIntoView() {}, focus() {} };
  e.kids.forEach(function (k) { k.parentNode = e; });
  return e;
}
function field(valid) { var f = el(); f.isField = true; f.disabled = false; f.type = 'text'; f.checkValidity = function () { return valid; }; return f; }
var bad = field(false), wrapper = el({}, [bad]); wrapper.style.display = 'none';
var s1 = el({ 'data-step': '1' }, [wrapper]), s2 = el({ 'data-step': '2' });
var back = el(), next = el(), nav = el();
var form = el({}, [s1, s2, back, next, nav]);
var map = { '.anchor-event-wizard-nav': nav, '[data-wizard-back]': back, '[data-wizard-next]': next };
form.querySelector = function (sel) { return map[sel] || null; };
var qs = form.querySelectorAll; form.querySelectorAll = function (sel) { return sel === '[data-step]' ? [s1, s2] : qs.call(this, sel); };
form.scrollIntoView = function () {};
var ready; global.document = { createElement: function () { return el(); }, addEventListener: function (t, f) { ready = f; }, querySelectorAll: function () { return [form]; } };
// the step walker queries fields per section
[s1, s2].forEach(function (s) { s.querySelectorAll = function () { var o = []; (function w(n) { n.kids.forEach(function (k) { if (k.isField) { o.push(k); } w(k); }); })(s); return o; }; });
global.window = global;
eval(fs.readFileSync(process.argv[2], 'utf8'));
ready();
next.listeners.click();
console.log(JSON.stringify({ advanced: s2.off === false }));
