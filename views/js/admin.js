/**
 * JosraResetShop - pantalla de configuración
 * Prepara el plan en el servidor y lo ejecuta paso a paso (cada paso trabaja unos segundos y se repite si no ha acabado).
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var root = document.getElementById('jrs');
    if (!root) { return; }
    var ajax = root.getAttribute('data-ajax');
    var txt = {};
    try { txt = JSON.parse(document.getElementById('jrs-txt').textContent); } catch (e) { txt = {}; }

    var $ = function (sel, ctx) { return (ctx || root).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); };
    var chks = $$('.jrs-chk');
    var forced = {}; // sección -> [secciones que la obligan]
    var userOn = {};  // lo que marcó la persona
    var running = false;
    var fullLog = [];
    var errors = 0;

    function byKey(k) { return $('.jrs-chk[value="' + k + '"]'); }
    function labelOf(k) { var c = byKey(k); return c ? c.getAttribute('data-label') : k; }

    // dependencias: si una marcada necesita otra, la otra se marca sola, bloqueada y con el motivo
    function applyDeps() {
      forced = {};
      var changed = true;
      var on = {};
      chks.forEach(function (c) { if (userOn[c.value]) { on[c.value] = true; } });
      while (changed) {
        changed = false;
        Object.keys(on).forEach(function (k) {
          var c = byKey(k);
          var req = c ? (c.getAttribute('data-requires') || '') : '';
          req.split(',').filter(Boolean).forEach(function (need) {
            (forced[need] = forced[need] || []);
            if (forced[need].indexOf(k) === -1) { forced[need].push(k); }
            if (!on[need]) { on[need] = true; changed = true; }
          });
        });
      }
      chks.forEach(function (c) {
        var why = c.closest('.jrs-section').querySelector('.jrs-why');
        var f = forced[c.value];
        c.checked = !!on[c.value];
        c.disabled = running || !!(f && f.length);
        if (why) {
          if (f && f.length) {
            why.textContent = (txt.requiredBy || '%s').replace('%s', f.map(labelOf).join(', '));
            why.hidden = false;
          } else {
            why.hidden = true;
          }
        }
      });
      summary();
    }

    function selection() {
      return {
        sections: chks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
        tables: $$('.jrs-tbl').filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
        modules: $$('.jrs-psmod').filter(function (c) { return c.checked; }).map(function (c) { return c.value; })
      };
    }

    function summary() {
      var s = selection();
      var box = $('#jrs-summary');
      var parts = s.sections.map(labelOf);
      if (s.tables.length) { parts.push(s.tables.length + ' ' + (txt.tables || '')); }
      if (s.modules.length) { parts.push(s.modules.length + ' ' + (txt.uninstall || '')); }
      if (!parts.length) {
        box.className = 'alert alert-info';
        box.textContent = txt.nothing || '';
      } else {
        box.className = 'alert alert-warning';
        box.innerHTML = '';
        var strong = document.createElement('strong');
        strong.textContent = (txt.willDelete || '') + ' ';
        box.appendChild(strong);
        box.appendChild(document.createTextNode(parts.join(' · ')));
      }
      updateGo();
    }

    function updateGo() {
      var s = selection();
      var any = s.sections.length || s.tables.length || s.modules.length;
      $('#jrs-go').disabled = running || !any || $('#jrs-confirm').value.trim() !== 'BORRAR';
    }

    chks.forEach(function (c) {
      c.addEventListener('change', function () {
        userOn[c.value] = c.checked;
        applyDeps();
      });
    });
    root.addEventListener('change', function (e) {
      if (e.target.classList.contains('jrs-tbl') || e.target.classList.contains('jrs-psmod')) { summary(); }
    });
    $('#jrs-confirm').addEventListener('input', updateGo);
    $('[data-jrs-all]').addEventListener('click', function () {
      chks.forEach(function (c) { if (c.value !== 'thumbs') { userOn[c.value] = true; } });
      applyDeps();
    });
    $('[data-jrs-none]').addEventListener('click', function () {
      userOn = {};
      $$('.jrs-tbl').forEach(function (c) { c.checked = false; });
      applyDeps();
    });

    // -------------------------------------------------------------------
    // Ejecución
    // -------------------------------------------------------------------

    function post(action, data) {
      var body = new URLSearchParams();
      body.append('ajax', '1');
      body.append('action', action);
      body.append('confirm', $('#jrs-confirm').value.trim());
      Object.keys(data).forEach(function (k) {
        var v = data[k];
        if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); } else { body.append(k, v); }
      });
      return fetch(ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: body
      }).then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) {
            throw new Error('HTTP ' + r.status + ': ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
          }
        });
      });
    }

    function log(line, cls) {
      fullLog.push(line);
      var el = document.createElement('div');
      if (cls) { el.className = cls; }
      el.textContent = line;
      var box = $('#jrs-log');
      box.appendChild(el);
      box.scrollTop = box.scrollHeight;
    }

    function progress(i, total, label) {
      var pct = total ? Math.round(i * 100 / total) : 0;
      $('#jrs-bar').style.width = pct + '%';
      $('#jrs-pct').textContent = pct + '%';
      $('#jrs-now').textContent = label;
    }

    function setRunning(on) {
      running = on;
      $$('input', root).forEach(function (el) { if (el.id !== 'jrs-confirm') { el.disabled = on; } });
      $('#jrs-confirm').disabled = on;
      if (!on) { applyDeps(); }
      updateGo();
    }

    function finish(steps, stoppedMsg) {
      setRunning(false);
      var bar = $('#jrs-bar');
      bar.classList.remove('active', 'progress-bar-striped');
      var done = $('#jrs-done');
      done.hidden = false;
      done.className = 'alert ' + (stoppedMsg ? 'alert-danger' : (errors ? 'alert-warning' : 'alert-success'));
      done.textContent = (stoppedMsg ? stoppedMsg : (errors ? (txt.finishedErr || '').replace('%d', errors) : txt.finishedOk || '')) + ' ' + (txt.reload || '');
      bar.classList.add(stoppedMsg ? 'progress-bar-danger' : (errors ? 'progress-bar-warning' : 'progress-bar-success'));
      if (!stoppedMsg) { progress(steps.length, steps.length, done.textContent); }
      $('#jrs-dl').hidden = false;
      var stamp = new Date().toLocaleString();
      log('— ' + stamp);
    }

    function runSteps(steps, i, state, tries) {
      if (i >= steps.length) { finish(steps); return; }
      var step = steps[i];
      progress(i, steps.length, step.label);
      post('Step', { step: step.id, state: JSON.stringify(state || {}) }).then(function (r) {
        if (!r || !r.ok) {
          errors++;
          log('✗ ' + step.label + ': ' + ((r && r.error) || '?'), 'jrs-err');
          finish(steps, (r && r.error) || txt.stopped);
          return;
        }
        (r.log || []).forEach(function (l) { log(l, l.charAt(0) === '✓' ? 'jrs-ok' : ''); });
        (r.errors || []).forEach(function (l) { errors++; log(l, 'jrs-err'); });
        if (!r.done) {
          runSteps(steps, i, r.state, 0);
        } else {
          runSteps(steps, i + 1, {}, 0);
        }
      }).catch(function (err) {
        // un corte de red: se reintenta el mismo paso un par de veces (lo borrado no se repite)
        if (tries < 2) {
          log('… ' + err.message + ' — reintentando', 'jrs-warn');
          setTimeout(function () { runSteps(steps, i, state, tries + 1); }, 3000);
          return;
        }
        errors++;
        log('✗ ' + step.label + ': ' + err.message, 'jrs-err');
        finish(steps, txt.stopped);
      });
    }

    $('#jrs-go').addEventListener('click', function () {
      if (running || !window.confirm(txt.confirmJs || '?')) { return; }
      var s = selection();
      fullLog = [];
      errors = 0;
      $('#jrs-log').innerHTML = '';
      $('#jrs-done').hidden = true;
      $('#jrs-dl').hidden = true;
      var bar = $('#jrs-bar');
      bar.className = 'progress-bar progress-bar-striped active';
      $('#jrs-progress').hidden = false;
      setRunning(true);
      progress(0, 1, txt.planning || '');
      $('#jrs-progress').scrollIntoView({ behavior: 'smooth', block: 'start' });
      post('Plan', s).then(function (r) {
        if (!r || !r.ok) {
          log('✗ ' + ((r && r.error) || '?'), 'jrs-err');
          errors++;
          finish([], (r && r.error) || txt.stopped);
          return;
        }
        log('JosraResetShop · ' + new Date().toLocaleString());
        runSteps(r.steps, 0, {}, 0);
      }).catch(function (err) {
        log('✗ ' + err.message, 'jrs-err');
        errors++;
        finish([], txt.stopped);
      });
    });

    $('#jrs-dl').addEventListener('click', function () {
      var blob = new Blob([fullLog.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'josraresetshop_' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.txt';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.parentNode.removeChild(a); }, 500);
    });

    applyDeps();
  });
}());
