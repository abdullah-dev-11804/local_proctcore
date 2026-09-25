// This file is part of Moodle - http://moodle.org/

/**
 * Section 1.3 sampled camera analysis plus browser/media violation events.
 *
 * @module local_proctorcore/violation_monitor
 */
define([], function() {
    'use strict';

    let config = null;
    let timer = null;
    let requestRunning = false;
    let lastBlurAt = 0;
    let stopped = false;
    let consecutiveErrors = 0;
    let droppedSamples = 0;
    let completedSamples = 0;
    let lastLatencyMs = null;
    let activeController = null;
    let lastFrameImage = null;
    let suppressFocusUntil = 0;
    let pageLeaving = false;

    const suppressFocusEvents = durationMs => {
        const duration = Math.min(15000, Math.max(0, Number(durationMs || 0)));
        suppressFocusUntil = Math.max(suppressFocusUntil, Date.now() + duration);
    };

    const focusEventIsIntentional = () => pageLeaving || Date.now() < suppressFocusUntil;

    const isQuizNavigationForm = form => {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }
        const action = String(form.getAttribute('action') || '');
        return form.id === 'responseform' || action.includes('/mod/quiz/processattempt.php');
    };

    const isQuizNavigationLink = target => {
        const link = target instanceof Element ? target.closest('a[href]') : null;
        if (!link) {
            return false;
        }
        try {
            const url = new URL(link.href, window.location.href);
            return url.origin === window.location.origin && [
                '/mod/quiz/attempt.php',
                '/mod/quiz/processattempt.php',
                '/mod/quiz/summary.php',
            ].some(path => url.pathname.endsWith(path));
        } catch (error) {
            return false;
        }
    };

    const request = async(payload, keepalive = false) => {
        const controller = new AbortController();
        activeController = controller;
        const timeout = window.setTimeout(
            () => controller.abort(),
            Math.max(2000, Number(config.requestTimeoutMs || 8000))
        );
        let response;
        try {
            response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: Boolean(keepalive),
                signal: controller.signal,
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(Object.assign({
                    sessionId: Number(config.sessionId),
                    sesskey: config.sesskey,
                }, payload)),
            });
        } finally {
            window.clearTimeout(timeout);
            if (activeController === controller) {
                activeController = null;
            }
        }
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

    const video = () => {
        if (window.ProctorCoreCapture && typeof window.ProctorCoreCapture.getVideoElement === 'function') {
            return window.ProctorCoreCapture.getVideoElement();
        }
        return document.querySelector('[data-proctorcore-local-video]');
    };

    const frameData = () => {
        const source = video();
        if (!source || !source.videoWidth || !source.videoHeight || source.readyState < 2) {
            return null;
        }
        const canvas = document.createElement('canvas');
        const maxWidth = 640;
        const scale = Math.min(1, maxWidth / source.videoWidth);
        canvas.width = Math.max(1, Math.round(source.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(source.videoHeight * scale));
        const context = canvas.getContext('2d', {alpha: false});
        context.drawImage(source, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.72);
    };

    const dispatchViolations = (data, snapshotImage = null) => {
        const violations = Array.isArray(data.violations)
            ? data.violations
            : (data.violation ? [data.violation] : []);
        violations.filter(Boolean).forEach(violation => {
            window.dispatchEvent(new CustomEvent('proctorcore:violation', {
                detail: {
                    violationId: Number(violation.id),
                    violationType: violation.type,
                    severity: Number(violation.severity || 1),
                    occurredAt: Number(violation.occurredAt || Math.floor(Date.now() / 1000)),
                    snapshotImage: snapshotImage || lastFrameImage,
                },
            }));
        });
    };

    const analyse = async() => {
        if (requestRunning) {
            droppedSamples += 1;
            return;
        }
        if (document.hidden || !navigator.onLine) {
            return;
        }
        const image = frameData();
        if (!image) {
            return;
        }
        lastFrameImage = image;
        requestRunning = true;
        const started = performance.now();
        try {
            const data = await request({
                action: 'frame',
                frameImage: image,
                clientTelemetry: {
                    completedSamples,
                    droppedSamples,
                    consecutiveErrors,
                    lastLatencyMs,
                },
            });
            lastLatencyMs = Math.round(performance.now() - started);
            completedSamples += 1;
            consecutiveErrors = 0;
            dispatchViolations(data);
        } catch (error) {
            lastLatencyMs = Math.round(performance.now() - started);
            consecutiveErrors += 1;
            window.console.warn('ProctorCore frame analysis failed:', error);
        } finally {
            requestRunning = false;
        }
    };

    const schedule = delay => {
        if (stopped) {
            return;
        }
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(async() => {
            await analyse();
            const interval = Math.max(1500, Number(config.intervalMs || 3000));
            const multiplier = Math.min(8, Math.pow(2, consecutiveErrors));
            schedule(interval * multiplier);
        }, Math.max(0, delay));
    };

    const browserEvent = async(type, metadata = {}, keepalive = false) => {
        // Preserve a camera frame before a hidden tab can suspend rendering or
        // delay the asynchronous response that creates the violation record.
        const snapshotImage = frameData() || lastFrameImage;
        try {
            const data = await request({action: 'event', eventType: type, metadata}, keepalive);
            dispatchViolations(data, snapshotImage);
        } catch (error) {
            window.console.warn(`ProctorCore event ${type} failed:`, error);
        }
    };

    const bindEvents = () => {
        // Moodle reloads the document for normal question navigation. Mark the
        // transition before blur/visibilitychange fires so it is not mistaken
        // for the learner leaving the exam.
        document.addEventListener('submit', event => {
            if (isQuizNavigationForm(event.target)) {
                suppressFocusEvents(10000);
            }
        }, true);
        document.addEventListener('click', event => {
            if (isQuizNavigationLink(event.target)) {
                suppressFocusEvents(10000);
            }
        }, true);
        window.addEventListener('proctorcore:intentionalfocusloss', event => {
            const detail = event.detail || {};
            suppressFocusEvents(detail.durationMs || 10000);
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !focusEventIsIntentional()) {
                browserEvent('tab_hidden', {visibilityState: document.visibilityState}, true);
            }
        });
        window.addEventListener('blur', () => {
            const now = Date.now();
            if (!document.hidden && !focusEventIsIntentional() && now - lastBlurAt > 3000) {
                lastBlurAt = now;
                browserEvent('window_blur', {at: now}, true);
            }
        });
        window.addEventListener('proctorcore:mediaended', event => {
            const detail = event.detail || {};
            const type = detail.kind === 'audio' ? 'microphone_ended' : 'camera_ended';
            browserEvent(type, detail, true);
        });
    };

    return {
        /** @param {Object} options Moodle options. */
        init: function(options) {
            config = options;
            if (!config.enabled) {
                return;
            }
            bindEvents();
            const interval = Math.max(1500, Number(config.intervalMs || 3000));
            stopped = false;
            schedule(1200);
            window.addEventListener('beforeunload', () => {
                pageLeaving = true;
                stopped = true;
                if (timer) {
                    window.clearTimeout(timer);
                }
                if (activeController) {
                    activeController.abort();
                }
            }, {once: true});
        },
    };
});
