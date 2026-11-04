(() => {
    let lastValue = null;

    const rangeBoxes = (box) => [...box.closest('table').querySelectorAll('input[type="checkbox"][data-eamm-range]')];

    document.addEventListener('click', (event) => {
        const box = event.target.closest('input[type="checkbox"][data-eamm-range]');
        if (!box) {
            return;
        }

        const boxes = rangeBoxes(box);
        const anchor = boxes.findIndex((candidate) => candidate.value === lastValue);
        lastValue = box.value;
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
    });
})();
