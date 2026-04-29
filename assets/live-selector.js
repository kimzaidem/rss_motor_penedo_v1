(() => {
  const frame = document.getElementById('siteFrame');
  const blockScripts = document.getElementById('blockScripts');
  const info = document.getElementById('selectedInfo');
  const modes = [...document.querySelectorAll('[data-mode]')];
  const fields = [...document.querySelectorAll('.selector-fields input')];
  const fieldMap = Object.fromEntries(fields.map(i => [i.name, i]));
  const countMap = Object.fromEntries([...document.querySelectorAll('[data-count]')].map(i => [i.dataset.count, i]));
  let mode = 'title_selector';
  let doc = null;

  function getOpenerDocument() {
    try {
      if (window.opener && !window.opener.closed && window.opener.document) {
        return window.opener.document;
      }
    } catch (e) {}
    return null;
  }

  function fromOpener() {
    const odoc = getOpenerDocument();
    if (!odoc) return;
    fields.forEach(input => {
      const src = odoc.querySelector(`[name="${input.name}"]`);
      if (src) input.value = src.value || '';
    });
  }

  function setMode(next) {
    mode = next;
    modes.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    if (fieldMap[mode]?.value) highlight(fieldMap[mode].value, mode);
  }

  modes.forEach(btn => btn.addEventListener('click', () => setMode(btn.dataset.mode)));

  document.getElementById('reloadFrame').addEventListener('click', () => {
    frame.src = `proxy.php?url=${encodeURIComponent(window.SELECTOR_URL)}&block_scripts=${blockScripts.checked ? 1 : 0}&t=${Date.now()}`;
  });

  frame.addEventListener('load', () => {
    try {
      doc = frame.contentDocument || frame.contentWindow.document;
      prepareDoc();
      refreshAllHighlights();
    } catch (e) {
      info.innerHTML = '<b>Erro</b><p>Não foi possível acessar o conteúdo carregado.</p>';
    }
  });

  function prepareDoc() {
    if (!doc || !doc.body) return;

    const oldStyle = doc.getElementById('rssmotor-live-style');
    if (oldStyle) oldStyle.remove();

    const style = doc.createElement('style');
    style.id = 'rssmotor-live-style';
    style.textContent = '.rssm-hover{outline:3px solid #7c5cff!important;outline-offset:2px!important}.rssm-selected{outline:4px solid #f4d35e!important;outline-offset:2px!important}.rssm-match{outline:3px solid #31c48d!important;outline-offset:2px!important}.rssm-block{outline:3px dashed #1c64f2!important;outline-offset:3px!important}';
    doc.head?.appendChild(style);

    doc.addEventListener('mouseover', e => {
      const el = e.target;
      if (!(el instanceof frame.contentWindow.Element)) return;
      clearClass('rssm-hover');
      el.classList.add('rssm-hover');
    }, true);

    doc.addEventListener('mouseout', e => {
      const el = e.target;
      if (el instanceof frame.contentWindow.Element) el.classList.remove('rssm-hover');
    }, true);

    doc.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      selectElement(e.target);
      return false;
    }, true);
  }

  function selectElement(el) {
    if (!el || !(el instanceof frame.contentWindow.Element)) return;
    clearClass('rssm-selected');
    el.classList.add('rssm-selected');
    const selector = bestSelector(el, mode);
    fieldMap[mode].value = selector;
    const count = highlight(selector, mode);
    info.innerHTML = `<b>${label(mode)} selecionado</b><code>${escapeHtml(selector)}</code><p>${sampleText(el)}</p><p><strong>${count}</strong> elemento(s) parecidos encontrados.</p>`;
    updateCount(mode, count);
  }

  function bestSelector(el, mode) {
    const target = normalizeTarget(el, mode);
    const candidates = unique([...modeCandidates(target, mode), ...genericCandidates(target, mode), ...pathCandidates(target, mode)]);
    const scored = [];

    for (const s of candidates) {
      const count = safeCount(s);
      if (!count) continue;
      const nodes = safeQuery(s);
      const has = nodes.some(n => n === target || n.contains(target) || target.contains(n));
      if (!has && mode !== 'item_selector') continue;

      let score = 0;
      if (count > 1) score += 120;
      if (count <= 80) score += 60;
      if (count <= 35) score += 35;
      if (count <= 20) score += 20;
      if (s.includes('[class*="td_module_"]') || s.includes('.td_module_wrap') || s.includes('.entry-title') || s.includes('.td-post-date') || s.includes('.td-excerpt') || s.includes('.td-post-content')) score += 100;
      if (s.includes('#tdi_') || s.includes(':nth-') || /#[^\s>]+\d{2,}/.test(s)) score -= 300;
      if (s.split(/[ >]+/).length <= 3) score += 40;
      if (s.length < 65) score += 25;
      if (mode === 'item_selector' && count >= 2 && target.querySelector('a')) score += 120;
      if (mode === 'title_selector' && /h[123456]|entry-title/.test(s)) score += 80;
      if (mode === 'link_selector' && s.includes('a')) score += 60;
      if (mode === 'date_selector' && /time|date/.test(s)) score += 80;
      if (mode === 'image_selector' && s.includes('img')) score += 80;

      scored.push({ s, count, score });
    }

    scored.sort((a, b) => b.score - a.score || a.s.length - b.s.length);
    return scored[0]?.s || fallbackPath(target);
  }

  function normalizeTarget(el, mode) {
    if (mode === 'title_selector' || mode === 'link_selector') {
      const a = el.closest('a') || el.querySelector?.('a');
      if (a) return a;
    }

    if (mode === 'date_selector') {
      const time = el.closest('time') || el.querySelector?.('time');
      if (time) return time;
    }

    if (mode === 'image_selector') {
      const img = el.closest('img') || el.querySelector?.('img');
      if (img) return img;
    }

    if (mode === 'item_selector') {
      return closestBlock(el) || el;
    }

    return el;
  }

  function modeCandidates(el, mode) {
    if (mode === 'title_selector') return ['.entry-title a','h3.entry-title a','h2.entry-title a','h1.entry-title a','[class*="entry-title"] a','[class*="td_module_"] .entry-title a','.td_module_wrap .entry-title a','a[rel="bookmark"]','h3 a','h2 a','article h2 a','article h3 a'];
    if (mode === 'link_selector') return ['.entry-title a','[class*="entry-title"] a','[class*="td_module_"] .entry-title a','.td_module_wrap .entry-title a','a[rel="bookmark"]','h3 a','h2 a','article a','a'];
    if (mode === 'description_selector') return ['.td-excerpt','.entry-summary','.excerpt','.summary','.item-details p','[class*="excerpt"]','[class*="summary"]','article p','p'];
    if (mode === 'date_selector') return ['time[datetime]','time','.td-post-date','.entry-date','.date','[class*="post-date"]','[class*="entry-date"]','[class*="date"]'];
    if (mode === 'image_selector') return ['.td-module-thumb img','picture img','img.entry-thumb','article img','[class*="thumb"] img','img'];
    if (mode === 'content_selector') return ['.td-post-content','.entry-content','.post-content','.article-content','article .content','article','main'];
    if (mode === 'item_selector') return ['article','[class*="td_module_"]','.td_module_wrap','[class*="td-block-span"]','.post','.entry','.item','.card','li'];
    return [];
  }

  function genericCandidates(el, mode) {
    const out = [];
    const tag = el.tagName.toLowerCase();
    const stable = stableClasses(el);

    if (stable.length) {
      out.push(`${tag}.${stable.join('.')}`);
      out.push(`.${stable[0]}`);
      if (stable.length > 1) out.push(`.${stable.slice(0, 2).join('.')}`);
    }

    if (el.getAttribute('rel')) out.push(`${tag}[rel="${cssEscape(el.getAttribute('rel'))}"]`);
    if (el.getAttribute('datetime')) out.push(`${tag}[datetime]`);
    if (el.getAttribute('property')) out.push(`${tag}[property="${cssEscape(el.getAttribute('property'))}"]`);
    if (el.getAttribute('itemprop')) out.push(`${tag}[itemprop="${cssEscape(el.getAttribute('itemprop'))}"]`);

    const block = closestBlock(el);

    if (block && block !== el) {
      const b = shortSelector(block);
      const s = shortSelector(el);

      if (b && s) out.push(`${b} ${s}`);
      if (b && mode === 'title_selector') out.push(`${b} .entry-title a`, `${b} h3 a`, `${b} h2 a`, `${b} h1 a`);
      if (b && mode === 'link_selector') out.push(`${b} .entry-title a`, `${b} h3 a`, `${b} h2 a`, `${b} a`);
      if (b && mode === 'description_selector') out.push(`${b} .td-excerpt`, `${b} .entry-summary`, `${b} p`);
      if (b && mode === 'date_selector') out.push(`${b} time`, `${b} .td-post-date`, `${b} .entry-date`);
      if (b && mode === 'image_selector') out.push(`${b} img`);
    }

    out.push(tag);
    return out;
  }

  function pathCandidates(el) {
    const out = [];
    let current = el;
    let child = shortSelector(el);
    let depth = 0;

    while (current && current.parentElement && depth < 4) {
      const parent = current.parentElement;
      const p = shortSelector(parent);
      if (p && child) out.push(`${p} ${child}`);
      child = p && child ? `${p} > ${child}` : child;
      current = parent;
      depth++;
    }

    return out;
  }

  function closestBlock(el) {
    let cur = el;

    while (cur && cur !== doc.body) {
      const tag = cur.tagName?.toLowerCase();
      const cls = ` ${typeof cur.className === 'string' ? cur.className : ''} `;

      if (tag === 'article' && cur.querySelector?.('a')) return cur;
      if (tag === 'li' && cur.querySelector?.('a')) return cur;
      if (/td_module_|td_module_wrap|td-block-span|\bpost\b|\bentry\b|\bitem\b|\bcard\b|\bmodule\b/i.test(cls) && cur.querySelector?.('a')) return cur;

      cur = cur.parentElement;
    }

    return null;
  }

  function shortSelector(el) {
    if (!el || !el.tagName) return '';
    const tag = el.tagName.toLowerCase();
    const id = el.id || '';

    if (id && !isDynamic(id)) return `${tag}#${cssEscape(id)}`;

    const cls = stableClasses(el);
    if (cls.length) return `${tag}.${cls.slice(0, 2).join('.')}`;

    return tag;
  }

  function stableClasses(el) {
    const raw = typeof el.className === 'string' ? el.className : '';

    return raw
      .split(/\s+/)
      .filter(c => c && /^[a-zA-Z0-9_-]+$/.test(c) && !isDynamic(c))
      .slice(0, 4);
  }

  function isDynamic(v) {
    return /^(tdi_|tdc-|wp-image-|elementor-element-|vc_|css-|js-|is-|has-)/i.test(v) || /[a-f0-9]{8,}/i.test(v) || /\d{4,}/.test(v);
  }

  function safeQuery(selector) {
    try {
      if (!selector || !doc) return [];
      return [...doc.querySelectorAll(selector)];
    } catch (e) {
      return [];
    }
  }

  function safeCount(selector) {
    return safeQuery(selector).length;
  }

  function highlight(selector, field) {
    if (!doc) return 0;

    doc.querySelectorAll('.rssm-match,.rssm-block').forEach(n => n.classList.remove('rssm-match', 'rssm-block'));

    const nodes = safeQuery(selector);
    nodes.slice(0, 300).forEach(n => n.classList.add(field === 'item_selector' ? 'rssm-block' : 'rssm-match'));

    updateCount(field, nodes.length);
    return nodes.length;
  }

  function refreshAllHighlights() {
    fields.forEach(input => {
      if (input.value) updateCount(input.name, safeCount(input.value));
    });

    if (fieldMap[mode]?.value) highlight(fieldMap[mode].value, mode);
  }

  function clearClass(cls) {
    if (!doc) return;
    doc.querySelectorAll('.' + cls).forEach(n => n.classList.remove(cls));
  }

  function updateCount(field, count) {
    if (countMap[field]) countMap[field].textContent = count ? `${count} encontrados` : 'não encontrou';
  }

  fields.forEach(input => {
    input.addEventListener('input', () => highlight(input.value, input.name));
    input.addEventListener('change', () => highlight(input.value, input.name));
  });

  document.getElementById('applySelectors').addEventListener('click', () => {
    const data = {};
    fields.forEach(input => {
      data[input.name] = input.value || '';
    });

    let applied = 0;
    const odoc = getOpenerDocument();

    if (odoc) {
      Object.entries(data).forEach(([name, value]) => {
        const targets = [...odoc.querySelectorAll(`[name="${name}"]`)];

        targets.forEach(target => {
          target.value = value;
          target.setAttribute('value', value);
          target.dispatchEvent(new Event('input', { bubbles: true }));
          target.dispatchEvent(new Event('change', { bubbles: true }));
          applied++;
        });
      });

      try {
        if (typeof window.opener.refreshSelectorChecklist === 'function') {
          window.opener.refreshSelectorChecklist();
        }
      } catch (e) {}

      try {
        const form = odoc.getElementById('feedForm');
        if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {}

      try {
        window.opener.postMessage({
          type: 'RSSMOTOR_SELECTORS_APPLIED',
          selectors: data,
          url: window.SELECTOR_URL
        }, window.location.origin);
      } catch (e) {}
    }

    try {
      localStorage.setItem('rssmotor_selector_payload', JSON.stringify({
        selectors: data,
        url: window.SELECTOR_URL,
        time: Date.now()
      }));
    } catch (e) {}

    if (applied > 0) {
      const btn = document.getElementById('applySelectors');
      btn.textContent = 'Aplicado ✓';
      btn.disabled = true;

      info.innerHTML = `<b>Seletores aplicados</b><p>Foram enviados ${applied} campo(s) para a janela principal.</p><p>Agora é só salvar o feed.</p>`;

      setTimeout(() => {
        try {
          window.opener.focus();
        } catch (e) {}
        try {
          window.close();
        } catch (e) {}
      }, 700);

      return;
    }

    info.innerHTML = `<b>Não consegui aplicar automaticamente</b><p>A janela principal não foi encontrada. Abra o seletor pelo botão "Selecionar na página" dentro do formulário do feed.</p>`;
    alert('Não consegui encontrar a janela principal. Abra o seletor pelo botão "Selecionar na página" dentro do formulário do feed.');
  });

  document.getElementById('testSelectors').addEventListener('click', async () => {
    const box = document.getElementById('selectorTestResult');
    box.innerHTML = 'Testando...';

    const fd = new FormData();
    fd.append('csrf', window.RSSMOTOR_CSRF);
    fd.append('list_url', window.SELECTOR_URL);
    fd.append('name', 'teste');
    fd.append('max_items', '10');

    fields.forEach(input => fd.append(input.name, input.value));

    try {
      const res = await fetch('api.php?action=test_selectors', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.ok) throw new Error(data.error || 'Erro no teste');

      box.innerHTML = `<b>${data.count} item(ns) encontrados</b>` + data.items.slice(0, 5).map(i => `<div class="test-item"><strong>${escapeHtml(i.title || '')}</strong><small>${escapeHtml(i.published_at || '')}</small><p>${escapeHtml((i.summary || i.content || '').slice(0, 180))}</p></div>`).join('');
    } catch (e) {
      box.innerHTML = `<span class="error-text">${escapeHtml(e.message)}</span>`;
    }
  });

  function unique(arr) {
    return [...new Set(arr.filter(Boolean))];
  }

  function label(v) {
    return {
      item_selector: 'Bloco de item',
      title_selector: 'Título',
      description_selector: 'Descrição',
      date_selector: 'Data',
      image_selector: 'Imagem',
      link_selector: 'Link',
      content_selector: 'Conteúdo do artigo'
    }[v] || v;
  }

  function sampleText(el) {
    return escapeHtml((el.innerText || el.textContent || el.getAttribute('src') || el.getAttribute('href') || '').trim().replace(/\s+/g, ' ').slice(0, 180));
  }

  function fallbackPath(el) {
    return shortSelector(el) || el.tagName.toLowerCase();
  }

  function cssEscape(v) {
    return String(v).replace(/(["\\])/g, '\\$1');
  }

  function escapeHtml(v) {
    return String(v).replace(/[&<>"]/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[s]));
  }

  fromOpener();
  setMode('title_selector');
})();