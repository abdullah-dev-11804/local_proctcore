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

    const request = async(payload, keepalive = false) => {
        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: Boolean(keepalive),
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({
                sessionId: Number(config.sessionId),
                sesskey: config.sesskey,
            }, payload)),
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

    const dispatchViolations = data => {
        const violations = Array.isArray(data.violations)
            ? data.violations
            : (data.violation ? [data.violation] : []);
        violations.filter(Boolean).forEach(violation => {
            window.dispatchEvent(new CustomEvent('proctorcore:violation', {
                detail: {
                    violationId: Number(violation.id),
                    violationType: violation.type,
                    severity: Number(violation.severity || 1),
                },
            }));
        });
    };

    const analyse = async() => {
        if (requestRunning || document.hidden || !navigator.onLine) {
            return;
        }
        const image = frameData();
        if (!image) {
            return;
        }
        requestRunning = true;
        try {
            const data = await request({action: 'frame', frameImage: image});
            dispatchViolations(data);
        } catch (error) {
            window.console.warn('ProctorCore frame analysis failed:', error);
        } finally {
            requestRunning = false;
        }
    };

    const browserEvent = async(type, metadata = {}, keepalive = false) => {
        try {
            const data = await request({action: 'event', eventType: type, metadata}, keepalive);
            dispatchViolations(data);
        } catch (error) {
            window.console.warn(`ProctorCore event ${type} failed:`, error);
        }
    };

    const bindEvents = () => {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                browserEvent('tab_hidden', {visibilityState: document.visibilityState}, true);
            }
        });
        window.addEventListener('blur', () => {
            const now = Date.now();
            if (!document.hidden && now - lastBlurAt > 3000) {
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
            timer = window.setInterval(analyse, interval);
            window.addEventListener('beforeunload', () => {
                if (timer) {
                    window.clearInterval(timer);
                }
            }, {once: true});
            window.setTimeout(analyse, 1200);
        },
    };
});
