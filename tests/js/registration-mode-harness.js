/*
 * Node harness for anchor-events-manager/assets/registration-mode.js (driven
 * by Test_External_Signup_Js). Usage:
 *   node registration-mode-harness.js <registration-mode.js path> '<cases JSON>'
 *   cases = [ { fn: 'effective'|'matches', args: [...] }, ... ]
 * Prints the results as a JSON array.
 */
'use strict';
const api = require(process.argv[2]);
const cases = JSON.parse(process.argv[3]);
process.stdout.write(JSON.stringify(cases.map((c) => api[c.fn].apply(null, c.args))));
