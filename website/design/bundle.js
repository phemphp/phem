/* Phem Docs — behaviour for static docs pages. Plain JavaScript, no dependencies, no network.
   Everything is wired by data attributes through event delegation, so markup added later works too.
   Public API: window.PhemDocs = { init, initTheme, setTheme, toggleTheme, openSearch, closeSearch, selectTab, version } */
(function () {
  'use strict';
  var doc = document;
  var CHECK_PATH = 'M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z';

  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } }
  };
  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }
  function each(list, fn) { for (var i = 0; i < list.length; i++) fn(list[i], i); }
  function esc(v) { return window.CSS && window.CSS.escape ? window.CSS.escape(v) : String(v).replace(/"/g, '\\"'); }
  function isTyping(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)); }

  /* ---------- Theme ---------- */
  function resolve(mode) {
    if (mode === 'light' || mode === 'dark') return mode;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function setTheme(mode) {
    mode = mode === 'light' || mode === 'dark' ? mode : 'system';
    store.set('ph-theme', mode);
    doc.documentElement.setAttribute('data-theme', resolve(mode));
    doc.documentElement.setAttribute('data-theme-mode', mode);
  }
  /* One button switches between light and dark: the opposite of the theme on screen. */
  function toggleTheme() {
    setTheme(doc.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  }
  /* Call once, as early as possible (end of <head>), to apply the saved choice before paint. */
  function initTheme() {
    var mode = store.get('ph-theme') || 'system';
    doc.documentElement.setAttribute('data-theme', resolve(mode));
    doc.documentElement.setAttribute('data-theme-mode', mode);
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      var onChange = function () { if ((doc.documentElement.getAttribute('data-theme-mode') || 'system') === 'system') doc.documentElement.setAttribute('data-theme', resolve('system')); };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
    }
  }

  /* ---------- Tabs (generic and code tabs) ---------- */
  function tabsOf(list) { return list ? list.querySelectorAll('[role="tab"]') : []; }
  function selectTab(tab, opts) {
    opts = opts || {};
    var list = closest(tab, '[role="tablist"]');
    if (!list) return;
    each(tabsOf(list), function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      var panel = doc.getElementById(t.getAttribute('aria-controls'));
      if (panel) panel.hidden = !on;
    });
    if (opts.focus) tab.focus();
    var root = closest(list, '[data-ph-tabs]');
    var group = root && root.getAttribute('data-ph-tabs');
    if (!group || opts.sync === false) return;
    var value = tab.getAttribute('data-value') || tab.textContent.trim();
    store.set('ph-tabs:' + group, value);
    each(doc.querySelectorAll('[data-ph-tabs="' + esc(group) + '"]'), function (other) {
      if (other === root) return;
      var match = other.querySelector('[role="tab"][data-value="' + esc(value) + '"]');
      if (match) selectTab(match, { sync: false });
    });
  }
  function onTabKey(e) {
    var tab = closest(e.target, '[role="tab"]');
    if (!tab) return;
    var tabs = Array.prototype.slice.call(tabsOf(closest(tab, '[role="tablist"]')));
    var i = tabs.indexOf(tab), next = null;
    if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
    else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
    else if (e.key === 'Home') next = tabs[0];
    else if (e.key === 'End') next = tabs[tabs.length - 1];
    if (next) { e.preventDefault(); selectTab(next, { focus: true }); }
  }

  /* ---------- Copy ---------- */
  function codeText(btn) {
    var literal = btn.getAttribute('data-ph-copy');
    if (literal) return literal;
    var root = closest(btn, '.ph-code');
    if (!root) return '';
    var codes = root.querySelectorAll('pre code');
    for (var i = 0; i < codes.length; i++) {
      var panel = closest(codes[i], '[role="tabpanel"]');
      if (!panel || !panel.hidden) {
        var clone = codes[i].cloneNode(true);
        each(clone.querySelectorAll('.line--del'), function (l) { l.parentNode.removeChild(l); });
        var lines = clone.querySelectorAll('.line');
        if (lines.length) return Array.prototype.map.call(lines, function (l) { return l.textContent; }).join('\n');
        return clone.textContent;
      }
    }
    return '';
  }
  function fallbackCopy(text) {
    var ta = doc.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    doc.body.appendChild(ta); ta.select();
    try { doc.execCommand('copy'); } catch (e) { /* ignore */ }
    doc.body.removeChild(ta);
  }
  function copy(btn) {
    var text = codeText(btn);
    var done = function () {
      if (btn.__phTimer) clearTimeout(btn.__phTimer); else btn.__phHtml = btn.innerHTML;
      var label = btn.getAttribute('aria-label');
      if (label && !btn.__phLabel) btn.__phLabel = label;
      btn.classList.add('is-copied');
      btn.setAttribute('aria-label', 'Copied');
      var svg = btn.querySelector('svg');
      if (svg) svg.innerHTML = '<path fill-rule="evenodd" clip-rule="evenodd" d="' + CHECK_PATH + '"/>';
      btn.__phTimer = setTimeout(function () {
        btn.innerHTML = btn.__phHtml; btn.classList.remove('is-copied');
        if (btn.__phLabel) btn.setAttribute('aria-label', btn.__phLabel);
        btn.__phTimer = null;
      }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
    } else { fallbackCopy(text); done(); }
  }

  /* ---------- Dialogs: search + drawer ---------- */
  function openDialog(dlg) {
    if (!dlg || dlg.open) return;
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
  }
  function closeDialog(dlg) {
    if (!dlg) return;
    if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
  }
  function searchDialog() { return doc.querySelector('[data-ph-search]'); }
  function visibleItems(dlg) { return Array.prototype.filter.call(dlg.querySelectorAll('[data-ph-search-item]'), function (i) { return !i.hidden; }); }
  function setActive(dlg, item) {
    each(dlg.querySelectorAll('[data-ph-search-item]'), function (i) { i.setAttribute('aria-selected', String(i === item)); });
    var input = dlg.querySelector('[data-ph-search-input]');
    if (item) {
      if (!item.id) item.id = 'ph-sr-' + Math.random().toString(36).slice(2, 8);
      if (input) input.setAttribute('aria-activedescendant', item.id);
      if (item.scrollIntoView) item.scrollIntoView({ block: 'nearest' });
    } else if (input) input.removeAttribute('aria-activedescendant');
  }
  function filterSearch(dlg, q) {
    q = (q || '').trim().toLowerCase();
    each(dlg.querySelectorAll('[data-ph-search-item]'), function (item) {
      var hay = (item.getAttribute('data-ph-search-item') || '') + ' ' + item.textContent;
      item.hidden = !!q && hay.toLowerCase().indexOf(q) === -1;
    });
    each(dlg.querySelectorAll('[data-ph-search-group]'), function (g) {
      g.hidden = !g.querySelector('[data-ph-search-item]:not([hidden])');
    });
    var vis = visibleItems(dlg);
    var empty = dlg.querySelector('[data-ph-search-empty]');
    if (empty) {
      empty.hidden = vis.length > 0;
      var echo = empty.querySelector('[data-ph-search-query]');
      if (echo) echo.textContent = q;
    }
    setActive(dlg, vis[0] || null);
  }
  function openSearch() {
    var dlg = searchDialog();
    if (!dlg) return;
    openDialog(dlg);
    var input = dlg.querySelector('[data-ph-search-input]');
    if (input) { input.focus(); input.select(); filterSearch(dlg, input.value); }
  }
  function closeSearch() { closeDialog(searchDialog()); }
  function onSearchKey(e, dlg) {
    var vis = visibleItems(dlg);
    var cur = dlg.querySelector('[data-ph-search-item][aria-selected="true"]');
    var i = vis.indexOf(cur);
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(dlg, vis[Math.min(i + 1, vis.length - 1)] || null); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(dlg, vis[Math.max(i - 1, 0)] || null); }
    else if (e.key === 'Enter' && cur) { e.preventDefault(); cur.click(); }
  }

  /* ---------- Table of contents: highlight the section in view ---------- */
  function initToc(toc) {
    if (toc.__lw) return;
    toc.__lw = true;
    var links = toc.querySelectorAll('a[href^="#"]');
    var heads = [];
    each(links, function (a) {
      var id = decodeURIComponent(a.getAttribute('href').slice(1));
      var el = id && doc.getElementById(id);
      if (el) heads.push({ el: el, link: a });
    });
    if (!heads.length) return;
    var ticking = false;
    function update() {
      ticking = false;
      // A heading is current from the place where a click on its link scrolls it to: its
      // scroll-margin-top (the header and the banner above it).
      var fallback = (parseInt(getComputedStyle(doc.documentElement).getPropertyValue('--header-height'), 10) || 64) + 24;
      var current = heads[0];
      for (var i = 0; i < heads.length; i++) {
        var offset = parseFloat(getComputedStyle(heads[i].el).scrollMarginTop) || fallback;
        if (heads[i].el.getBoundingClientRect().top - offset <= 1) current = heads[i];
      }
      if ((window.innerHeight + window.scrollY) >= doc.documentElement.scrollHeight - 2) current = heads[heads.length - 1];
      each(heads, function (h) { if (h === current) h.link.setAttribute('aria-current', 'true'); else h.link.removeAttribute('aria-current'); });
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }, { passive: true });
    update();
  }

  /* ---------- Banner ---------- */
  function initBanners() {
    each(doc.querySelectorAll('[data-ph-banner]'), function (b) {
      var key = b.getAttribute('data-ph-banner');
      if (key && b.hasAttribute('data-ph-persist') && store.get('ph-dismiss:' + key)) b.hidden = true;
    });
  }

  /* ---------- Init + delegation ---------- */
  function init(root) {
    root = root || doc;
    each(root.querySelectorAll('[data-ph-tabs]'), function (set) {
      var group = set.getAttribute('data-ph-tabs');
      var saved = group && store.get('ph-tabs:' + group);
      var tab = saved && set.querySelector('[role="tab"][data-value="' + esc(saved) + '"]');
      if (tab) selectTab(tab, { sync: false });
    });
    each(root.querySelectorAll('[data-ph-toc]'), initToc);
    initBanners();
  }

  doc.addEventListener('click', function (e) {
    var t = e.target, el;
    if (closest(t, '[data-ph-theme-toggle]')) { toggleTheme(); return; }
    if ((el = closest(t, '[role="tab"]')) && closest(el, '[data-ph-tabs]')) { selectTab(el); return; }
    if ((el = closest(t, '[data-ph-copy], .ph-code__copy'))) { copy(el); return; }
    if ((el = closest(t, '[data-ph-search-open]'))) { e.preventDefault(); openSearch(); return; }
    if ((el = closest(t, '[data-ph-drawer-open]'))) { openDialog(doc.getElementById(el.getAttribute('data-ph-drawer-open'))); return; }
    if ((el = closest(t, '[data-ph-close]'))) { closeDialog(closest(el, 'dialog')); return; }
    if ((el = closest(t, '[data-ph-dismiss]'))) {
      var banner = closest(el, '[data-ph-banner]');
      if (banner) {
        banner.hidden = true;
        if (banner.hasAttribute('data-ph-persist')) store.set('ph-dismiss:' + banner.getAttribute('data-ph-banner'), '1');
      }
      return;
    }
    if ((el = closest(t, '[data-ph-feedback]'))) {
      var box = closest(el, '.ph-feedback');
      if (box) {
        each(box.querySelectorAll('[data-ph-feedback]'), function (b) { b.setAttribute('aria-pressed', String(b === el)); });
        var thanks = box.querySelector('.ph-feedback__thanks');
        if (thanks) thanks.hidden = false;
        box.dispatchEvent(new CustomEvent('ph:feedback', { bubbles: true, detail: { value: el.getAttribute('data-ph-feedback') } }));
      }
      return;
    }
    if (t && t.tagName === 'DIALOG' && (t.matches('[data-ph-search]') || t.classList.contains('ph-drawer'))) {
      var r = t.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeDialog(t);
    }
    if ((el = closest(t, '.ph-drawer a[href]'))) closeDialog(closest(el, 'dialog'));
  });

  doc.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { if (searchDialog()) { e.preventDefault(); openSearch(); } return; }
    if (e.key === '/' && !isTyping(e.target) && searchDialog()) { e.preventDefault(); openSearch(); return; }
    var dlg = closest(e.target, '[data-ph-search]');
    if (dlg && e.target.matches && e.target.matches('[data-ph-search-input]')) { onSearchKey(e, dlg); return; }
    onTabKey(e);
  });

  doc.addEventListener('input', function (e) {
    var dlg = closest(e.target, '[data-ph-search]');
    if (dlg && e.target.matches('[data-ph-search-input]')) filterSearch(dlg, e.target.value);
  });

  doc.addEventListener('change', function (e) {
    var sel = e.target;
    if (sel && sel.matches && sel.matches('select[data-ph-version]')) {
      var url = sel.value;
      if (url && url.charAt(0) !== '#') window.location.href = url;
    }
  });

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { init(); });
  else init();

  window.PhemDocs = {
    version: '1.0.0',
    init: init,
    initTheme: initTheme,
    setTheme: setTheme,
    toggleTheme: toggleTheme,
    openSearch: openSearch,
    closeSearch: closeSearch,
    selectTab: function (tab) { selectTab(tab); }
  };
})();
