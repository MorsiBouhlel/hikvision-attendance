/**
 * Recherche unique pour l'historique (une carte par jour, chacune avec sa
 * propre table [data-table][data-table-search="external"] — voir
 * templates/attendance_history/index.html.twig). Un seul champ pilote
 * toutes les tables via table.dataTableSearch(term) (voir data-table.js),
 * et masque entièrement la carte d'un jour si aucune de ses lignes ne
 * matche — pas de carte vide à scroller.
 */
function initHistorySearch(container) {
    const cards = Array.from(container.querySelectorAll('[data-history-day]'));
    if (cards.length === 0) return;

    const searchWrap = document.createElement('div');
    searchWrap.className = 'data-table__search';
    searchWrap.innerHTML = '<input type="search" class="form-control" placeholder="Rechercher…">';
    container.parentNode.insertBefore(searchWrap, container);

    const input = searchWrap.querySelector('input');
    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        cards.forEach((card) => {
            const table = card.querySelector('table[data-table]');
            const matchCount = table?.dataTableSearch ? table.dataTableSearch(term) : 0;
            card.hidden = matchCount === 0;
        });
    });
}

document.querySelectorAll('[data-history-search]').forEach(initHistorySearch);
