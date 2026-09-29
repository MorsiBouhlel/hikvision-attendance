/**
 * Soumet automatiquement les formulaires marqués [data-autosubmit] dès qu'un
 * champ change — évite un bouton "Afficher" pour de simples filtres (dates,
 * département). Le <noscript><button>...</button></noscript> associé sert de
 * repli si JS est désactivé.
 */
function initFilterAutosubmit(form) {
    form.querySelectorAll('input, select').forEach((field) => {
        field.addEventListener('change', () => form.requestSubmit());
    });
}

document.querySelectorAll('[data-autosubmit]').forEach(initFilterAutosubmit);
