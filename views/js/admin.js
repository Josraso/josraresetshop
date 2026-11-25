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
    var userOn = {};
    var running = false;
    var fullLog = [];
    var errors = 0;

    function mode() { var r = $('input[name="jrs_mode"]:checked'); return r ? r.value : 'sections'; }
    function demo() { var r = $('#jrs_demo_on'); return !!(r && r.checked); }
    function byKey(k) { return $('.jrs-chk[value="' + k + '"]'); }
    function labelOf(k) { var c = byKey(k); return c ? c.getAttribute('data-label') : k; }
    function card(c) { return c.closest('.jrs-card'); }

    // ------------------------------------------------------------------
    // Marcar secciones (dependencias, modo «de fábrica»)
    // ------------------------------------------------------------------

    function applyDeps() {
      var factory = mode() === 'factory';
      var on = {};
      var forced = {};
      chks.forEach(function (c) {
        if (factory) {
          if (c.getAttribute('data-group') === 'data') { on[c.value] = true; }
        } else if (userOn[c.value]) {
          on[c.value] = true;
        }
      });
      var changed = true;
      while (changed && !factory) {
        changed = false;
        Object.keys(on).forEach(function (k) {
          var c = byKey(k);
          (c ? (c.getAttribute('data-requires') || '') : '').split(',').filter(Boolean).forEach(function (need) {
            forced[need] = forced[need] || [];
            if (forced[need].indexOf(k) === -1) { forced[need].push(k); }
            if (!on[need]) { on[need] = true; changed = true; }
          });
        });
      }
      chks.forEach(function (c) {
        var el = card(c);
        var why = el.querySelector('.jrs-why');
        var f = forced[c.value];
        var locked = factory ? c.getAttribute('data-group') === 'data' : !!(f && f.length);
        c.checked = !!on[c.value];
        c.disabled = running || locked || (factory && c.getAttribute('data-group') !== 'data');
        el.classList.toggle('is-on', c.checked);
        el.classList.toggle('is-locked', c.disabled);
        el.style.display = factory && c.getAttribute('data-group') !== 'data' ? 'none' : '';
        if (factory) {
          why.textContent = txt.lockedFactory || '';
          why.hidden = false;
        } else if (f && f.length) {
          why.textContent = (txt.requiredBy || '%s').replace('%s', f.map(labelOf).join(', '));
          why.hidden = false;
        } else {
          why.hidden = true;
        }
      });
      $$('.jrs-mode').forEach(function (m) { m.classList.toggle('is-on', !!m.querySelector('input:checked')); });
      $('#jrs-factory').hidden = !factory;
      $('[data-jrs-all]').style.visibility = factory ? 'hidden' : '';
      $('[data-jrs-none]').style.visibility = factory ? 'hidden' : '';
      $('#jrs-go-txt').textContent = factory ? (txt.factoryLabel || '') : (root.getAttribute('data-go') || $('#jrs-go-txt').getAttribute('data-def') || $('#jrs-go-txt').textContent);
      moduleBadges();
      summary();
    }

    function moduleBadges() {
      $$('.jrs-mod').forEach(function (d) {
        var n = $$('.jrs-tbl', d).filter(function (c) { return c.checked; }).length;
        var b = d.querySelector('.jrs-mod-sel');
        b.hidden = !n;
        b.textContent = n ? '✓ ' + n : '';
        var all = d.querySelector('.jrs-tbl-all');
        if (all) { all.checked = n > 0 && n === $$('.jrs-tbl', d).length; }
      });
    }

    function selection() {
      var sections = chks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
      if (mode() === 'factory') { sections.unshift('factory'); }
      return {
        sections: sections,
        tables: $$('.jrs-tbl').filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
        modules: $$('.jrs-psmod').filter(function (c) { return c.checked; }).map(function (c) { return c.value; }),
        demo: mode() === 'factory' && demo() ? '1' : '0'
      };
    }

    function summary() {
      var s = selection();
      var box = $('#jrs-summary');
      var parts;
      if (mode() === 'factory') {
        parts = [(txt.factoryLabel || '') + (demo() ? ' (' + (txt.demoYes || '') + ')' : '')];
      } else {
        parts = s.sections.map(labelOf);
      }
      if (s.tables.length) { parts.push(s.tables.length + ' ' + (txt.tables || '')); }
      if (s.modules.length) { parts.push(s.modules.length + ' ' + (txt.uninstall || '')); }
      if (!parts.length) {
        box.className = 'alert alert-info';
        box.textContent = txt.nothing || '';
      } else {
        box.className = 'alert ' + (mode() === 'factory' ? 'alert-danger' : 'alert-warning');
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

    $('#jrs-go-txt').setAttribute('data-def', $('#jrs-go-txt').textContent);
    chks.forEach(function (c) {
      c.addEventListener('change', function () { userOn[c.value] = c.checked; applyDeps(); });
    });
    $$('input[name="jrs_mode"], input[name="jrs_demo"]').forEach(function (r) { r.addEventListener('change', applyDeps); });
    root.addEventListener('change', function (e) {
      var t = e.target;
      if (t.classList.contains('jrs-tbl-all')) {
        $$('.jrs-tbl', t.closest('.jrs-mod')).forEach(function (c) { c.checked = t.checked; });
      }
      if (t.classList.contains('jrs-tbl') || t.classList.contains('jrs-tbl-all') || t.classList.contains('jrs-psmod')) {
        moduleBadges();
        summary();
      }
    });
    $('#jrs-confirm').addEventListener('input', updateGo);
    $('[data-jrs-all]').addEventListener('click', function (e) {
      e.preventDefault();
      chks.forEach(function (c) { if (c.getAttribute('data-group') === 'data') { userOn[c.value] = true; } });
      applyDeps();
    });
    $('[data-jrs-none]').addEventListener('click', function (e) {
      e.preventDefault();
      userOn = {};
      $$('.jrs-tbl').forEach(function (c) { c.checked = false; });
      applyDeps();
    });

    // ------------------------------------------------------------------
    // Ejecución
    // ------------------------------------------------------------------

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

    function stepIcon(li, state) {
      var icons = { wait: 'radio_button_unchecked', run: 'autorenew', ok: 'check_circle', err: 'error' };
      li.className = 'is-' + state;
      li.querySelector('.material-icons').textContent = icons[state];
    }

    function progress(i, total, label) {
      var pct = total ? Math.round(i * 100 / total) : 0;
      $('#jrs-bar').style.width = pct + '%';
      $('#jrs-pct').textContent = pct + '%';
      $('#jrs-now').textContent = label;
      $('#jrs-count').textContent = total ? (txt.stepsDone || '%1$d / %2$d').replace('%1$d', i).replace('%2$d', total) : '';
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
      if (!stoppedMsg) { progress(steps.length, steps.length, txt.finished || '✓'); } else { $('#jrs-now').textContent = '✗'; }
      $('#jrs-dl').hidden = false;
      log('— ' + new Date().toLocaleString());
    }

    // una línea por sección: se marca cuando acaba su último paso
    function groupLi(step) { return $('#jrs-steps').querySelector('li[data-g="' + groupIdx[step.group] + '"]'); }
    var groupIdx = {};
    var groupLast = {};

    function runSteps(steps, i, state, tries) {
      if (i >= steps.length) { finish(steps); return; }
      var step = steps[i];
      var li = groupLi(step);
      if (!li.classList.contains('is-err')) { stepIcon(li, 'run'); }
      progress(i, steps.length, step.group === step.label ? step.label : step.group + ' · ' + step.label);
      post('Step', { step: step.id, state: JSON.stringify(state || {}) }).then(function (r) {
        if (!r || !r.ok) {
          errors++;
          stepIcon(li, 'err');
          log('✗ ' + step.label + ': ' + ((r && r.error) || '?'), 'jrs-err');
          finish(steps, (r && r.error) || txt.stopped);
          return;
        }
        (r.log || []).forEach(function (l) { log(l, l.charAt(0) === '✓' ? 'jrs-ok' : ''); });
        (r.errors || []).forEach(function (l) { errors++; log(l, 'jrs-err'); });
        if (!r.done) {
          runSteps(steps, i, r.state, 0);
          return;
        }
        if ((r.errors || []).length) { stepIcon(li, 'err'); } else if (groupLast[step.group] === i && !li.classList.contains('is-err')) { stepIcon(li, 'ok'); }
        // si falta lo imprescindible para «de fábrica», no se sigue borrando
        if ((r.errors || []).length && step.id === 'factory.installer') {
          finish(steps, r.errors[0]);
          return;
        }
        runSteps(steps, i + 1, {}, 0);
      }).catch(function (err) {
        if (tries < 2) {
          log('… ' + err.message + ' — reintentando', 'jrs-warn');
          setTimeout(function () { runSteps(steps, i, state, tries + 1); }, 3000);
          return;
        }
        errors++;
        stepIcon(li, 'err');
        log('✗ ' + step.label + ': ' + err.message, 'jrs-err');
        finish(steps, txt.stopped);
      });
    }

    $('#jrs-go').addEventListener('click', function () {
      var factory = mode() === 'factory';
      if (running || !window.confirm((factory ? txt.confirmFactory : txt.confirmJs) || '?')) { return; }
      var s = selection();
      fullLog = [];
      errors = 0;
      $('#jrs-log').innerHTML = '';
      $('#jrs-steps').innerHTML = '';
      $('#jrs-done').hidden = true;
      $('#jrs-dl').hidden = true;
      $('#jrs-bar').className = 'progress-bar progress-bar-striped active';
      $('#jrs-progress').hidden = false;
      setRunning(true);
      progress(0, 0, txt.planning || '');
      $('#jrs-progress').scrollIntoView({ behavior: 'smooth', block: 'start' });
      post('Plan', s).then(function (r) {
        if (!r || !r.ok) {
          log('✗ ' + ((r && r.error) || '?'), 'jrs-err');
          errors++;
          finish([], (r && r.error) || txt.stopped);
          return;
        }
        log('JosraResetShop · ' + new Date().toLocaleString());
        groupIdx = {};
        groupLast = {};
        r.steps.forEach(function (st, n) {
          groupLast[st.group] = n;
          if (groupIdx[st.group] !== undefined) { return; }
          groupIdx[st.group] = Object.keys(groupIdx).length;
          var li = document.createElement('li');
          li.setAttribute('data-g', groupIdx[st.group]);
          var ico = document.createElement('i');
          ico.className = 'material-icons';
          li.appendChild(ico);
          li.appendChild(document.createTextNode(st.group));
          $('#jrs-steps').appendChild(li);
          stepIcon(li, 'wait');
        });
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

    chks.forEach(function (c) { if (c.closest('.jrs-card').querySelector('.jrs-card__num') && c.closest('.jrs-card').querySelector('.jrs-card__num').textContent.trim() === '0') { c.closest('.jrs-card').classList.add('is-empty'); } });
    applyDeps();
  });
}());
