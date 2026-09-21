function initWeekPlanRow(row) {
    const restCheckbox = row.querySelector('[data-week-plan-rest]');
    const timeInputs = row.querySelectorAll('[data-week-plan-time]');
    if (!restCheckbox || !timeInputs.length) return;

    const sync = () => {
        timeInputs.forEach((input) => { input.disabled = restCheckbox.checked; });
    };

    restCheckbox.addEventListener('change', sync);
    sync();
}

document.querySelectorAll('[data-week-plan-row]').forEach(initWeekPlanRow);
