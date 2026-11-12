/* Кнопки «скопировать» в админке: текст берётся из data-dharmaform-copy, на секунду заменяется на data-copied. */
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-dharmaform-copy]') : null;

    if (!button || !navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(button.dataset.dharmaformCopy).then(() => {
        const old = button.textContent;

        button.textContent = button.dataset.copied || 'OK';
        setTimeout(() => { button.textContent = old; }, 1500);
    });
});
