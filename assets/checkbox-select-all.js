/**
 * Case "Tout sélectionner" pour les listes de checkboxes marquées
 * [data-checkbox-select-all]. Aucune dépendance — coche/décoche tous les
 * <input type="checkbox"> des .checkbox-row déjà rendues côté serveur,
 * y compris celles masquées par une recherche active (data-checkbox-search).
 */
function initCheckboxSelectAll(list) {
    const checkboxes = Array.from(list.querySelectorAll('.checkbox-row input[type="checkbox"]'));
    if (checkboxes.length === 0) return;

    const wrap = document.createElement('label');
    wrap.className = 'checkbox-row checkbox-row--select-all';
    wrap.innerHTML = '<input type="checkbox"><span>Tout sélectionner</span>';
    list.parentNode.insertBefore(wrap, list);

    const selectAll = wrap.querySelector('input');

    selectAll.addEventListener('change', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
    });

    const syncSelectAllState = () => {
        selectAll.checked = checkboxes.every((checkbox) => checkbox.checked);
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncSelectAllState));
    syncSelectAllState();
}

document.querySelectorAll('[data-checkbox-select-all]').forEach(initCheckboxSelectAll);
