/**
 * Sélection rapide par département: cocher/décocher un département dans
 * [data-department-picker] coche/décoche tous les employés de ce
 * département dans [data-employee-list] — simple raccourci de
 * pré-remplissage, chaque case employé reste ensuite modifiable
 * individuellement avant la soumission (aucune logique côté serveur).
 */
function initDepartmentPicker(picker) {
    const form = picker.closest('form');
    if (!form) return;

    const employeeList = form.querySelector('[data-employee-list]');
    if (!employeeList) return;

    picker.querySelectorAll('[data-department-toggle]').forEach((toggle) => {
        toggle.addEventListener('change', () => {
            const departmentId = toggle.dataset.departmentToggle;
            const checkboxes = employeeList.querySelectorAll(`input[data-department-id="${departmentId}"]`);
            checkboxes.forEach((checkbox) => {
                checkbox.checked = toggle.checked;
                checkbox.dispatchEvent(new Event('change'));
            });
        });
    });
}

document.querySelectorAll('[data-department-picker]').forEach(initDepartmentPicker);
