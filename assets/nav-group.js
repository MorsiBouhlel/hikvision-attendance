/**
 * Groupe de nav repliable/dépliable (ex: "Configuration"). Replié par
 * défaut, sauf si la page courante appartient déjà au groupe (classe
 * is-open posée côté serveur dans base.html.twig). L'état est mémorisé en
 * localStorage pour rester cohérent d'une page à l'autre.
 */
function initNavGroupToggle(toggle) {
    const key = 'nav-group-open:' + (toggle.textContent.trim() || 'default');
    const group = toggle.nextElementSibling;
    if (!group || !group.hasAttribute('data-nav-group')) return;

    const stored = localStorage.getItem(key);
    const isOpen = stored !== null ? stored === '1' : toggle.classList.contains('is-open');

    toggle.classList.toggle('is-open', isOpen);
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    group.classList.toggle('is-open', isOpen);

    toggle.addEventListener('click', () => {
        const nowOpen = !group.classList.contains('is-open');
        toggle.classList.toggle('is-open', nowOpen);
        toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
        group.classList.toggle('is-open', nowOpen);
        localStorage.setItem(key, nowOpen ? '1' : '0');
    });
}

document.querySelectorAll('[data-nav-group-toggle]').forEach(initNavGroupToggle);
