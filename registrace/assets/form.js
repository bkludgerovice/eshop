/* Registrační formulář: přidávání závodníků, živý výpočet ceny, zobrazení souhlasu rodiče. Bez JS formulář funguje pro 1 závodníka. */
(function () {
  var form = document.getElementById('regform');
  if (!form) return;
  var list = document.getElementById('runners');
  var tpl = document.getElementById('runner-tpl');
  var addBtn = document.getElementById('add-runner');
  var max = parseInt(form.dataset.max || '10', 10);
  var nextIndex = list.querySelectorAll('.runner').length;

  function runners() { return list.querySelectorAll('.runner'); }

  function renumber() {
    var rs = runners();
    rs.forEach(function (r, i) {
      r.querySelector('.runner-no').textContent = i + 1;
      var rm = r.querySelector('.remove-runner');
      rm.hidden = rs.length < 2;
    });
    addBtn.hidden = rs.length >= max;
  }

  addBtn && addBtn.addEventListener('click', function () {
    if (runners().length >= max) return;
    var html = tpl.innerHTML.replace(/__I__/g, String(nextIndex++));
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var el = wrap.firstElementChild;
    // nový závodník převezme klub a zemi od prvního (často rodina / oddíl)
    var first = runners()[0];
    if (first) {
      ['club', 'country'].forEach(function (k) {
        var src = first.querySelector('[name$="[' + k + ']"]'), dst = el.querySelector('[name$="[' + k + ']"]');
        if (src && dst) dst.value = src.value;
      });
    }
    list.appendChild(el);
    renumber(); bind(el); recalc();
    var inp = el.querySelector('[name$="[first_name]"]'); if (inp) inp.focus();
  });

  list.addEventListener('click', function (ev) {
    var b = ev.target.closest('.remove-runner');
    if (!b) return;
    b.closest('.runner').remove();
    renumber(); recalc();
  });

  function birthDate(r) {
    var d = r.querySelector('.bd-d').value, m = r.querySelector('.bd-m').value, y = r.querySelector('.bd-y').value;
    if (!/^\d{4}$/.test(y) || !m || !/^\d{1,2}$/.test(d)) return null;
    return y + '-' + ('0' + m).slice(-2) + '-' + ('0' + d).slice(-2);
  }

  function guardian(r) {
    var g = r.querySelector('.guardian');
    if (!g) return;
    var bd = birthDate(r);
    if (!bd) return;
    var ev = new Date(g.dataset.eventDate + 'T00:00:00'), b = new Date(bd + 'T00:00:00');
    var age = ev.getFullYear() - b.getFullYear() - ((ev.getMonth() < b.getMonth() || (ev.getMonth() === b.getMonth() && ev.getDate() < b.getDate())) ? 1 : 0);
    var minor = age < parseInt(g.dataset.guardianAge, 10);
    g.classList.toggle('maybe', !minor);
    g.querySelectorAll('input').forEach(function (i) { i.required = minor; });
  }

  function raceFields(r) {
    var sel = r.querySelector('input[name$="[race_id]"]:checked');
    var race = sel ? sel.value : '';
    r.querySelectorAll('.custom[data-race]').forEach(function (f) {
      var show = f.dataset.race === race;
      f.hidden = !show;
      f.querySelectorAll('input,select').forEach(function (i) { i.disabled = !show; });
    });
  }

  function bind(r) {
    r.addEventListener('input', function (ev) {
      if (ev.target.matches('.bd-d,.bd-m,.bd-y')) guardian(r);
      schedule();
    });
    r.addEventListener('change', function () { raceFields(r); guardian(r); schedule(); });
    raceFields(r); guardian(r);
  }

  // Příspěvek: tlačítka s částkou ↔ pole
  var charity = document.getElementById('charity');
  form.querySelectorAll('input[name="charity_pick"]').forEach(function (rb) {
    rb.addEventListener('change', function () {
      if (!charity) return;
      if (rb.value === 'vlastni') { charity.value = ''; charity.focus(); } else charity.value = rb.value;
      schedule();
    });
  });
  if (charity) charity.addEventListener('input', schedule);

  // Živý výpočet ceny (server je autorita, tady jen náhled)
  var t = null, seq = 0;
  function schedule() { clearTimeout(t); t = setTimeout(recalc, 350); }
  function collect() {
    var data = { runners: [], charity: charity ? charity.value : '0' };
    runners().forEach(function (r) {
      var o = { fields: {} };
      var race = r.querySelector('input[name$="[race_id]"]:checked');
      o.race_id = race ? race.value : '';
      o.birth_date = birthDate(r) || '';
      var c = r.querySelector('[name$="[coupon]"]'); o.coupon = c ? c.value : '';
      r.querySelectorAll('[name*="[fields]"]').forEach(function (inp) {
        if (inp.disabled) return;
        var m = inp.name.match(/\[fields\]\[(\d+)\](\[\])?$/); if (!m) return;
        var id = m[1];
        if (inp.type === 'checkbox') {
          if (m[2]) { o.fields[id] = o.fields[id] || []; if (inp.checked) o.fields[id].push(inp.value); }
          else if (inp.checked) o.fields[id] = '1';
        } else if (inp.value) o.fields[id] = inp.value;
      });
      data.runners.push(o);
    });
    return data;
  }
  function recalc() {
    var my = ++seq;
    fetch(form.dataset.priceUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(collect()) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (my !== seq || !d.ok) return;
        document.getElementById('total').textContent = d.fmt.total;
        runners().forEach(function (r, i) {
          var l = d.lines[i]; if (!l) return;
          r.querySelector('.line-price').textContent = d.fmt.lines[i];
          r.querySelector('.discount-note').textContent = l.discount > 0 ? (l.source === 'kupon' ? 'sleva podle kódu' : 'věková sleva ' + l.percent + ' %') : '';
          var msg = r.querySelector('.coupon-msg'), err = d.errors && d.errors['runners.' + i + '.coupon'];
          if (msg) { msg.hidden = !err; msg.textContent = err || ''; }
        });
        if (window.bklResize) window.bklResize();
      })
      .catch(function () {});
  }

  form.addEventListener('submit', function (ev) {
    // jednoduchá kontrola povinných polí před odesláním
    var bad = null;
    form.querySelectorAll('[required]').forEach(function (i) {
      if (i.disabled || i.closest('[hidden]') || i.closest('.maybe')) return;
      var ok = i.type === 'checkbox' ? i.checked : (i.type === 'radio' ? !!form.querySelector('input[name="' + CSS.escape(i.name) + '"]:checked') : i.value.trim() !== '');
      var f = i.closest('.field'); if (f) f.classList.toggle('invalid', !ok);
      if (!ok && !bad) bad = i;
    });
    if (bad) { ev.preventDefault(); bad.focus(); bad.scrollIntoView({ block: 'center' }); return; }
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Odesílám…';
  });

  runners().forEach(bind);
  renumber();
  recalc();
})();
