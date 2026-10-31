// Lógica pura do Toppernote (§33): node tests/cc_logic_test.js
const CCL = require('../admin/assets/cc_logic.js');
let ok = 0, fail = 0;
const eq = (a, b, msg) => { const A = JSON.stringify(a), Bv = JSON.stringify(b); if (A === Bv) ok++; else { fail++; console.error('FALHA: ' + msg + '\n  esperado ' + Bv + '\n  obtido   ' + A); } };

eq(CCL.version, 1, 'CCL.version === 1');

console.log(fail ? `FALHOU ${fail} de ${ok + fail}` : `OK ${ok}`);
process.exit(fail ? 1 : 0);
