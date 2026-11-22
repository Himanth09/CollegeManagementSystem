/* Reads server-provided settings from data attributes (keeps CSP free of inline scripts). */
(function () {
  var el = document.getElementById('boot');
  window.CMS_APP = {
    college: el ? el.getAttribute('data-college') : '',
    name: el ? el.getAttribute('data-name') : 'College Management System',
    short: 'CMS',
  };
})();
