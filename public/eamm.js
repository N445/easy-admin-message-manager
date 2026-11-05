(() => {
    const SETUP_CHECK_DELAY = 2000;
    const pausedRoots = new WeakSet();
    const polledRoots = new WeakSet();
    let lastRangeValue = null;

    const copyText = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        const area = Object.assign(document.createElement('textarea'), { value: text });
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    };

    const flash = (button, label) => {
        const original = { text: button.textContent, title: button.title };
        const keepsIcon = null !== button.querySelector('i');
        button.classList.add('is-copied');
        button.title = label;
        if (!keepsIcon) {
            button.textContent = label;
        }
        setTimeout(() => {
            button.classList.remove('is-copied');
            button.title = original.title;
            if (!keepsIcon) {
                button.textContent = original.text;
            }
        }, 1500);
    };

    const copy = (button, text) => copyText(text).then(() => flash(button, button.dataset.eammCopiedLabel));

    const confirmed = (trigger) => {
        const message = trigger.dataset.eammConfirm;
        const expected = trigger.dataset.eammConfirmType;
        if (undefined === expected) {
            return window.confirm(message);
        }

        return (window.prompt(message) ?? '').trim() === expected;
    };

    const onConfirmClick = (event) => {
        const trigger = event.target.closest('[data-eamm-confirm]');
        if (!trigger || confirmed(trigger)) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
    };

    const rangeBoxes = (box) => [...box.closest('table').querySelectorAll('input[type="checkbox"][data-eamm-range]')];

    const onRangeClick = (event) => {
        const box = event.target.closest('input[type="checkbox"][data-eamm-range]');
        if (!box) {
            return;
        }

        const boxes = rangeBoxes(box);
        const anchor = boxes.findIndex((candidate) => candidate.value === lastRangeValue);
        lastRangeValue = box.value;
        if (!event.shiftKey || -1 === anchor) {
            return;
        }

        window.getSelection()?.removeAllRanges();
        const current = boxes.indexOf(box);
        const [start, end] = [Math.min(anchor, current), Math.max(anchor, current)];
        boxes.slice(start, end + 1)
            .filter((other) => other !== box && other.checked !== box.checked)
            .forEach((other) => {
                other.checked = box.checked;
                other.dispatchEvent(new Event('input', { bubbles: true }));
            });
    };

    const onCopyClick = (event) => {
        const button = event.target.closest('[data-eamm-copy]');
        if (button) {
            copy(button, button.dataset.eammCopy);
        }
    };

    const onDismissClick = (event) => {
        event.target.closest('[data-eamm-dismiss]')?.closest('.eamm-notice')?.remove();
    };

    const hasSelectionInside = (root) => {
        const selection = window.getSelection();

        return Boolean(selection && !selection.isCollapsed && root.contains(selection.anchorNode));
    };

    const hasFocusedFieldInside = (root) => {
        const focused = document.activeElement;

        return Boolean(focused && root.contains(focused) && focused.matches('input, select, textarea'));
    };

    const shouldPoll = (root) => !document.hidden
        && !pausedRoots.has(root)
        && !root.hasAttribute('data-eamm-poll-paused')
        && !root.hasAttribute('busy')
        && !hasSelectionInside(root)
        && !hasFocusedFieldInside(root);

    const syncPollToggle = (root) => {
        const toggle = root.querySelector('[data-eamm-poll-toggle]');
        if (!toggle) {
            return;
        }
        const paused = pausedRoots.has(root);
        const label = paused ? toggle.dataset.eammLabelResume : toggle.dataset.eammLabelPause;
        toggle.classList.toggle('is-paused', paused);
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
    };

    const onPollToggleClick = (event) => {
        const root = event.target.closest('[data-eamm-poll-toggle]')?.closest('[data-eamm-poll]');
        if (!root) {
            return;
        }
        if (!pausedRoots.delete(root)) {
            pausedRoots.add(root);
        }
        syncPollToggle(root);
    };

    const syncIndeterminate = (root) => {
        root.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.indeterminate = box.hasAttribute('data-eamm-indeterminate');
        });
    };

    const connectedRoots = new WeakSet();

    const onConnect = (root, component) => {
        if (!component || connectedRoots.has(root)) {
            return;
        }
        connectedRoots.add(root);
        syncIndeterminate(root);
        component.on('render:finished', () => syncIndeterminate(root));
        startPolling(root, component);
    };

    const startPolling = (root, component) => {
        const seconds = Number.parseInt(root.dataset.eammPoll ?? '0', 10);
        if (polledRoots.has(root) || !(seconds > 0)) {
            return;
        }
        polledRoots.add(root);
        component.on('render:finished', () => syncPollToggle(root));
        const timer = setInterval(() => {
            if (!root.isConnected) {
                clearInterval(timer);
                return;
            }
            if (shouldPoll(root)) {
                component.render();
            }
        }, seconds * 1000);
    };

    const liveRoots = () => [...document.querySelectorAll('[data-controller~="live"]')];

    const showSetupAlert = (name) => document.querySelector(`[data-eamm-setup-alert="${name}"]`)?.removeAttribute('hidden');

    const checkSetup = () => {
        if (liveRoots().some((root) => !root.__component)) {
            showSetupAlert('live');
        }
        if (document.querySelector('[data-eamm-json]') && !customElements.get('json-viewer')) {
            showSetupAlert('assets');
        }
    };

    document.addEventListener('click', onConfirmClick, true);
    document.addEventListener('click', onRangeClick);
    document.addEventListener('click', onCopyClick);
    document.addEventListener('click', onDismissClick);
    document.addEventListener('click', onPollToggleClick);
    document.addEventListener('live:connect', (event) => onConnect(event.target, event.detail.component));
    liveRoots().forEach((root) => onConnect(root, root.__component));
    window.addEventListener('load', () => setTimeout(checkSetup, SETUP_CHECK_DELAY));

    window.eamm = { copy };
})();
