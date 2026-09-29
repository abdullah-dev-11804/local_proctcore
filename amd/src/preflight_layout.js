// This file is part of Moodle - http://moodle.org/

/**
 * Keeps the Start attempt dialog's rules and acknowledgement in the right place.
 *
 * @module local_proctorcore/preflight_layout
 */
define([], function() {
    const bound = new WeakSet();
    const guardedForms = new WeakSet();
    let scheduled = false;
    let initialized = false;
    let rulesRequiredMessage = '';

    const clearRulesError = (checkbox, field) => {
        checkbox.setCustomValidity('');
        checkbox.removeAttribute('aria-invalid');
        field.classList.remove('has-danger');
        const feedback = field.querySelector('[data-proctorcore-rules-error]');
        if (feedback) {
            feedback.hidden = true;
        }
    };

    const guardRulesAcknowledgement = (form, checkbox, field) => {
        if (guardedForms.has(form)) {
            return;
        }
        guardedForms.add(form);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
                clearRulesError(checkbox, field);
            }
        });
        form.addEventListener('submit', event => {
            if (checkbox.checked) {
                clearRulesError(checkbox, field);
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            checkbox.setCustomValidity(rulesRequiredMessage || 'Please acknowledge the exam rules before starting.');
            checkbox.setAttribute('aria-invalid', 'true');
            field.classList.add('has-danger');
            let feedback = field.querySelector('[data-proctorcore-rules-error]');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.className = 'invalid-feedback d-block local-proctorcore-rules-error';
                feedback.dataset.proctorcoreRulesError = 'true';
                feedback.setAttribute('role', 'alert');
                field.appendChild(feedback);
            }
            feedback.textContent = checkbox.validationMessage;
            feedback.hidden = false;
            field.scrollIntoView({behavior: 'smooth', block: 'center'});
            checkbox.focus({preventScroll: true});
            checkbox.reportValidity();
        }, true);
    };

    const updateState = (form, checks) => {
        form.classList.toggle('local-proctorcore-checks-open', checks.open);
        form.classList.toggle('local-proctorcore-checks-closed', !checks.open);
    };

    const arrange = popup => {
        const form = popup.querySelector('#mod_quiz_preflight_form');
        if (!form) {
            return;
        }
        const checks = form.querySelector('.local-proctorcore-precheck-side');
        const details = form.querySelector('.local-proctorcore-preflight-details');
        const panel = form.querySelector('.local-proctorcore-precheck');
        if (checks && details && panel) {
            let column = panel.querySelector('.local-proctorcore-preflight-right-column');
            if (!column) {
                column = document.createElement('div');
                column.className = 'local-proctorcore-preflight-right-column';
                panel.appendChild(column);
            }
            // The column is the desktop overflow region; make keyboard scrolling available.
            column.tabIndex = 0;
            // Keep Checks inside the panel: precheck.js updates its rows there.
            if (checks.parentElement !== column) {
                column.appendChild(checks);
            }
            if (details.parentElement !== column) {
                column.appendChild(details);
            }
        }
        if (checks) {
            if (!bound.has(checks)) {
                checks.addEventListener('toggle', () => updateState(form, checks));
                bound.add(checks);
            }
            updateState(form, checks);
        }

        const timer = form.querySelector('#id_honestycheckheader');
        const checkbox = form.querySelector('#id_proctorcore_rules_ack');
        const field = checkbox && (checkbox.closest('#fitem_id_proctorcore_rules_ack')
            || checkbox.closest('.fitem') || checkbox.closest('.form-check'));
        if (!timer || !field) {
            return;
        }
        // Move Moodle's original field, including its label and validation message.
        // It remains inside the original form and retains its listeners and value.
        if (timer.nextElementSibling !== field) {
            timer.insertAdjacentElement('afterend', field);
        }
        field.classList.add('local-proctorcore-rules-acknowledgement');
        guardRulesAcknowledgement(form, checkbox, field);
        form.classList.add('local-proctorcore-ui-ready');
    };

    const findDialogs = () => {
        document.querySelectorAll('.mod_quiz_preflight_popup').forEach(arrange);
    };

    return {
        init: function(config = {}) {
            rulesRequiredMessage = config.rulesRequired || rulesRequiredMessage;
            if (initialized) {
                return;
            }
            initialized = true;
            findDialogs();
            // Moodle may add or rebuild the dialog after this AMD call executes.
            // Check once per animation frame while the current page is open.
            const observer = new MutationObserver(() => {
                if (scheduled) {
                    return;
                }
                scheduled = true;
                window.requestAnimationFrame(() => {
                    scheduled = false;
                    findDialogs();
                });
            });
            observer.observe(document.body, {childList: true, subtree: true});
            window.addEventListener('pagehide', () => observer.disconnect(), {once: true});
        },
    };
});
