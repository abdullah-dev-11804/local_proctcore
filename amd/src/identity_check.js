// This file is part of Moodle - http://moodle.org/

/**
 * Section 1.2 pre-attempt identity verification and simple active liveness challenge.
 *
 * @module local_proctorcore/identity_check
 */
define([], function() {
    'use strict';

    const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));
    const field = name => document.querySelector(`[name="${name}"]`);

    const setField = (name, value) => {
        const input = field(name);
        if (input) {
            input.value = String(value);
        }
    };

    const submitButtons = panel => {
        const form = panel.closest('form');
        return form ? Array.from(form.querySelectorAll(
            'button[type="submit"].btn-primary, input[type="submit"].btn-primary, ' +
            'button[name="submitbutton"], input[name="submitbutton"], ' +
            'button[name="startattempt"], input[name="startattempt"]'
        )) : [];
    };

    const enableSubmit = (panel, enabled) => {
        submitButtons(panel).forEach(button => {
            button.disabled = !enabled;
            button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        });
    };

    const update = (panel, state, text) => {
        panel.className = `local-proctorcore-identity is-${state}`;
        const status = panel.querySelector('[data-identity-status]');
        if (status) {
            status.textContent = text;
        }
    };

    const capture = () => {
        if (!window.ProctorCorePrecheck || typeof window.ProctorCorePrecheck.captureJpeg !== 'function') {
            throw new Error('Camera preview is unavailable. Run the equipment check again.');
        }
        return window.ProctorCorePrecheck.captureJpeg(0.9);
    };

    const post = async(config, images) => {
        const token = field('proctorcore_preflight_token');
        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                sesskey: config.sesskey,
                quizId: Number(config.quizId),
                token: token ? token.value : '',
                centerImage: images.center,
                leftImage: images.left,
                rightImage: images.right,
            }),
        });
        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {ok: false, error: config.strings.invalidResponse};
        }
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || config.strings.failed);
        }
        return data;
    };

    const runChallenge = async(config, panel, button) => {
        button.disabled = true;
        enableSubmit(panel, false);
        setField('proctorcore_identity_passed', 0);
        setField('proctorcore_identity_status', 'running');
        setField('proctorcore_identity_score', '');

        try {
            update(panel, 'running', config.strings.lookStraight);
            await sleep(1600);
            const center = capture();

            update(panel, 'running', config.strings.turnLeft);
            await sleep(2200);
            const left = capture();

            update(panel, 'running', config.strings.turnRight);
            await sleep(2200);
            const right = capture();

            update(panel, 'running', config.strings.comparing);
            const result = await post(config, {center, left, right});
            setField('proctorcore_identity_status', result.result || 'failed');
            setField('proctorcore_identity_score', result.similarityScore ?? '');
            setField('proctorcore_identity_passed', result.passed ? 1 : 0);

            if (result.passed) {
                update(panel, 'passed', `${config.strings.passed} (${Number(result.similarityScore || 0).toFixed(3)})`);
                enableSubmit(panel, true);
            } else {
                update(panel, 'failed', result.message || config.strings.failed);
                button.disabled = false;
            }
        } catch (error) {
            setField('proctorcore_identity_status', 'error');
            update(panel, 'failed', error.message || config.strings.failed);
            button.disabled = false;
        }
    };

    return {
        /**
         * @param {Object} config Moodle configuration.
         */
        init: function(config) {
            const panel = document.getElementById(config.panelId);
            if (!panel) {
                return;
            }
            const button = panel.querySelector('[data-identity-start]');
            if (!button) {
                return;
            }
            if (!config.required) {
                setField('proctorcore_identity_passed', 1);
                setField('proctorcore_identity_status', 'notrequired');
                update(panel, 'passed', config.strings.notRequired);
                return;
            }

            enableSubmit(panel, false);
            if (!config.mlHealthy) {
                update(panel, 'failed', config.strings.serviceUnavailable);
                button.disabled = true;
                return;
            }
            update(panel, 'waiting', config.strings.waitingForPrecheck);
            button.disabled = true;
            button.addEventListener('click', () => runChallenge(config, panel, button));

            const applyPrecheckState = passed => {
                button.disabled = !passed;
                enableSubmit(panel, false);
                update(panel, passed ? 'ready' : 'waiting',
                    passed ? config.strings.ready : config.strings.waitingForPrecheck);
            };

            window.addEventListener('proctorcore:precheckcomplete', event => {
                applyPrecheckState(Boolean(event.detail && event.detail.passed));
            });

            // AMD modules can initialise in either order. Reuse the most recent
            // equipment-check result when it finished before this listener was bound.
            if (window.ProctorCorePrecheck && window.ProctorCorePrecheck.lastResult) {
                applyPrecheckState(Boolean(window.ProctorCorePrecheck.lastResult.passed));
            }
        },
    };
});
