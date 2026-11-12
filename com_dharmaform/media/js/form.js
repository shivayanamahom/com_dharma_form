/* Dharma Form: окно, проверка, маска телефона, файлы, отправка, автопоявление. Чистый JS, без зависимостей. */
(() => {
    'use strict';

    const O = (() => {
        try {
            const own = window.Joomla && Joomla.getOptions && Joomla.getOptions('com_dharmaform.form');
            if (own) { return own; }
            let found = {};
            document.querySelectorAll('script.joomla-script-options').forEach((el) => {
                const data = JSON.parse(el.text || '{}');
                if (data['com_dharmaform.form']) { found = data['com_dharmaform.form']; }
            });
            return found;
        } catch (e) { return {}; }
    })();
    const S = O.strings || {};
    const triggers = [];
    Object.keys(O.triggers || {}).forEach((code) => (O.triggers[code] || []).forEach((cls) => triggers.push([cls, code])));

    const q = (el, sel) => el.querySelector(sel);
    const qa = (el, sel) => Array.from(el.querySelectorAll(sel));
    const store = (area, fn) => { try { return fn(window[area]); } catch (e) { return null; } };

    /* ---------- запросы ---------- */

    const renderUrl = (code, ctx) => {
        const u = new URL(O.render, location.href);
        u.searchParams.set('code', code);
        if (ctx) { u.searchParams.set('ctx', ctx); }
        return u.toString();
    };
    const getJson = (u) => fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json());

    /* Разметка окна подгружается заранее (наведение, фокус, касание): к клику ответ уже готов. Токен и метка времени свежие: кэш живёт минуту и тратится на одно открытие. */
    const warm = new Map();
    const prefetch = (code, ctx) => {
        const key = code + '|' + (ctx || '');
        const hit = warm.get(key);
        if (hit && Date.now() - hit.at < 60000) { return hit.promise; }
        const promise = getJson(renderUrl(code, ctx));
        warm.set(key, { at: Date.now(), promise });
        promise.catch(() => warm.delete(key));
        return promise;
    };

    window.DharmaFormShared = { O, S, q, qa, store, getJson, renderUrl };

    /* Тяжёлая часть формы (форма-ui.js) и стили подгружаются, когда форма нужна: при наведении на кнопку, клике, автопоказе или если форма есть на странице. */
    let uiLoad = null;
    const loadUI = () => {
        if (window.DharmaFormUI) { return Promise.resolve(window.DharmaFormUI); }
        if (!O.ui) { return Promise.reject(new Error('no ui')); }
        uiLoad = uiLoad || new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = O.ui;
            s.onload = () => (window.DharmaFormUI ? resolve(window.DharmaFormUI) : reject(new Error('ui')));
            s.onerror = () => { uiLoad = null; reject(new Error('ui')); };
            document.head.appendChild(s);
        });
        return uiLoad;
    };

    let cssLoad = null;
    const loadCss = () => {
        if (!O.css) { return Promise.resolve(); }
        cssLoad = cssLoad || new Promise((resolve) => {
            const l = document.createElement('link');
            l.rel = 'stylesheet';
            l.href = O.css;
            l.onload = resolve;
            l.onerror = resolve;
            document.head.appendChild(l);
        });
        return cssLoad;
    };

    /* ---------- окно ---------- */

    let current = null;

    const makeModal = (title, trigger) => {
        const el = document.createElement('div');
        const id = 'dform-title-' + Date.now();
        el.className = 'dharmaform-modal';
        el.tabIndex = -1;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-labelledby', id);
        el.innerHTML = '<div class="dharmaform-modal__dialog"><div class="dharmaform-modal__content">'
            + '<button type="button" class="dharmaform-modal__close" data-dform-close><span aria-hidden="true">&times;</span></button>'
            + '<h2 class="dharmaform-modal__title" id="' + id + '"></h2><div class="dharmaform-modal__body"></div></div></div>';
        q(el, '.dharmaform-modal__title').textContent = title || '';
        q(el, '[data-dform-close]').setAttribute('aria-label', S.close || '');
        let closing = false;

        const onKey = (e) => {
            if (e.key === 'Escape') { m.hide(); return; }
            if (e.key !== 'Tab') { return; }
            const items = qa(el, 'a[href], button:not([disabled]), input:not([type=hidden]):not([tabindex="-1"]), select, textarea').filter((n) => n.offsetParent !== null);
            if (!items.length) { return; }
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        };

        const m = {
            el,
            body: q(el, '.dharmaform-modal__body'),
            show(auto) {
                document.body.appendChild(el);
                document.documentElement.classList.add('dharmaform-lock');
                void el.offsetWidth;
                el.classList.add('is-open');
                el.addEventListener('mousedown', (e) => { if (e.target === el || e.target.classList.contains('dharmaform-modal__dialog')) { m.hide(); } });
                q(el, '[data-dform-close]').addEventListener('click', () => m.hide());
                document.addEventListener('keydown', onKey);
                const first = auto ? null : q(el, 'input:not([type=hidden]):not([tabindex="-1"]), select, textarea');
                (first || el).focus({ preventScroll: true });
            },
            hide(instant) {
                if (closing) { return; }
                closing = true;
                document.removeEventListener('keydown', onKey);
                const end = () => {
                    el.remove();
                    document.documentElement.classList.remove('dharmaform-lock');
                    if (current === m) { current = null; }
                    if (trigger && document.contains(trigger)) { trigger.focus({ preventScroll: true }); }
                };
                el.classList.remove('is-open');
                if (instant) { end(); } else { setTimeout(end, 200); }
            },
        };
        return m;
    };

    const open = (code, trigger, source, ctx) => {
        if (current) { current.hide(true); }
        if (trigger) { trigger.classList.add('is-loading'); trigger.setAttribute('aria-busy', 'true'); }
        const auto = source !== 'class';
        const show = (title, fill) => {
            const modal = makeModal(title, trigger);
            fill(modal);
            current = modal;
            modal.show(auto);
            document.dispatchEvent(new CustomEvent('dharmaform:open', { detail: { code, source } }));
        };
        const fail = () => show('', (modal) => { modal.body.textContent = S.load_error || ''; });
        const p = prefetch(code, ctx);
        warm.delete(code + '|' + (ctx || ''));

        return Promise.all([p, loadUI(), loadCss()]).then(([json, ui]) => {
            if (!json.success) { show('', (modal) => { modal.body.textContent = json.message || S.load_error || ''; }); return; }
            show(json.data.title, (modal) => {
                modal.body.innerHTML = json.data.html;
                const form = q(modal.body, 'form.dharmaform');
                if (form) { ui.init(form, modal); }
            });
        }).catch(fail).finally(() => {
            if (trigger) { trigger.classList.remove('is-loading'); trigger.removeAttribute('aria-busy'); }
        });
    };

    /* ---------- автопоявление ---------- */

    const initAuto = () => {
        if (Object.keys(O.auto || {}).length) {
            const preload = () => { loadUI().catch(() => {}); loadCss(); };
            if (window.requestIdleCallback) { requestIdleCallback(preload, { timeout: 4000 }); } else { setTimeout(preload, 2000); }
        }

        // Для проверки: адрес с ?dform=reset забывает, что окно уже показывали или форму уже отправляли.
        if (/[?&]dform=reset(&|$)/.test(location.search)) {
            ['sessionStorage', 'localStorage'].forEach((area) => store(area, (s) => { Object.keys(s).filter((k) => k.indexOf('dform:') === 0).forEach((k) => s.removeItem(k)); }));
        }
        const coarse = matchMedia('(pointer:coarse)').matches || innerWidth < 768;

        Object.keys(O.auto || {}).forEach((code) => {
            const a = O.auto[code];
            const seenKey = 'dform:seen:' + code;
            if ((a.devices === 'desktop' && coarse) || (a.devices === 'mobile' && !coarse)) { return; }
            // Режим разработчика (настройки компонента): ограничений по показам нет, окно открывается при каждой загрузке.
            if (!O.dev) {
                if (+store('localStorage', (s) => s.getItem('dform:sent:' + code)) > Date.now()) { return; }
                if (a.frequency === 'session' && store('sessionStorage', (s) => s.getItem(seenKey))) { return; }
                if (a.frequency === 'days' && +store('localStorage', (s) => s.getItem(seenKey)) > Date.now()) { return; }
            }

            const off = [];
            let done = false;
            const fire = (source) => {
                if (done) { return; }
                done = true;
                off.forEach((f) => f());
                if (current) { return; }
                if (!O.dev && a.frequency === 'session') { store('sessionStorage', (s) => s.setItem(seenKey, '1')); }
                if (!O.dev && a.frequency === 'days') { store('localStorage', (s) => s.setItem(seenKey, String(Date.now() + (+a.days || 1) * 864e5))); }
                open(code, null, source, contextFor(code, null));
            };
            const on = (target, ev, fn, opt) => { target.addEventListener(ev, fn, opt); off.push(() => target.removeEventListener(ev, fn, opt)); };
            const list = a.triggers || [];

            if (list.includes('delay')) { const t = setTimeout(() => fire('delay'), (+a.delay || 0) * 1000); off.push(() => clearTimeout(t)); }

            if (list.includes('scroll')) {
                let timer = 0;
                on(window, 'scroll', () => {
                    if (timer) { return; }
                    timer = setTimeout(() => {
                        timer = 0;
                        const h = document.documentElement.scrollHeight - innerHeight;
                        if (h > 0 && (scrollY / h) * 100 >= (+a.scroll || 50)) { fire('scroll'); }
                    }, 100);
                }, { passive: true });
                off.push(() => clearTimeout(timer));
            }

            if (list.includes('exit') && !coarse) {
                on(document, 'mouseout', (e) => { if (e.clientY <= 0 && !e.relatedTarget) { fire('exit'); } });
            }

            if (list.includes('idle')) {
                let t;
                const arm = () => { clearTimeout(t); t = setTimeout(() => fire('idle'), (+a.idle || 30) * 1000); };
                ['pointermove', 'keydown', 'scroll', 'touchstart'].forEach((ev) => on(window, ev, arm, { passive: true }));
                off.push(() => clearTimeout(t));
                arm();
            }
        });
    };

    /* ---------- запуск ---------- */

    /* Привязка к объекту (товару): ближайший к кнопке родитель с атрибутом; для автопоказа и кнопок вне карточки — страница объекта. */
    const contextFor = (code, el) => {
        const idOf = (node, attrs) => {
            for (const a of attrs) {
                const n = parseInt(node.getAttribute(a) || '', 10);
                if (n > 0) { return n; }
            }
            return 0;
        };

        for (const r of ((O.contexts || {})[code] || [])) {
            const ids = r.attrs.map((a) => '[' + a + ']').join(',');
            const box = el ? el.closest(r.container) : null;
            const node = box ? (box.matches(ids) ? box : box.querySelector(ids)) : document.querySelector(r.page);
            const id = node ? idOf(node, r.attrs) : 0;
            if (id > 0) { return r.integration + '.' + r.entity + ':' + id; }
        }
        return '';
    };

    const opener = (target) => {
        if (!(target instanceof Element)) { return null; }
        const el = target.closest('[data-dharmaform]');
        if (el) { return { el, code: el.dataset.dharmaform, ctx: el.dataset.dharmaformCtx || contextFor(el.dataset.dharmaform, el) }; }
        for (const [cls, code] of triggers) {
            const hit = target.closest('.' + CSS.escape(cls));
            if (hit) { return { el: hit, code, ctx: hit.dataset.dharmaformCtx || contextFor(code, hit) }; }
        }
        return null;
    };

    /* Подготовка к показу: разметка формы, скрипт и стили. Наведение ждёт 120 мс, чтобы пробег курсора по карточкам не слал запросов. */
    let hoverTimer = 0;
    const warmUp = (e) => {
        const o = opener(e.target);
        if (!o) { return; }
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => { prefetch(o.code, o.ctx).catch(() => {}); loadUI().catch(() => {}); loadCss(); }, e.type === 'pointerover' ? 120 : 0);
    };
    ['pointerover', 'focusin'].forEach((ev) => document.addEventListener(ev, warmUp, { passive: true }));
    document.addEventListener('pointerout', () => clearTimeout(hoverTimer), { passive: true });
    document.addEventListener('touchstart', warmUp, { passive: true });

    document.addEventListener('click', (e) => {
        const o = opener(e.target);
        if (!o) { return; }
        e.preventDefault();
        open(o.code, o.el, 'class', o.ctx);
    });

    window.DharmaForm = { open: (code, trigger, ctx) => open(code, trigger || null, 'class', ctx || ''), init: (form) => loadUI().then((ui) => ui.init(form, null)) };

    const inline = qa(document, 'form.dharmaform');
    if (inline.length) { loadUI().then((ui) => inline.forEach((form) => ui.init(form, null))).catch(() => {}); }
    initAuto();
})();
