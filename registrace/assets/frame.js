/* Ve vloženém iframe posílá rodičovské stránce výšku obsahu (jen číslo, žádná osobní data). */
(function () {
  if (window.parent === window) return;
  var last = 0;
  function send(type) {
    var h = Math.ceil(document.documentElement.scrollHeight);
    if (type === 'height' && h === last) return;
    last = h;
    window.parent.postMessage({ bkl: type, h: h, path: location.pathname }, '*');
  }
  window.bklResize = function () { send('height'); };
  window.addEventListener('load', function () { send('ready'); send('height'); });
  if ('ResizeObserver' in window) new ResizeObserver(function () { send('height'); }).observe(document.body);
  else setInterval(function () { send('height'); }, 500);
  // Po odeslání formuláře posuň rodičovskou stránku na začátek iframe
  window.parent.postMessage({ bkl: 'ready', h: document.documentElement.scrollHeight, path: location.pathname, scroll: /moje\//.test(location.pathname) }, '*');
})();
