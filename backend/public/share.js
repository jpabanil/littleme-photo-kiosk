const share = document.getElementById('share');
const copied = document.getElementById('copied');

share?.addEventListener('click', async () => {
    const url = share.dataset.url || window.location.href;
    try {
        if (navigator.share) {
            await navigator.share({ title: 'Little Me', url });
            return;
        }
    } catch (error) {
        if (error && error.name === 'AbortError') {
            return;
        }
    }

    try {
        await navigator.clipboard.writeText(url);
        if (copied) {
            copied.hidden = false;
        }
    } catch {
        window.prompt('Copy this link', url);
    }
});
