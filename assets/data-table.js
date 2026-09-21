/**
 * Recherche + tri générique pour les tableaux marqués [data-table].
 * Colonnes triables: <th data-sort="text|number"> (colonnes sans data-sort, ex. Actions, restent non cliquables).
 * Aucune dépendance, aucun rechargement — tri/filtrage se font sur les lignes déjà rendues côté serveur.
 */
function initDataTable(table) {
    const wrap = table.closest('.table-wrap');
    if (!wrap) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    const headers = Array.from(table.querySelectorAll('th[data-sort]'));

    if (rows.length > 0) {
        const searchWrap = document.createElement('div');
        searchWrap.className = 'data-table__search';
        searchWrap.innerHTML = '<input type="search" class="form-control" placeholder="Rechercher…">';
        wrap.parentNode.insertBefore(searchWrap, wrap);

        const input = searchWrap.querySelector('input');
        input.addEventListener('input', () => {
            const term = input.value.trim().toLowerCase();
            let visibleCount = 0;
            rows.forEach((row) => {
                const match = !term || row.textContent.toLowerCase().includes(term);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            table.dispatchEvent(new CustomEvent('datatable:filtered', { detail: { visibleCount } }));
        });
    }

    headers.forEach((th) => {
        const index = Array.from(th.parentNode.children).indexOf(th);
        const type = th.dataset.sort;

        th.classList.add('is-sortable');
        th.setAttribute('role', 'button');
        th.setAttribute('tabindex', '0');

        let direction = null;

        const applySort = () => {
            direction = direction === 'asc' ? 'desc' : 'asc';

            headers.forEach((other) => other.classList.remove('is-sorted-asc', 'is-sorted-desc'));
            th.classList.add(direction === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');

            const factor = direction === 'asc' ? 1 : -1;

            const sorted = rows.slice().sort((a, b) => {
                const cellEl_A = a.children[index];
                const cellEl_B = b.children[index];
                const cellA = cellEl_A?.dataset.sortValue ?? cellEl_A?.textContent.trim() ?? '';
                const cellB = cellEl_B?.dataset.sortValue ?? cellEl_B?.textContent.trim() ?? '';

                if (type === 'number') {
                    const numA = parseFloat(cellA.replace(',', '.')) || 0;
                    const numB = parseFloat(cellB.replace(',', '.')) || 0;
                    return (numA - numB) * factor;
                }

                return cellA.localeCompare(cellB, 'fr', { sensitivity: 'base' }) * factor;
            });

            sorted.forEach((row) => tbody.appendChild(row));
        };

        th.addEventListener('click', applySort);
        th.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                applySort();
            }
        });
    });
}

document.querySelectorAll('table[data-table]').forEach(initDataTable);
