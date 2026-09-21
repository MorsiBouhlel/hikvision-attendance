/**
 * Recherche générique pour les listes de checkboxes marquées [data-checkbox-search].
 * Aucune dépendance — filtre les <label class="checkbox-row"> déjà rendues côté serveur.
 */
function initCheckboxSearch(list) {
    const rows = Array.from(list.querySelectorAll('.checkbox-row'));
    if (rows.length === 0) return;

    const searchWrap = document.createElement('div');
    searchWrap.className = 'checkbox-list__search';
    searchWrap.innerHTML = '<input type="search" class="form-control" placeholder="Rechercher un employé…">';
    list.parentNode.insertBefore(searchWrap, list);

    const input = searchWrap.querySelector('input');
    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        rows.forEach((row) => {
            const match = !term || row.textContent.toLowerCase().includes(term);
            row.style.display = match ? '' : 'none';
        });
    });
}

document.querySelectorAll('[data-checkbox-search]').forEach(initCheckboxSearch);
