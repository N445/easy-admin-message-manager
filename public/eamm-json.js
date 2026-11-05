(() => {
    const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const setup = (block) => {
        if (block.dataset.eammReady) {
            return;
        }
        block.dataset.eammReady = '1';

        const raw = block.querySelector('script[type="application/json"]').textContent;
        const viewer = block.querySelector('json-viewer');
        const search = block.querySelector('[data-eamm-json-search]');
        let matches = null;

        viewer.data = JSON.parse(raw);
        if (block.dataset.eammJsonExpand) {
            viewer.expand(block.dataset.eammJsonExpand);
        }

        block.addEventListener('click', (event) => {
            const button = event.target.closest('[data-eamm-json-action]');
            if (!button) {
                return;
            }
            const action = button.dataset.eammJsonAction;
            if ('expand' === action) viewer.expandAll();
            if ('collapse' === action) viewer.collapseAll();
            if ('copy' === action) window.eamm.copy(button, raw);
        });

        search?.addEventListener('input', () => { matches = null; });
        search?.addEventListener('keydown', (event) => {
            if ('Enter' !== event.key || '' === search.value.trim()) {
                return;
            }
            event.preventDefault();
            matches ??= viewer.search(new RegExp(escapeRegExp(search.value.trim()), 'i'));
            const next = matches.next();
            search.classList.toggle('is-not-found', Boolean(next.done));
            if (next.done) {
                matches = null;
            }
        });
    };

    const init = () => customElements.whenDefined('json-viewer').then(() => document.querySelectorAll('[data-eamm-json]').forEach(setup));

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
