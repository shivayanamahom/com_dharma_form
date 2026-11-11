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

    const renderUrl = (code) => {
        const u = new URL(O.render, location.href);
        u.searchParams.set('code', code);
        return u.toString();
    };
    const getJson = (u) => fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json());

    /* Разметка окна подгружается заранее (наведение, фокус, касание): к клику ответ уже готов. Токен и метка времени свежие: кэш живёт минуту и тратится на одно открытие. */
    const warm = new Map();
    const prefetch = (code) => {
        const hit = warm.get(code);
        if (hit && Date.now() - hit.at < 60000) { return hit.promise; }
        const promise = getJson(renderUrl(code));
        warm.set(code, { at: Date.now(), promise });
        promise.catch(() => warm.delete(code));
        return promise;
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

    const open = (code, trigger, source) => {
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
        const p = prefetch(code);
        warm.delete(code);

        return p.then((json) => {
            if (!json.success) { show('', (modal) => { modal.body.textContent = json.message || S.load_error || ''; }); return; }
            show(json.data.title, (modal) => {
                modal.body.innerHTML = json.data.html;
                const form = q(modal.body, 'form.dharmaform');
                if (form) { initForm(form, modal); }
            });
        }).catch(fail).finally(() => {
            if (trigger) { trigger.classList.remove('is-loading'); trigger.removeAttribute('aria-busy'); }
        });
    };

    /* ---------- маска телефона ---------- */

    const initMask = (input) => {
        const mask = input.dataset.dharmaMask;
        const slots = (mask.match(/#/g) || []).length;
        const lead = mask.split('#')[0];
        const prefix = lead.replace(/\D/g, '');
        input.dataset.slots = String(slots);

        const format = (raw) => {
            let digits = raw.replace(/\D/g, '');
            if (prefix && digits.length > slots && (digits.startsWith(prefix) || (prefix === '7' && digits.startsWith('8')))) {
                digits = digits.slice(prefix.length);
            }
            digits = digits.slice(0, slots);
            let out = '';
            let i = 0;
            for (const ch of mask) {
                if (ch === '#') {
                    if (i >= digits.length) { break; }
                    out += digits[i++];
                } else if (i < digits.length || out === '') {
                    out += ch;
                } else {
                    break;
                }
            }
            return digits.length ? out : '';
        };

        input.addEventListener('focus', () => { if (input.value === '') { input.value = lead; } });
        input.addEventListener('blur', () => { if (input.value === lead || input.value.replace(/\D/g, '') === prefix) { input.value = ''; } });
        input.addEventListener('input', () => {
            const f = format(input.value.startsWith(lead) ? input.value.slice(lead.length) : input.value);
            input.value = f === '' ? (input.value === '' ? '' : lead) : f;
        });
    };

    const phoneComplete = (input) => {
        const mask = input.dataset.dharmaMask || '';
        const slots = +input.dataset.slots || 0;
        const lead = mask.split('#')[0].trim();
        const prefix = lead.replace(/\D/g, '');
        const v = input.value.trim();
        if (lead && v.startsWith(lead)) { return v.slice(lead.length).replace(/\D/g, '').length === slots; }
        const d = v.replace(/\D/g, '');
        return d.length === slots || (prefix !== '' && d.length === slots + prefix.length && (d.startsWith(prefix) || (prefix === '7' && d.startsWith('8'))));
    };

    /* ---------- файлы ---------- */

    const fmtSize = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(n / 1024)) + ' КБ');

    const initFile = (wrap, form) => {
        const input = q(wrap, 'input[type=file]');
        const list = q(wrap, '.dharmaform__file-list');
        const maxBytes = (+wrap.dataset.maxMb || 0) * 1048576;
        const maxCount = input.multiple ? (+wrap.dataset.maxCount || 1) : 1;
        const accept = (wrap.dataset.accept || '').split(',').filter(Boolean);
        const box = wrap.closest('[data-dharma-field]');
        let files = [];

        const render = () => {
            list.textContent = '';
            files.forEach((f, i) => {
                const li = document.createElement('li');
                li.className = 'dharmaform__file-item';
                li.innerHTML = '<span class="dharmaform__file-name"></span><span class="dharmaform__file-size"></span><button type="button" class="dharmaform__file-remove">&times;</button>';
                q(li, '.dharmaform__file-name').textContent = f.name;
                q(li, '.dharmaform__file-size').textContent = fmtSize(f.size);
                q(li, 'button').setAttribute('aria-label', S.close || '');
                q(li, 'button').addEventListener('click', () => { files.splice(i, 1); sync(); });
                list.appendChild(li);
            });
        };
        const sync = () => {
            try {
                const dt = new DataTransfer();
                files.forEach((f) => dt.items.add(f));
                input.files = dt.files;
            } catch (e) { /* без DataTransfer остаётся выбор браузера */ }
            render();
        };

        input.addEventListener('change', () => {
            clearError(box);
            let bad = '';
            Array.from(input.files).forEach((f) => {
                const ext = '.' + (f.name.split('.').pop() || '').toLowerCase();
                if (accept.length && !accept.includes(ext)) { bad = 'file_type'; }
                else if (maxBytes && f.size > maxBytes) { bad = 'file_size'; }
                else if (files.length >= maxCount && input.multiple) { bad = 'file_count'; }
                else if (!files.some((x) => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified)) {
                    if (input.multiple) { files.push(f); } else { files = [f]; }
                }
            });
            if (bad) { setError(box, bad); }
            sync();
        });

        ['dragenter', 'dragover'].forEach((ev) => wrap.addEventListener(ev, () => q(wrap, '.dharmaform__file-area').classList.add('is-dragover')));
        ['dragleave', 'drop'].forEach((ev) => wrap.addEventListener(ev, () => q(wrap, '.dharmaform__file-area').classList.remove('is-dragover')));
        form.addEventListener('reset', () => { files = []; render(); });
    };

    /* ---------- ошибки и проверка ---------- */

    const errBox = (box) => q(box, '.dharmaform__error');

    const setError = (box, kind) => {
        const err = errBox(box);
        box.classList.add('is-invalid');
        qa(box, 'input, select, textarea').forEach((el) => el.setAttribute('aria-invalid', 'true'));
        if (err) { err.textContent = err.dataset.msg || (kind === 'empty' ? err.dataset.msgEmpty : '') || S[kind === 'empty' ? 'required' : kind] || S.required || ''; }
    };

    const clearError = (box) => {
        if (!box) { return; }
        box.classList.remove('is-invalid');
        qa(box, '[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
        const err = errBox(box);
        if (err) { err.textContent = ''; }
    };

    const fieldKind = (box) => {
        const input = q(box, 'input:not([type=hidden]), select, textarea');
        if (!input) { return ''; }
        if (box.classList.contains('dharmaform__field--consent')) { return 'consent'; }
        if (box.classList.contains('dharmaform__field--radio') || box.classList.contains('dharmaform__field--checkboxes')) { return 'group'; }
        return input.type === 'file' ? 'file' : 'input';
    };

    const validate = (form) => {
        let ok = true;
        qa(form, '[data-dharma-field]').forEach((box) => {
            const kind = fieldKind(box);
            const required = box.dataset.required === '1';
            let bad = '';
            if (kind === 'consent') {
                if (required && !q(box, 'input').checked) { bad = 'consent'; }
            } else if (kind === 'group') {
                if (required && !qa(box, 'input').some((i) => i.checked)) { bad = 'choice'; }
            } else if (kind === 'file') {
                if (required && !q(box, 'input').files.length) { bad = 'empty'; }
            } else if (kind === 'input') {
                const el = q(box, 'input, select, textarea');
                const v = el.value.trim();
                if (v === '') { if (required) { bad = 'empty'; } }
                else if (el.dataset.dharmaMask) { if (!phoneComplete(el)) { bad = 'phone'; } }
                else if (el.type === 'email' && !el.checkValidity()) { bad = 'email'; }
            }
            if (bad) { ok = false; setError(box, bad); } else { clearError(box); }
        });
        return ok;
    };

    /* ---------- защита: доказательство работы (PoW) и присутствие человека ---------- */

    /*pow-start*/
    function core() {
        const K = new Uint32Array(64);
        const IV = new Uint32Array(8);
        const H = new Uint32Array(8);
        const W = new Uint32Array(64);
        for (let c = 2, n = 0; n < 64; c++) {
            let p = 1;
            for (let d = 2; d * d <= c; d++) { if (c % d === 0) { p = 0; break; } }
            if (p) { if (n < 8) { IV[n] = Math.pow(c, 0.5) % 1 * 4294967296; } K[n++] = Math.pow(c, 1 / 3) % 1 * 4294967296; }
        }
        const rr = (x, n) => (x >>> n) | (x << (32 - n));
        const block = (b, o) => {
            for (let i = 0; i < 16; i++) { W[i] = (b[o + 4 * i] << 24) | (b[o + 4 * i + 1] << 16) | (b[o + 4 * i + 2] << 8) | b[o + 4 * i + 3]; }
            for (let i = 16; i < 64; i++) {
                const x = W[i - 15];
                const y = W[i - 2];
                W[i] = W[i - 16] + (rr(x, 7) ^ rr(x, 18) ^ (x >>> 3)) + W[i - 7] + (rr(y, 17) ^ rr(y, 19) ^ (y >>> 10));
            }
            let a = H[0], b2 = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
            for (let i = 0; i < 64; i++) {
                const t1 = h + (rr(e, 6) ^ rr(e, 11) ^ rr(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + W[i];
                const t2 = (rr(a, 2) ^ rr(a, 13) ^ rr(a, 22)) + ((a & b2) ^ (a & c) ^ (b2 & c));
                h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b2; b2 = a; a = (t1 + t2) | 0;
            }
            H[0] += a; H[1] += b2; H[2] += c; H[3] += d; H[4] += e; H[5] += f; H[6] += g; H[7] += h;
        };
        /* Первое n из [from, to), при котором sha256(stamp:n) начинается с bits нулевых бит; иначе -1. */
        return (stamp, bits, from, to) => {
            const pre = stamp + ':';
            for (let n = from; n < to; n++) {
                const s = pre + n;
                const size = ((s.length + 9 + 63) >> 6) << 6;
                const buf = new Uint8Array(size);
                for (let i = 0; i < s.length; i++) { buf[i] = s.charCodeAt(i); }
                buf[s.length] = 128;
                buf[size - 2] = (s.length * 8) >> 8;
                buf[size - 1] = (s.length * 8) & 255;
                H.set(IV);
                for (let o = 0; o < size; o += 64) { block(buf, o); }
                if ((H[0] >>> (32 - bits)) === 0) { return n; }
            }
            return -1;
        };
    }
    /*pow-end*/

    const POW_WORKER = 'const step=(' + core + ')();onmessage=e=>{const[s,b]=e.data;let n=0,r;while((r=step(s,b,n,n+50000))<0)n+=50000;postMessage(r)}';

    /* Решение ищется сразу при готовности формы, в потоке Worker; без Worker — кусками по main-потоку. */
    const startPow = (form) => {
        const stamp = form.elements.stamp;
        const out = form.elements.pow;
        if (!stamp || !out) { return null; }
        const bits = Math.min(24, Math.max(8, +form.dataset.dharmaPow || 14));
        const state = { done: false };
        state.promise = new Promise((resolve) => {
            const finish = (n) => { out.value = String(n); state.done = true; resolve(); };
            const inline = () => {
                const step = core();
                let n = 0;
                const tick = () => {
                    const r = step(stamp.value, bits, n, n + 4000);
                    if (r >= 0) { finish(r); } else { n += 4000; setTimeout(tick, 0); }
                };
                tick();
            };
            const run = () => {
                try {
                    const url = URL.createObjectURL(new Blob([POW_WORKER], { type: 'text/javascript' }));
                    const w = new Worker(url);
                    const end = () => { w.terminate(); URL.revokeObjectURL(url); };
                    w.onmessage = (e) => { end(); finish(e.data); };
                    w.onerror = () => { end(); inline(); };
                    w.postMessage([stamp.value, bits]);
                } catch (e) { inline(); }
            };
            if (window.requestIdleCallback) { requestIdleCallback(run, { timeout: 300 }); } else { setTimeout(run, 0); }
        });
        return state;
    };

    /* Число событий человека в форме и время от первого из них (мс): «7.4210». */
    const watchHuman = (form) => {
        const out = form.elements.dfh;
        if (!out) { return () => {}; }
        let count = 0;
        let first = 0;
        const hit = (e) => { if (e.isTrusted) { if (!count) { first = performance.now(); } count++; } };
        ['pointerdown', 'keydown', 'touchstart', 'focusin', 'input'].forEach((ev) => form.addEventListener(ev, hit, { capture: true, passive: true }));
        return () => { out.value = count + '.' + (count ? Math.round(performance.now() - first) : 0); };
    };

    /* ---------- форма ---------- */

    const utm = () => {
        const out = store('sessionStorage', (s) => JSON.parse(s.getItem('dformUtm') || '{}')) || {};
        new URLSearchParams(location.search).forEach((v, k) => { if (/^(utm_[a-z]+|yclid|gclid|ref)$/i.test(k)) { out[k.toLowerCase()] = v; } });
        store('sessionStorage', (s) => s.setItem('dformUtm', JSON.stringify(out)));
        return out;
    };

    const goal = (d) => {
        if (!d.goal) { return; }
        try {
            if (d.counter && typeof window.ym === 'function') { window.ym(Number(d.counter), 'reachGoal', d.goal); }
            if (typeof window.gtag === 'function') { window.gtag('event', d.goal); }
            if (Array.isArray(window.dataLayer)) { window.dataLayer.push({ event: d.goal }); }
        } catch (e) { /* счётчики не должны ломать форму */ }
    };

    const thanks = (form, modal, d) => {
        const wrap = document.createElement('div');
        wrap.className = 'dharmaform__thanks';
        wrap.setAttribute('role', 'status');
        wrap.innerHTML = '<svg class="dharmaform__thanks-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12"/><path d="m6.5 12.5 3.8 3.8 7.2-7.6"/></svg><div class="dharmaform__thanks-text"></div>';
        q(wrap, '.dharmaform__thanks-text').innerHTML = d.message || '';
        form.replaceWith(wrap);
        goal(d);
        store('localStorage', (s) => s.setItem('dform:sent:' + d.code, String(Date.now() + 30 * 864e5)));
        document.dispatchEvent(new CustomEvent('dharmaform:sent', { detail: { code: d.code, message: d.message } }));
        if (d.redirect) { setTimeout(() => { location.href = d.redirect; }, Math.max(300, (d.delay || 0) * 1000)); }
        else if (modal && d.close) { setTimeout(() => modal.hide(), Math.max(0, (d.delay || 0) * 1000)); }
    };

    const alertText = (form, text) => {
        const a = q(form, '.dharmaform__alert');
        if (a) { a.textContent = text; a.hidden = false; }
    };

    function initForm(form, modal) {
        if (form.dataset.dformReady) { return; }
        form.dataset.dformReady = '1';
        const pow = form.dataset.dharmaPow ? startPow(form) : null;
        const stampHuman = watchHuman(form);
        qa(form, '[data-dharma-mask]').forEach(initMask);
        qa(form, '[data-dharma-file]').forEach((w) => initFile(w, form));
        qa(form, '[data-dharma-hidden="page_title"]').forEach((el) => { el.value = document.title; });
        qa(form, '[data-dharma-hidden="page_url"], [name="page_url"]').forEach((el) => { el.value = location.href; });
        const params = utm();
        Object.keys(params).forEach((k) => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'utm[' + k + ']';
            i.value = params[k];
            form.appendChild(i);
        });

        const reset = (e) => { if (e.target.type === 'file') { return; } const box = e.target.closest && e.target.closest('[data-dharma-field]'); if (box && box.classList.contains('is-invalid')) { clearError(box); } };
        form.addEventListener('input', reset);
        form.addEventListener('change', reset);

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const a = q(form, '.dharmaform__alert');
            if (a) { a.hidden = true; }
            if (!validate(form)) {
                const bad = q(form, '.is-invalid input, .is-invalid select, .is-invalid textarea');
                if (bad) { bad.focus(); }
                return;
            }
            const btn = q(form, 'button[type=submit]');
            if (btn) { btn.disabled = true; }
            const send = () => {
                stampHuman();
                const u = new URL(form.action, location.href);
                u.searchParams.set('format', 'json');
                fetch(u.toString(), { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((json) => {
                        if (json.success) { thanks(form, modal, json.data); return; }
                        const errors = (json.data && json.data.errors) || {};
                        let shown = false;
                        Object.keys(errors).forEach((name) => {
                            const box = q(form, '[data-dharma-field="' + name + '"]');
                            if (!box) { return; }
                            setError(box, 'empty');
                            errBox(box).textContent = errors[name];
                            shown = true;
                        });
                        if (!shown || json.message) { alertText(form, json.message || S.send_error || ''); }
                        if (btn) { btn.disabled = false; }
                    })
                    .catch(() => { alertText(form, S.send_error || ''); if (btn) { btn.disabled = false; } });
            };
            if (pow && !pow.done) { Promise.race([pow.promise, new Promise((r) => setTimeout(r, 15000))]).then(send); } else { send(); }
        });
    }

    /* ---------- автопоявление ---------- */

    const initAuto = () => {
        const coarse = matchMedia('(pointer:coarse)').matches || innerWidth < 768;

        Object.keys(O.auto || {}).forEach((code) => {
            const a = O.auto[code];
            const seenKey = 'dform:seen:' + code;
            if ((a.devices === 'desktop' && coarse) || (a.devices === 'mobile' && !coarse)) { return; }
            if (+store('localStorage', (s) => s.getItem('dform:sent:' + code)) > Date.now()) { return; }
            if (a.frequency === 'session' && store('sessionStorage', (s) => s.getItem(seenKey))) { return; }
            if (a.frequency === 'days' && +store('localStorage', (s) => s.getItem(seenKey)) > Date.now()) { return; }

            const off = [];
            let done = false;
            const fire = (source) => {
                if (done) { return; }
                done = true;
                off.forEach((f) => f());
                if (current) { return; }
                if (a.frequency === 'session') { store('sessionStorage', (s) => s.setItem(seenKey, '1')); }
                if (a.frequency === 'days') { store('localStorage', (s) => s.setItem(seenKey, String(Date.now() + (+a.days || 1) * 864e5))); }
                open(code, null, source);
            };
            const on = (target, ev, fn, opt) => { target.addEventListener(ev, fn, opt); off.push(() => target.removeEventListener(ev, fn, opt)); };
            const list = a.triggers || [];

            if (list.includes('delay')) { const t = setTimeout(() => fire('delay'), (+a.delay || 0) * 1000); off.push(() => clearTimeout(t)); }

            if (list.includes('scroll')) {
                let queued = false;
                on(window, 'scroll', () => {
                    if (queued) { return; }
                    queued = true;
                    requestAnimationFrame(() => {
                        queued = false;
                        const h = document.documentElement.scrollHeight - innerHeight;
                        if (h > 0 && (scrollY / h) * 100 >= (+a.scroll || 50)) { fire('scroll'); }
                    });
                }, { passive: true });
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

    const opener = (target) => {
        if (!(target instanceof Element)) { return null; }
        const el = target.closest('[data-dharmaform]');
        if (el) { return { el, code: el.dataset.dharmaForm }; }
        for (const [cls, code] of triggers) {
            const hit = target.closest('.' + CSS.escape(cls));
            if (hit) { return { el: hit, code }; }
        }
        return null;
    };

    const warmUp = (e) => { const o = opener(e.target); if (o) { prefetch(o.code); } };
    ['pointerover', 'focusin'].forEach((ev) => document.addEventListener(ev, warmUp, { passive: true }));
    document.addEventListener('touchstart', warmUp, { passive: true });

    document.addEventListener('click', (e) => {
        const o = opener(e.target);
        if (!o) { return; }
        e.preventDefault();
        open(o.code, o.el, 'class');
    });

    window.DharmaForm = { open: (code, trigger) => open(code, trigger || null, 'class'), init: (form) => initForm(form, null) };

    qa(document, 'form.dharmaform').forEach((form) => initForm(form, null));
    initAuto();
})();
