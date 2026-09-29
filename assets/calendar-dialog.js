/**
 * Détail au clic sur une cellule du calendrier : les événements sont déjà rendus côté
 * serveur (blocs cachés [data-cal-event]) ; on copie ceux de la cellule dans le <dialog>.
 */
const dialog = document.getElementById('cal-dialog');

if (dialog) {
    const title = dialog.querySelector('[data-cal-dialog-title]');
    const body = dialog.querySelector('[data-cal-dialog-body]');

    document.querySelectorAll('[data-cal-events]').forEach((cell) => {
        cell.addEventListener('click', () => {
            title.textContent = cell.dataset.calDate;
            body.replaceChildren();
            cell.dataset.calEvents.split(' ').forEach((key) => {
                const source = document.querySelector(`[data-cal-event="${key}"]`);
                if (source) body.appendChild(source.cloneNode(true));
            });
            dialog.showModal();
        });
    });

    dialog.querySelector('[data-cal-dialog-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) dialog.close();
    });
}
