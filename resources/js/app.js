// Filters the API reference sidebar + endpoint cards by method/path/group
// text as the user types. Hides whole groups when nothing inside them
// matches, so an empty group heading never lingers on screen.
function initEndpointSearch() {
    const input = document.getElementById('endpoint-search');
    if (!input) return;

    const navItems = document.querySelectorAll('[data-nav-item]');
    const cards = document.querySelectorAll('[data-endpoint-card]');
    const navGroups = document.querySelectorAll('[data-nav-group]');
    const sections = document.querySelectorAll('section[id]');

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();

        navItems.forEach((item) => {
            item.style.display = !query || item.dataset.search.includes(query) ? '' : 'none';
        });

        cards.forEach((card) => {
            card.style.display = !query || card.dataset.search.includes(query) ? '' : 'none';
        });

        navGroups.forEach((group) => {
            const anyVisible = [...group.querySelectorAll('[data-nav-item]')].some(
                (item) => item.style.display !== 'none',
            );
            group.style.display = !query || group.querySelectorAll('[data-nav-item]').length === 0 || anyVisible ? '' : 'none';
        });

        sections.forEach((section) => {
            const cardsInSection = section.querySelectorAll('[data-endpoint-card]');
            if (cardsInSection.length === 0) return; // prose-only sections (Global, Enums) always stay
            const anyVisible = [...cardsInSection].some((card) => card.style.display !== 'none');
            section.style.display = !query || anyVisible ? '' : 'none';
        });
    });
}

// Nav N13 — inline search pill + ⌘K command palette. Reads the flat
// {label, method, group, href} index the layout embeds as JSON and
// renders keyboard-navigable, grouped results client-side.
function initCommandPalette() {
    const pill = document.getElementById('searchpill');
    const root = document.getElementById('cmdk');
    const dataEl = document.getElementById('cmdk-data');
    if (!pill || !root || !dataEl) return;

    const input = document.getElementById('cmdk-input');
    const results = document.getElementById('cmdk-results');
    let index = [];
    try {
        index = JSON.parse(dataEl.textContent);
    } catch {
        index = [];
    }

    let activeIndex = 0;
    let lastFocused = null;

    function render(query) {
        const q = query.trim().toLowerCase();
        const matches = q
            ? index.filter((item) => (item.label + ' ' + item.group).toLowerCase().includes(q))
            : index.filter((item) => item.group === 'Pages');

        results.innerHTML = '';
        activeIndex = 0;

        if (matches.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'cmdk-empty';
            empty.textContent = `No results for “${query}”.`;
            results.appendChild(empty);
            return;
        }

        let currentGroup = null;
        matches.slice(0, 40).forEach((item, i) => {
            if (item.group !== currentGroup) {
                currentGroup = item.group;
                const heading = document.createElement('p');
                heading.className = 'cmdk-group';
                heading.textContent = currentGroup;
                results.appendChild(heading);
            }

            const button = document.createElement('a');
            button.href = item.href;
            button.className = 'cmdk-item' + (i === 0 ? ' is-active' : '');
            button.dataset.index = String(i);
            button.innerHTML = `
                <span class="cmdk-item-method">${item.method === 'PAGE' ? '' : item.method}</span>
                <span class="cmdk-item-label">${item.method === 'PAGE' ? item.label : item.label.slice(item.method.length + 1)}</span>
                <span class="cmdk-item-group">${item.group}</span>
            `;
            results.appendChild(button);
        });
    }

    function items() {
        return [...results.querySelectorAll('.cmdk-item')];
    }

    function setActive(i) {
        const all = items();
        if (all.length === 0) return;
        activeIndex = (i + all.length) % all.length;
        all.forEach((el, idx) => el.classList.toggle('is-active', idx === activeIndex));
        all[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function open() {
        lastFocused = document.activeElement;
        root.classList.add('is-open');
        root.setAttribute('aria-hidden', 'false');
        pill.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        input.value = '';
        render('');
        requestAnimationFrame(() => input.focus());
    }

    function close() {
        root.classList.remove('is-open');
        root.setAttribute('aria-hidden', 'true');
        pill.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (lastFocused instanceof HTMLElement) lastFocused.focus();
    }

    pill.addEventListener('click', open);

    root.querySelectorAll('[data-cmdk-close]').forEach((el) => el.addEventListener('click', close));

    document.addEventListener('keydown', (event) => {
        const isOpen = root.classList.contains('is-open');
        const cmdK = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';

        if (cmdK) {
            event.preventDefault();
            isOpen ? close() : open();
            return;
        }

        if (!isOpen) return;

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(activeIndex - 1);
        } else if (event.key === 'Enter') {
            const active = items()[activeIndex];
            if (active) {
                event.preventDefault();
                window.location.href = active.getAttribute('href');
            }
        }
    });

    input.addEventListener('input', () => render(input.value));
}

function initCopyButtons() {
    document.querySelectorAll('.copy-path-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = button.dataset.copy;
            const original = button.textContent;

            try {
                await navigator.clipboard.writeText(text);
                button.textContent = 'Copied';
            } catch {
                button.textContent = 'Copy failed';
            }

            setTimeout(() => {
                button.textContent = original;
            }, 1500);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initEndpointSearch();
    initCommandPalette();
    initCopyButtons();
});
