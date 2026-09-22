/**
 * Recherche + tri + pagination générique pour les tableaux marqués [data-table].
 * Colonnes triables: <th data-sort="text|number"> (colonnes sans data-sort, ex. Actions, restent non cliquables).
 * Aucune dépendance, aucun rechargement — tri/filtrage/pagination se font sur
 * les lignes déjà rendues côté serveur (pas de LIMIT/OFFSET SQL).
 */
const PAGE_SIZE = 20;

function initDataTable(table) {
    const wrap = table.closest('.table-wrap');
    if (!wrap) return;

    // data-table-search="external": pas de champ de recherche auto-créé par
    // table — utilisé quand une page pilote plusieurs tables depuis un seul
    // champ commun (voir assets/history-search.js). table.dataTableSearch(term)
    // reste appelable dans ce mode.
    const externalSearch = table.dataset.tableSearch === 'external';

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => !row.hasAttribute('data-table-detail'));

    // Une ligne de détail (ex. pointages bruts d'une journée) reste rattachée
    // à la ligne qui la précède — jamais triée/recherchée/paginée indépendamment.
    const detailFor = (row) => (row.nextElementSibling?.hasAttribute('data-table-detail') ? row.nextElementSibling : null);

    const headers = Array.from(table.querySelectorAll('th[data-sort]'));

    let currentPage = 1;

    // Lignes actuellement filtrées par la recherche, dans leur ordre actuel
    // (après tri) — c'est cet ensemble que la pagination découpe en pages.
    const visibleRows = () => rows.filter((row) => row.dataset.searchHidden !== 'true');

    const applyPagination = () => {
        const matched = visibleRows();
        const pageCount = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        currentPage = Math.min(currentPage, pageCount);

        const start = (currentPage - 1) * PAGE_SIZE;
        const end = start + PAGE_SIZE;

        // Lignes exclues par la recherche: toujours masquées, quelle que
        // soit la page — sinon elles gardent leur dernier display connu.
        rows.forEach((row) => {
            if (row.dataset.searchHidden === 'true') {
                row.style.display = 'none';
                const detail = detailFor(row);
                if (detail) detail.style.display = 'none';
            }
        });

        matched.forEach((row, index) => {
            const onPage = index >= start && index < end;
            row.style.display = onPage ? '' : 'none';

            const detail = detailFor(row);
            if (detail && (!onPage || detail.hidden)) {
                detail.style.display = 'none';
            }
        });

        renderPager(pageCount, matched.length);
        table.dispatchEvent(new CustomEvent('datatable:filtered', { detail: { visibleCount: matched.length } }));
    };

    let pager = null;
    const renderPager = (pageCount, totalMatched) => {
        if (totalMatched === 0) {
            if (pager) pager.hidden = true;
            return;
        }

        if (!pager) {
            pager = document.createElement('div');
            pager.className = 'data-table__pager';
            wrap.parentNode.insertBefore(pager, wrap.nextSibling);
        }

        if (pageCount <= 1) {
            pager.hidden = true;
            return;
        }

        pager.hidden = false;
        pager.innerHTML = '';

        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'btn btn--sm';
        prev.textContent = '‹';
        prev.disabled = currentPage === 1;
        prev.addEventListener('click', () => { currentPage--; applyPagination(); });
        pager.appendChild(prev);

        const status = document.createElement('span');
        status.className = 'data-table__pager-status';
        status.textContent = `${currentPage} / ${pageCount}`;
        pager.appendChild(status);

        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn--sm';
        next.textContent = '›';
        next.disabled = currentPage === pageCount;
        next.addEventListener('click', () => { currentPage++; applyPagination(); });
        pager.appendChild(next);
    };

    const runSearch = (term) => {
        rows.forEach((row) => {
            const match = !term || row.textContent.toLowerCase().includes(term);
            row.dataset.searchHidden = match ? 'false' : 'true';
        });
        currentPage = 1;
        applyPagination();
    };

    if (rows.length > 0 && !externalSearch) {
        const searchWrap = document.createElement('div');
        searchWrap.className = 'data-table__search';
        searchWrap.innerHTML = '<input type="search" class="form-control" placeholder="Rechercher…">';
        wrap.parentNode.insertBefore(searchWrap, wrap);

        const input = searchWrap.querySelector('input');
        input.addEventListener('input', () => runSearch(input.value.trim().toLowerCase()));
    }

    // API exposée pour un pilotage externe (voir history-search.js): renvoie
    // le nombre de lignes qui matchent, pour permettre à l'appelant de
    // masquer un conteneur entier (ex. la carte du jour) si le résultat est 0.
    table.dataTableSearch = (term) => {
        runSearch(term);
        return rows.filter((row) => row.dataset.searchHidden !== 'true').length;
    };

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

            sorted.forEach((row) => {
                tbody.appendChild(row);
                const detail = detailFor(row);
                if (detail) tbody.appendChild(detail);
            });

            currentPage = 1;
            applyPagination();
        };

        th.addEventListener('click', applySort);
        th.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                applySort();
            }
        });
    });

    rows.forEach((row) => { row.dataset.searchHidden = 'false'; });
    applyPagination();
}

document.querySelectorAll('table[data-table]').forEach(initDataTable);
