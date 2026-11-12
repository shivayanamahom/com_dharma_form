/* Dharma Form: тяжёлая часть формы (маска телефона, файлы, проверка, защита от ботов, отправка). Подгружается, когда форма нужна. */
(() => {
    'use strict';

    if (window.DharmaFormUI || !window.DharmaFormShared) { return; }

    const { O, S, q, qa, store, getJson, renderUrl } = window.DharmaFormShared;

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
        const auto = (O.auto || {})[d.code];
        if (auto && !O.dev && +auto.sent > 0) { store('localStorage', (s) => s.setItem('dform:sent:' + d.code, String(Date.now() + (+auto.sent) * 864e5))); }
        document.dispatchEvent(new CustomEvent('dharmaform:sent', { detail: { code: d.code, message: d.message } }));
        if (d.redirect) { setTimeout(() => { location.href = d.redirect; }, Math.max(300, (d.delay || 0) * 1000)); }
        else if (modal && d.close) { setTimeout(() => modal.hide(), Math.max(0, (d.delay || 0) * 1000)); }
    };

    const alertText = (form, text) => {
        const a = q(form, '.dharmaform__alert');
        if (a) { a.textContent = text; a.hidden = false; }
    };

    /* Форма на странице могла попасть в кэш страницы (токен, метка и ловушка чужие): перед работой берём свежие. Окно подгружается уже свежим. */
    const refresh = (form) => {
        if (!form.hasAttribute('data-dharma-refresh') || !form.dataset.dharmaformRoot) { return Promise.resolve(); }
        return getJson(renderUrl(form.dataset.dharmaformRoot)).then((json) => {
            if (!json.success) { return; }
            const fresh = q(new DOMParser().parseFromString(json.data.html, 'text/html'), 'form.dharmaform');
            if (!fresh) { return; }
            if (form.elements.stamp && fresh.elements.stamp) { form.elements.stamp.value = fresh.elements.stamp.value; }
            const trap = qa(form, 'input[name^="hp_"]')[0];
            const newTrap = qa(fresh, 'input[name^="hp_"]')[0];
            if (trap && newTrap) { trap.name = newTrap.name; }
            const token = qa(form, 'input[type="hidden"]').find((i) => /^[0-9a-f]{32}$/.test(i.name));
            const newToken = qa(fresh, 'input[type="hidden"]').find((i) => /^[0-9a-f]{32}$/.test(i.name));
            if (token && newToken) { token.name = newToken.name; }
            if (fresh.dataset.dharmaPow) { form.dataset.dharmaPow = fresh.dataset.dharmaPow; }
        }).catch(() => {});
    };

    function initForm(form, modal) {
        if (form.dataset.dformReady) { return; }
        form.dataset.dformReady = '1';
        let pow = null;
        const ready = refresh(form);
        // Доказательство работы считается, когда посетитель начал заполнять форму (к отправке оно уже готово), а не при каждой загрузке страницы.
        const ensurePow = () => { if (!pow && form.dataset.dharmaPow) { pow = startPow(form); } return pow; };
        ['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach((ev) => form.addEventListener(ev, () => { ready.then(ensurePow); }, { once: true, passive: true }));
        const stampHuman = watchHuman(form);
        qa(form, '[data-dharma-mask]').forEach(initMask);
        qa(form, '[data-dharma-file]').forEach((w) => initFile(w, form));
        qa(form, '[data-dharma-hidden="page_title"]').forEach((el) => { el.value = document.title; });
        qa(form, '[data-dharma-hidden="page_url"], [name="page_url"]').forEach((el) => { el.value = location.href; });
        if (form.hasAttribute('data-dharma-env')) {
            const env = document.createElement('input');
            env.type = 'hidden';
            env.name = 'env';
            try {
                env.value = JSON.stringify({ ref: document.referrer || '', w: screen.width, h: screen.height, tz: (Intl.DateTimeFormat().resolvedOptions().timeZone || '') });
            } catch (e) { env.value = ''; }
            form.appendChild(env);
        }
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
            ready.then(() => { const p = ensurePow(); return p && !p.done ? Promise.race([p.promise, new Promise((r) => setTimeout(r, 15000))]) : null; }).then(send);
        });
    }

    window.DharmaFormUI = { init: initForm };
})();
