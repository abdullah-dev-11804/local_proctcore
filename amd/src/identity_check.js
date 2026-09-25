// This file is part of Moodle - http://moodle.org/

/**
 * Section 1.2 pre-attempt identity enrollment/verification.
 *
 * @module local_proctorcore/identity_check
 */
define([], function() {
    'use strict';

    let cameraPreviewError = '';
    const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));
    const field = name => document.querySelector(`[name="${name}"]`);

    const setField = (name, value) => {
        const input = field(name);
        if (input) {
            input.value = String(value);
        }
    };

    const setParticipantFieldsVisible = visible => {
        document.querySelectorAll('[data-proctorcore-participant-fields]').forEach(section => {
            section.classList.toggle('is-waiting', !visible);
            section.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
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

    const updateQualityFeedback = (panel, state, text) => {
        const feedback = panel.querySelector('[data-identity-quality-feedback]');
        if (!feedback) {
            return;
        }
        feedback.hidden = !text;
        feedback.className = `local-proctorcore-identity-quality is-${state}`;
        feedback.textContent = text || '';
    };

    const capture = () => {
        if (!window.ProctorCorePrecheck || typeof window.ProctorCorePrecheck.captureJpeg !== 'function') {
            throw new Error(cameraPreviewError);
        }
        return window.ProctorCorePrecheck.captureJpeg(0.96, 1440);
    };

    const captureFrames = async(count, intervalMs) => {
        const frames = [];
        for (let index = 0; index < count; index++) {
            frames.push(capture());
            await sleep(intervalMs);
        }
        return frames;
    };

    const post = async(config, payload) => {
        const token = field('proctorcore_preflight_token');
        let response;
        try {
            response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    sesskey: config.sesskey,
                    quizId: Number(config.quizId),
                    token: token ? token.value : '',
                    ...payload,
                }),
            });
        } catch (error) {
            window.console.warn('ProctorCore identity request failed:', error);
            throw new Error(config.strings.serviceUnavailable);
        }
        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {ok: false, error: config.strings.invalidResponse};
        }
        if (!response.ok || data.ok === false) {
            throw new Error(data.message || data.error || config.strings.failed);
        }
        return data;
    };

    const issueChallenge = config => post(config, {action: 'issueChallenge'});

    const waitForChallengeReady = async(config, panel, challenge) => {
        let latest = null;
        const correctionDeadline = Date.now() + 20000;
        updateQualityFeedback(panel, 'checking', config.strings.qualityChecking);
        while (Date.now() < correctionDeadline) {
            latest = await post(config, {
                action: 'checkChallengeFrame',
                challengeId: challenge.challengeId || '',
                challengeNonce: challenge.nonce || '',
                image: captureJpegForLiveness(),
            });
            const message = latest.message || config.strings.lookStraight;
            update(panel, 'running', config.strings.qualityChecking);
            updateQualityFeedback(panel, latest.ready ? 'ready' : 'warning', message);
            if (latest.ready) {
                update(panel, 'running', config.strings.qualityReady || message);
                updateQualityFeedback(panel, 'ready', config.strings.qualityReady || message);
                return;
            }
            await sleep(650);
        }
        const reason = (latest && latest.message) || config.strings.failed;
        throw new Error(`${reason} ${config.strings.qualityTimeout || ''}`.trim());
    };

    const movementLabel = (config, action) => {
        if (action === 'left') {
            return config.strings.turnLeft;
        }
        if (action === 'right') {
            return config.strings.turnRight;
        }
        return config.strings.lookStraight;
    };

    const createPoseSampler = () => {
        const frames = [];
        let captureError = null;
        const capture = () => {
            if (!window.ProctorCorePrecheck || typeof window.ProctorCorePrecheck.captureJpeg !== 'function') {
                captureError = new Error(config.strings.cameraPreviewUnavailable || config.strings.failed);
                return;
            }
            try {
                frames.push(window.ProctorCorePrecheck.captureJpeg(0.80, 560));
                if (frames.length > 40) {
                    frames.shift();
                }
            } catch (error) {
                captureError = error;
            }
        };
        capture();
        const timer = window.setInterval(capture, 140);
        return {
            nextBatch: async() => {
                const deadline = performance.now() + 700;
                while (frames.length < 3 && performance.now() < deadline && !captureError) {
                    await sleep(40);
                }
                if (captureError) {
                    throw captureError;
                }
                if (!frames.length) {
                    capture();
                }
                return frames.splice(0, 8);
            },
            stop: () => window.clearInterval(timer),
        };
    };

    const captureLivenessEvidence = async(config, panel, challenge) => {
        const evidence = [];
        const preview = document.querySelector('[data-precheck-preview]')
            || document.querySelector('.local-proctorcore-precheck-preview');
        const illumination = document.createElement('div');
        illumination.className = 'local-proctorcore-liveness-illumination';
        illumination.setAttribute('aria-hidden', 'true');
        const prompt = document.createElement('div');
        prompt.className = 'local-proctorcore-liveness-prompt is-center';
        prompt.setAttribute('role', 'status');
        prompt.setAttribute('aria-live', 'assertive');
        prompt.innerHTML = '<strong data-liveness-instruction></strong>' +
            '<span data-liveness-hint></span>' +
            '<span class="local-proctorcore-liveness-progress" aria-hidden="true"><i></i></span>';
        const instruction = prompt.querySelector('[data-liveness-instruction]');
        const hint = prompt.querySelector('[data-liveness-hint]');
        const progress = prompt.querySelector('.local-proctorcore-liveness-progress i');
        if (preview) {
            preview.appendChild(illumination);
            preview.appendChild(prompt);
        }

        instruction.textContent = config.strings.challengeGetReady || config.strings.lookStraight;
        hint.textContent = config.strings.holdPosition || '';
        update(panel, 'running', instruction.textContent);
        await sleep(900);

        const started = performance.now();
        const duration = Math.max(1000, Number(challenge.durationMs || 4500));
        const lightForElapsed = elapsed => (challenge.illuminationSteps || []).find(
            step => elapsed >= Number(step.startMs) && elapsed < Number(step.endMs)
        );
        const captureEvidenceFrame = (purpose, illuminationElapsed) => {
            const elapsed = Math.round(performance.now() - started);
            if (evidence.length >= 48 || elapsed > duration + 500) {
                return;
            }
            const frame = {
                image: captureJpegForLiveness(),
                capturedAtMs: Number(challenge.issuedAtMs || 0) + elapsed,
                elapsedMs: elapsed,
                purpose: purpose,
            };
            if (Number.isFinite(illuminationElapsed)) {
                frame.illuminationElapsedMs = Math.max(0, Math.round(illuminationElapsed));
            }
            evidence.push(frame);
        };
        const capturePassiveFrames = async() => {
            if (!challenge.components || !challenge.components.passivePad) {
                return;
            }
            const captureMs = Math.max(1800, Number(challenge.passiveCaptureMs || 4500));
            instruction.textContent = config.strings.passiveCheck || config.strings.lookStraight;
            hint.textContent = config.strings.holdPosition || '';
            prompt.className = 'local-proctorcore-liveness-prompt is-center';
            progress.style.width = '0%';
            illumination.classList.remove('is-active');
            const phaseStarted = performance.now();
            while (performance.now() - phaseStarted <= captureMs && evidence.length < 48) {
                const phaseElapsed = performance.now() - phaseStarted;
                progress.style.width = `${Math.min(100, (phaseElapsed / captureMs) * 100)}%`;
                captureEvidenceFrame('passive');
                await sleep(280);
            }
            progress.style.width = '100%';
        };
        const captureIlluminationFrames = async() => {
            const illuminationSteps = challenge.illuminationSteps || [];
            if (!challenge.components || !challenge.components.illumination || !illuminationSteps.length) {
                return;
            }
            const captureMs = Number(illuminationSteps[illuminationSteps.length - 1].endMs || 0);
            instruction.textContent = config.strings.illuminationCheck || config.strings.lookStraight;
            hint.textContent = config.strings.holdPosition || '';
            prompt.className = 'local-proctorcore-liveness-prompt is-center';
            progress.style.width = '0%';
            illumination.classList.add('is-active');
            await sleep(700);
            const phaseStarted = performance.now();
            while (performance.now() - phaseStarted <= captureMs && evidence.length < 48) {
                const phaseElapsed = Math.round(performance.now() - phaseStarted);
                const light = lightForElapsed(phaseElapsed);
                illumination.style.setProperty('--liveness-colour', light ? light.hex : '#ffffff');
                progress.style.width = `${Math.min(100, (phaseElapsed / Math.max(1, captureMs)) * 100)}%`;
                captureEvidenceFrame('illumination', phaseElapsed);
                await sleep(280);
            }
            illumination.classList.remove('is-active');
            progress.style.width = '100%';
        };
        try {
            if (challenge.adaptiveHeadPose && challenge.components && challenge.components.headPose) {
                const steps = challenge.movementSteps || [];
                const stepTimeout = Math.max(10000, Number(challenge.poseStepTimeoutMs || 12000));
                for (let index = 0; index < steps.length; index++) {
                    const action = steps[index].action || 'center';
                    const label = movementLabel(config, action);
                    update(panel, 'running', label);
                    instruction.textContent = label;
                    hint.textContent = config.strings.holdPosition || '';
                    prompt.className = `local-proctorcore-liveness-prompt is-${action}`;
                    progress.style.width = '0%';
                    let reached = false;
                    const sampler = createPoseSampler();
                    try {
                        await sleep(action === 'center' ? 1100 : 1400);
                        const stepStarted = performance.now();
                        while (!reached && performance.now() - stepStarted < stepTimeout
                                && performance.now() - started < duration) {
                            const stepElapsed = performance.now() - stepStarted;
                            const frames = await sampler.nextBatch();
                            const result = await post(config, {
                                action: 'checkChallengePose',
                                challengeId: challenge.challengeId || '',
                                challengeNonce: challenge.nonce || '',
                                stepIndex: index,
                                image: frames[0],
                                images: frames,
                            });
                            reached = Boolean(result.reached);
                            const measuredProgress = Number(result.progressPercent);
                            progress.style.width = `${Number.isFinite(measuredProgress)
                                ? Math.max(0, Math.min(100, measuredProgress))
                                : Math.min(95, (stepElapsed / stepTimeout) * 100)}%`;
                            if (!reached) {
                                hint.textContent = result.message || config.strings.holdPosition || '';
                                await sleep(100);
                            }
                        }
                    } finally {
                        sampler.stop();
                    }
                    if (!reached) {
                        throw new Error(config.strings.movementTimeout || config.strings.failed);
                    }
                    progress.style.width = '100%';
                    hint.textContent = config.strings.poseConfirmed || '';
                    prompt.classList.add('is-confirmed');
                    await sleep(600);
                }

                await capturePassiveFrames();
                await captureIlluminationFrames();
            } else {
                let previousAction = '';
                const movementEnd = challenge.components && challenge.components.headPose
                    ? Math.max(0, ...(challenge.movementSteps || []).map(step => Number(step.endMs || 0)))
                    : 0;
                while (performance.now() - started <= movementEnd && evidence.length < 48) {
                    const elapsed = Math.round(performance.now() - started);
                    const movement = (challenge.movementSteps || []).find(
                        step => elapsed >= Number(step.startMs) && elapsed < Number(step.endMs)
                    );
                    const action = movement ? movement.action : 'center';
                    if (action !== previousAction) {
                        const label = movementLabel(config, action);
                        update(panel, 'running', label);
                        instruction.textContent = label;
                        prompt.className = `local-proctorcore-liveness-prompt is-${action}`;
                        previousAction = action;
                    }
                    if (movement && progress) {
                        const stepDuration = Math.max(1, Number(movement.endMs) - Number(movement.startMs));
                        progress.style.width = `${Math.min(100, Math.max(
                            0,
                            ((elapsed - Number(movement.startMs)) / stepDuration) * 100
                        ))}%`;
                    }
                    captureEvidenceFrame('headpose');
                    await sleep(300);
                }
                await capturePassiveFrames();
                await captureIlluminationFrames();
            }
        } finally {
            illumination.remove();
            prompt.remove();
        }
        return evidence;
    };

    const captureJpegForLiveness = () => {
        if (!window.ProctorCorePrecheck || typeof window.ProctorCorePrecheck.captureJpeg !== 'function') {
            throw new Error(cameraPreviewError);
        }
        return window.ProctorCorePrecheck.captureJpeg(0.88, 960);
    };

    const runChallenge = async(config, panel, button) => {
        button.disabled = true;
        enableSubmit(panel, false);
        setField('proctorcore_identity_passed', 0);
        setField('proctorcore_identity_status', 'running');
        setField('proctorcore_identity_score', '');

        try {
            if (config.enrollmentRequired) {
                const confirm = panel.querySelector('[data-identity-confirm]');
                if (!confirm || !confirm.checked) {
                    throw new Error(config.strings.confirmationRequired);
                }
            }

            update(panel, 'running', config.strings.preparingChallenge || config.strings.lookStraight);
            const challenge = await issueChallenge(config);
            if (challenge.locked) {
                setField('proctorcore_identity_status', challenge.result || 'retry_locked');
                update(panel, 'failed', challenge.message || config.strings.failed);
                return;
            }
            let livenessEvidence = [];
            await waitForChallengeReady(config, panel, challenge);
            await sleep(350);
            if (challenge.required) {
                livenessEvidence = await captureLivenessEvidence(config, panel, challenge);
                update(panel, 'running', config.strings.challengeComplete || config.strings.lookStraight);
                await sleep(250);
            }

            update(panel, 'running', config.strings.lookStraight);
            await sleep(400);
            const center = await captureFrames(6, 220);

            update(panel, 'running', config.enrollmentRequired ? config.strings.enrolling : config.strings.comparing);
            if (window.ProctorCorePrecheck && typeof window.ProctorCorePrecheck.freeze === 'function') {
                window.ProctorCorePrecheck.freeze(
                    config.enrollmentRequired ? config.strings.enrolling : config.strings.comparing
                );
            }
            const result = await post(config, {
                centerImage: center[0] || '',
                centerImages: center,
                leftImages: [],
                rightImages: [],
                confirmedName: config.fullName || '',
                confirmEnrollment: config.enrollmentRequired ? 1 : 0,
                challengeId: challenge.challengeId || '',
                challengeNonce: challenge.nonce || '',
                livenessEvidence: livenessEvidence,
            });
            setField('proctorcore_identity_status', result.result || 'failed');
            setField('proctorcore_identity_score', result.similarityScore ?? '');
            setField('proctorcore_identity_passed', result.passed ? 1 : 0);

            if (result.passed) {
                let label = config.strings.passed;
                if (result.result === 'enrolled') {
                    label = config.strings.enrolled;
                } else if (result.result === 'needs_review') {
                    label = config.strings.needsReview;
                }
                const score = Number(result.similarityScore);
                const scoreLabel = Number.isFinite(score) && score > 0 ? ` (${score.toFixed(3)})` : '';
                update(panel, 'passed', `${label}${scoreLabel}`);
                updateQualityFeedback(panel, 'ready', config.strings.qualityReady);
                if (window.ProctorCorePrecheck && typeof window.ProctorCorePrecheck.complete === 'function') {
                    window.ProctorCorePrecheck.complete(label);
                    window.ProctorCorePrecheck.stop();
                }
                setParticipantFieldsVisible(true);
                enableSubmit(panel, true);
            } else {
                if (window.ProctorCorePrecheck && typeof window.ProctorCorePrecheck.resume === 'function') {
                    window.ProctorCorePrecheck.resume();
                }
                update(panel, 'failed', config.strings.failed);
                updateQualityFeedback(panel, 'warning', result.message || config.strings.failed);
                button.textContent = config.strings.retry || button.textContent;
                button.disabled = Boolean(result.locked);
            }
        } catch (error) {
            if (window.ProctorCorePrecheck && typeof window.ProctorCorePrecheck.resume === 'function') {
                window.ProctorCorePrecheck.resume();
            }
            setField('proctorcore_identity_status', 'error');
            update(panel, 'failed', config.strings.failed);
            updateQualityFeedback(panel, 'warning', error.message || config.strings.failed);
            button.textContent = config.strings.retry || button.textContent;
            button.disabled = false;
        }
    };

    return {
        /**
         * @param {Object} config Moodle configuration.
         */
        init: function(config) {
            cameraPreviewError = config.strings.cameraPreviewUnavailable || config.strings.failed;
            setParticipantFieldsVisible(false);
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
                setParticipantFieldsVisible(true);
                return;
            }

            enableSubmit(panel, false);
            if (!config.serverHealthy) {
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
