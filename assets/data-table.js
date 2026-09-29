/**
 * Recherche + tri + pagination générique pour les tableaux marqués [data-table].
 * Colonnes triables: <th data-sort="text|number"> (colonnes sans data-sort, ex. Actions, restent non cliquables).
 * Aucune dépendance — tri/filtrage/pagination se font sur les lignes déjà
 * rendues côté serveur (pas de LIMIT/OFFSET SQL). La page courante et la
 * position de scroll sont tout de même mémorisées en sessionStorage (voir
 * stateStorageKey) pour survivre à un rechargement classique déclenché par
 * une action sur une ligne (ex. activer/désactiver un employé en page 2 via
 * un POST + redirect) — sans ça, chaque action ramène l'utilisateur en haut
 * de la page 1, ce qui est particulièrement pénible en cas d'actions
 * répétées ligne par ligne.
 */
import { carrySearchToFilterForm } from './search-carry.js';

const PAGE_SIZE = 20;

// Indexée par URL + position du tableau dans la page, pour rester correcte
// si plusieurs [data-table] coexistent sur une même vue.
const stateStorageKey = (index) => `data-table-state:${location.pathname}:${index}`;

function initDataTable(table, index) {
    const wrap = table.closest('.table-wrap');
    if (!wrap) return;

    // data-table-search="external": pas de champ de recherche auto-créé par
    // table — utilisé quand une page pilote plusieurs tables depuis un seul
    // champ commun (voir assets/history-search.js). table.dataTableSearch(term)
    // reste appelable dans ce mode.
    const externalSearch = table.dataset.tableSearch === 'external';

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    // Mutable: réordonné en place après un tri (voir applySort) pour que la
    // pagination, qui découpe cet ordre-ci et non l'ordre DOM, reste cohérente.
    // ":scope > tr" (et non querySelectorAll('tr') seul) — sinon les <tr>
    // d'une table imbriquée dans une ligne de détail (ex. le tableau des
    // jours d'un employé dans le rapport, ou celui des pointages d'un jour)
    // seraient aussi ramassées ici et fausseraient pagination/tri/recherche
    // du tableau parent.
    let rows = Array.from(tbody.querySelectorAll(':scope > tr')).filter((row) => !row.hasAttribute('data-table-detail'));

    // Une ligne de détail (ex. pointages bruts d'une journée) reste rattachée
    // à la ligne qui la précède — jamais triée/recherchée/paginée indépendamment.
    const detailFor = (row) => (row.nextElementSibling?.hasAttribute('data-table-detail') ? row.nextElementSibling : null);

    // Même précaution que pour "rows" — ne cible que les en-têtes du thead
    // de ce tableau-ci, pas ceux d'une éventuelle table imbriquée.
    const headers = Array.from(table.querySelectorAll(':scope > thead th[data-sort]'));

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

    // Un pager en haut (avant .table-wrap) et un en bas (après) — mêmes
    // contrôles, même état. Changer de page depuis l'un ou l'autre doit
    // aussi remonter en haut de la page : sans ça, un clic sur le pager du
    // bas laisse l'utilisateur scrollé en bas, face à des lignes qu'il n'a
    // pas vu apparaître.
    let pagerTop = null;
    let pagerBottom = null;

    const goToPage = (page) => {
        currentPage = page;
        applyPagination();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // Sauvegarde page + scroll juste avant qu'une action de ligne (ex.
    // activer/désactiver) ne recharge la page via son propre POST — ce
    // n'est pas goToPage qui appelle ceci, ce clic-ci ne doit provoquer
    // aucun scroll, seulement mémoriser où on est pour s'y replacer après
    // coup à l'identique (voir la restauration en bas de initDataTable).
    const saveStateBeforeReload = () => {
        try {
            sessionStorage.setItem(stateStorageKey(index), JSON.stringify({ page: currentPage, scrollY: window.scrollY }));
        } catch {
            // sessionStorage indisponible (navigation privée, etc.) — l'état
            // ne sera simplement pas restauré après le rechargement.
        }
    };

    // N'importe quel <form> soumis depuis une ligne du tableau (activer,
    // désactiver, supprimer…) compte comme une action de ligne — capté au
    // niveau du tbody plutôt que par bouton, pour couvrir toute action
    // future sans avoir à la câbler explicitement ici.
    tbody.addEventListener('submit', saveStateBeforeReload, true);

    const fillPager = (pager, pageCount) => {
        pager.innerHTML = '';

        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'btn btn--sm';
        prev.textContent = '‹';
        prev.disabled = currentPage === 1;
        prev.addEventListener('click', () => goToPage(currentPage - 1));
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
        next.addEventListener('click', () => goToPage(currentPage + 1));
        pager.appendChild(next);
    };

    const renderPager = (pageCount, totalMatched) => {
        if (totalMatched === 0 || pageCount <= 1) {
            if (pagerTop) pagerTop.hidden = true;
            if (pagerBottom) pagerBottom.hidden = true;
            return;
        }

        if (!pagerTop) {
            pagerTop = document.createElement('div');
            pagerTop.className = 'data-table__pager data-table__pager--top';
            wrap.parentNode.insertBefore(pagerTop, wrap);
        }
        if (!pagerBottom) {
            pagerBottom = document.createElement('div');
            pagerBottom.className = 'data-table__pager';
            wrap.parentNode.insertBefore(pagerBottom, wrap.nextSibling);
        }

        pagerTop.hidden = false;
        pagerBottom.hidden = false;
        fillPager(pagerTop, pageCount);
        fillPager(pagerBottom, pageCount);
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

        // Reprend le terme depuis ?q= (posé par un précédent submit du
        // filtre from/to/department — voir search-carry.js et
        // filter-autosubmit.js) pour que changer l'intervalle de dates ne
        // fasse pas perdre ce que l'utilisateur avait tapé.
        const initialTerm = new URLSearchParams(location.search).get('q') || '';
        if (initialTerm) {
            input.value = initialTerm;
            runSearch(initialTerm.toLowerCase());
        }

        carrySearchToFilterForm(input);
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

            rows = sorted;

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

    // Restaure l'état mémorisé avant le dernier rechargement déclenché par
    // une action de ligne (voir saveStateBeforeReload) — lu une seule fois,
    // puis effacé : un simple retour sur la page plus tard (nav, favori…)
    // doit repartir de la page 1 en haut, pas recoller indéfiniment sur une
    // ancienne position.
    let savedScrollY = null;
    try {
        const raw = sessionStorage.getItem(stateStorageKey(index));
        sessionStorage.removeItem(stateStorageKey(index));
        if (raw) {
            const saved = JSON.parse(raw);
            if (saved.page > 1) currentPage = saved.page;
            if (typeof saved.scrollY === 'number') savedScrollY = saved.scrollY;
        }
    } catch {
        // sessionStorage indisponible ou entrée corrompue — reste en page 1, comportement inchangé.
    }

    applyPagination();

    // Repositionne le scroll exactement où l'utilisateur était avant son
    // action — sans transition (pas de smooth), pour qu'aucun mouvement ne
    // soit visible : le rechargement doit sembler ne pas avoir bougé la vue.
    if (savedScrollY !== null) {
        window.scrollTo({ top: savedScrollY });
    }
}

document.querySelectorAll('table[data-table]').forEach((table, index) => initDataTable(table, index));
