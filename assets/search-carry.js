/**
 * Combine le champ de recherche (data-table.js / history-search.js) avec le
 * filtre from/to/department (formulaire [data-autosubmit], voir
 * filter-autosubmit.js) sur les pages historique et rapports : sans ça,
 * changer l'intervalle de dates soumet ce formulaire et recharge la page,
 * ce qui efface le mot tapé dans "Rechercher" (recherche purement
 * client-side, jamais envoyée au serveur autrement).
 *
 * Le champ caché <input type="hidden" name="q" data-search-carry> du
 * formulaire est synchronisé à chaque frappe, pour que le prochain submit
 * (déclenché par un changement de date/département) reparte avec ?q=...
 * dans l'URL — lu au chargement par l'appelant via new URLSearchParams
 * pour ré-appliquer le filtre texte.
 */
export function carrySearchToFilterForm(searchInput) {
    const hidden = document.querySelector('input[data-search-carry]');
    if (!hidden) return;

    hidden.value = searchInput.value;
    searchInput.addEventListener('input', () => {
        hidden.value = searchInput.value;
    });
}
