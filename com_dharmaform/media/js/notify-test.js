/* Кнопка «Отправить тест» в настройках компонента: запрос к admin-задаче notify.test, итог по каждому получателю. */
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-dharmaform-test]') : null;

    if (!button) {
        return;
    }

    const box = button.parentElement.querySelector('[data-dharmaform-test-result]');
    const show = (items) => {
        box.textContent = '';
        items.forEach((item) => {
            const line = document.createElement('div');
            line.className = item.ok ? 'text-success' : 'text-danger';
            line.textContent = (item.ok ? '✓ ' : '✗ ') + (item.to ? item.to + ': ' : '') + item.text;
            box.appendChild(line);
        });
    };

    button.disabled = true;
    box.textContent = '…';

    fetch(button.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((json) => show(json.success ? json.data.items : [{ ok: false, to: '', text: json.message || 'Error' }]))
        .catch(() => show([{ ok: false, to: '', text: 'Network error' }]))
        .finally(() => { button.disabled = false; });
});
