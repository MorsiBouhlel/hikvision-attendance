/**
 * Déplie/replie la ligne de détail (pointages bruts) sous une ligne de jour
 * marquée [data-day-toggle]. La ligne de détail est son sibling suivant
 * marqué [data-table-detail].
 * Si la ligne porte data-punches-url, le contenu du détail est chargé en
 * AJAX au premier clic (fragment HTML pré-rendu par le serveur, voir
 * attendance/_punches_table.html.twig) et mis en cache pour les clics
 * suivants — utilisé par le dashboard/historique où les pointages ne sont
 * pas préchargés pour tous les employés affichés.
 */
function initDayToggle(row) {
    const detail = row.nextElementSibling;
    if (!detail || !detail.hasAttribute('data-table-detail')) return;

    const chevron = row.querySelector('.js-day-chevron');
    const url = row.dataset.punchesUrl;
    const slot = detail.querySelector('.js-punches-slot');
    let loaded = false;

    row.addEventListener('click', () => {
        const expanded = !detail.hidden;
        detail.hidden = expanded;
        detail.style.display = expanded ? 'none' : '';
        if (chevron) chevron.textContent = expanded ? '▸' : '▾';

        if (!expanded && url && slot && !loaded) {
            loaded = true;
            fetch(url)
                .then((response) => {
                    if (!response.ok) throw new Error('http ' + response.status);
                    return response.text();
                })
                .then((html) => { slot.innerHTML = html; })
                .catch(() => {
                    loaded = false;
                    slot.innerHTML = '<p class="text-muted">Erreur de chargement.</p>';
                });
        }
    });
}

document.querySelectorAll('[data-day-toggle]').forEach(initDayToggle);
