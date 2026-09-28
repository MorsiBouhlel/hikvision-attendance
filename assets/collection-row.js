// Ajout/suppression de lignes pour un CollectionType Symfony rendu avec
// allow_add/allow_delete — générique, pas spécifique aux pauses de
// département (data-prototype vient directement du form_widget rendu par
// Symfony, voir DepartmentType::breaks).
function initCollection(container) {
    const list = container.querySelector('[data-collection-list]');
    const addButton = container.querySelector('[data-collection-add]');
    if (!list || !addButton) return;

    const prototype = list.dataset.prototype;

    addButton.addEventListener('click', () => {
        const index = list.dataset.index || list.children.length;
        const html = prototype.replace(/__name__/g, index);
        const row = document.createElement('div');
        row.className = 'collection-row';
        row.innerHTML = html;
        list.appendChild(row);
        list.dataset.index = Number(index) + 1;
    });

    list.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-collection-remove]');
        if (!removeButton) return;
        removeButton.closest('.collection-row')?.remove();
    });
}

document.querySelectorAll('[data-collection]').forEach(initCollection);
