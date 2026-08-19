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
    initCopyButtons();
});
