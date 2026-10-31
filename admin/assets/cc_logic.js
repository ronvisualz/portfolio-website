/* topper. admin — Toppernote: lógica pura dos Topperboards (§33), sem DOM. No browser fica em window.CCL; no Node em
   module.exports (tests/cc_logic_test.js). cc.js liga estas funções aos eventos. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.CCL = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  return { version: 1 };
});
