/*
 * BKL Registrace – vložení formuláře do jiného webu (WordPress / Elementor).
 * Použití:
 *   <div data-bkl-registrace="zimni-beh-2027"></div>
 *   <script src="__APP_URL__/embed.js" async></script>
 * Volitelně data-view="startovka" pro startovní listinu.
 */
(function () {
  var APP = '__APP_URL__';
  var ORIGIN = APP.replace(/^(https?:\/\/[^\/]+).*$/, '$1');
  var FALLBACK_MS = 10000;
  function init(box) {
    if (box.getAttribute('data-bkl-ready')) return;
    box.setAttribute('data-bkl-ready', '1');
    var slug = box.getAttribute('data-bkl-registrace');
    var view = box.getAttribute('data-view') === 'startovka' ? '/startovka' : '';
    var src = APP + '/' + encodeURIComponent(slug) + view + '?embed=1';
    var iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = 'Registrace na závod';
    iframe.setAttribute('allow', 'clipboard-write');
    iframe.style.cssText = 'width:100%;border:0;display:block;min-height:480px;overflow:hidden';
    iframe.setAttribute('scrolling', 'no');
    var note = document.createElement('div');
    note.style.cssText = 'display:none;padding:16px;border:1px solid #e3dedc;border-radius:12px;font:16px/1.5 system-ui,sans-serif;background:#fff';
    note.innerHTML = 'Registrační systém je momentálně nedostupný. Zkuste to prosím později, nebo otevřete <a href="' + APP + '/' + encodeURIComponent(slug) + '" target="_blank" rel="noopener">registraci v novém okně</a>.';
    box.innerHTML = '';
    box.appendChild(iframe);
    box.appendChild(note);
    var alive = false;
    var timer = setTimeout(function () {
      if (!alive) { iframe.style.display = 'none'; note.style.display = 'block'; }
    }, FALLBACK_MS);
    window.addEventListener('message', function (ev) {
      if (ev.origin !== ORIGIN || ev.source !== iframe.contentWindow || !ev.data || typeof ev.data !== 'object') return;
      if (ev.data.bkl === 'ready' || ev.data.bkl === 'height') {
        alive = true; clearTimeout(timer);
        iframe.style.display = 'block'; note.style.display = 'none';
        var h = parseInt(ev.data.h, 10);
        if (h > 100 && h < 20000) iframe.style.height = h + 'px';
        if (ev.data.scroll) iframe.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  }
  function run() { document.querySelectorAll('[data-bkl-registrace]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
