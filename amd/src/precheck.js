// This file is part of Moodle - http://moodle.org/

/**
 * Browser/device precheck shared by the student preflight form and admin preview.
 *
 * @module local_proctorcore/precheck
 */
define([], function() {
    let currentStream = null;

    const bool = value => value === true || value === 1 || value === '1';

    const captureJpeg = (quality = 0.9) => {
        const video = document.querySelector('[data-precheck-video]');
        if (!video || !video.videoWidth || !video.videoHeight || video.readyState < 2) {
            throw new Error('Camera preview is not ready.');
        }
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const context = canvas.getContext('2d', {alpha: false});
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', quality);
    };

    const field = name => document.querySelector(`[name="${name}"]`);

    const setField = (name, value) => {
        const input = field(name);
        if (input) {
            input.value = String(value);
        }
    };

    const setRow = (panel, key, state, message) => {
        const row = panel.querySelector(`[data-precheck-row="${key}"]`);
        const value = panel.querySelector(`[data-precheck-value="${key}"]`);
        if (row) {
            row.className = `local-proctorcore-precheck-row is-${state}`;
        }
        if (value) {
            value.textContent = message;
        }
    };

    const stopStream = () => {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    };

    const detectBrowser = () => {
        const ua = navigator.userAgent || '';
        let match = ua.match(/Edg\/([0-9.]+)/);
        if (match) {
            return {ok: true, name: 'Microsoft Edge', version: match[1]};
        }
        match = ua.match(/Chrome\/([0-9.]+)/);
        if (match && !/OPR\//.test(ua)) {
            return {ok: true, name: 'Google Chrome', version: match[1]};
        }
        return {ok: false, name: navigator.userAgentData?.brands?.[0]?.brand || 'Unsupported', version: ''};
    };

    const waitForVideo = video => new Promise((resolve, reject) => {
        if (video.readyState >= 2) {
            resolve();
            return;
        }
        const timeout = window.setTimeout(() => reject(new Error('Camera preview timed out')), 5000);
        video.addEventListener('loadeddata', () => {
            window.clearTimeout(timeout);
            resolve();
        }, {once: true});
    });

    const measureLighting = video => {
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 120;
        const context = canvas.getContext('2d', {willReadFrequently: true});
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const data = context.getImageData(0, 0, canvas.width, canvas.height).data;
        let total = 0;
        let samples = 0;
        for (let i = 0; i < data.length; i += 64) {
            total += (data[i] + data[i + 1] + data[i + 2]) / 3;
            samples++;
        }
        return samples ? total / samples : 0;
    };

    const testNetwork = async(config) => {
        if (!navigator.onLine) {
            throw new Error(config.strings.networkOffline);
        }
        const url = new URL(config.pingUrl, window.location.origin);
        url.searchParams.set('bytes', '131072');
        url.searchParams.set('_', String(Date.now()));
        const started = performance.now();
        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(config.strings.networkFailed);
        }
        const payload = await response.arrayBuffer();
        const elapsedMs = Math.max(1, performance.now() - started);
        const speedMbps = (payload.byteLength * 8) / (elapsedMs / 1000) / 1000000;
        return {
            ok: speedMbps >= Number(config.minimumSpeedMbps || 5),
            speedMbps: speedMbps,
            latencyMs: Math.round(elapsedMs),
        };
    };

    const submitButtons = panel => {
        const form = panel.closest('form');
        if (!form) {
            return [];
        }
        const preferred = form.querySelectorAll(
            'button[type="submit"].btn-primary, input[type="submit"].btn-primary, ' +
            'button[name="submitbutton"], input[name="submitbutton"], ' +
            'button[name="startattempt"], input[name="startattempt"]'
        );
        return Array.from(preferred);
    };

    const enableSubmit = (panel, enabled) => {
        submitButtons(panel).forEach(button => {
            button.disabled = !enabled;
            button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        });
    };

    const run = async(config, panel) => {
        const strings = config.strings;
        const required = {
            camera: bool(config.requireCamera) || bool(config.requireSnapshot),
            microphone: bool(config.requireMicrophone),
            snapshot: bool(config.requireSnapshot),
        };
        required.secure = bool(config.requireHttps) || required.camera || required.microphone || required.snapshot;
        required.lighting = required.camera;

        enableSubmit(panel, false);
        setField('proctorcore_preflight_passed', 0);
        stopStream();

        const summary = panel.querySelector('[data-precheck-summary]');
        if (summary) {
            summary.className = 'local-proctorcore-precheck-summary';
            summary.textContent = strings.running;
        }

        const results = {
            server: bool(config.serverHealthy),
            browser: false,
            secure: false,
            network: false,
            camera: !required.camera,
            microphone: !required.microphone,
            lighting: !required.lighting,
            snapshot: !required.snapshot,
        };

        // Proctoring server health is checked by Moodle before this module runs.
        setField('proctorcore_preflight_server', results.server ? 1 : 0);
        setRow(panel, 'server', results.server ? 'passed' : 'failed',
            results.server ? strings.serverHealthy : strings.serverUnavailable);

        // Browser.
        setRow(panel, 'browser', 'running', strings.checking);
        const browser = detectBrowser();
        results.browser = browser.ok;
        setField('proctorcore_preflight_browser', browser.ok ? 1 : 0);
        setField('proctorcore_preflight_browsername', browser.name);
        setField('proctorcore_preflight_browserversion', browser.version);
        setRow(panel, 'browser', browser.ok ? 'passed' : 'failed',
            browser.ok ? `${browser.name} ${browser.version}` : strings.browserUnsupported);

        // Secure context.
        setRow(panel, 'secure', 'running', strings.checking);
        results.secure = !required.secure || window.isSecureContext;
        setField('proctorcore_preflight_secure', results.secure ? 1 : 0);
        setRow(panel, 'secure', results.secure ? 'passed' : 'failed',
            !required.secure ? strings.notRequired : (results.secure ? strings.passed : strings.secureRequired));

        // Network.
        setRow(panel, 'network', 'running', strings.checking);
        try {
            const network = await testNetwork(config);
            results.network = network.ok;
            setField('proctorcore_preflight_network', network.ok ? 1 : 0);
            setField('proctorcore_preflight_speedmbps', network.speedMbps.toFixed(2));
            setField('proctorcore_preflight_latencyms', network.latencyMs);
            const message = `${network.speedMbps.toFixed(2)} Mbps (${network.latencyMs} ms)`;
            setRow(panel, 'network', network.ok ? 'passed' : 'failed', message);
        } catch (error) {
            results.network = false;
            setField('proctorcore_preflight_network', 0);
            setRow(panel, 'network', 'failed', error.message || strings.networkFailed);
        }

        // Camera and microphone.
        if (!required.camera) {
            setField('proctorcore_preflight_camera', 1);
            setRow(panel, 'camera', 'passed', strings.notRequired);
        }
        if (!required.microphone) {
            setField('proctorcore_preflight_microphone', 1);
            setRow(panel, 'microphone', 'passed', strings.notRequired);
        }
        if (!required.lighting) {
            setField('proctorcore_preflight_lighting', 1);
            setRow(panel, 'lighting', 'passed', strings.notRequired);
        }
        if (!required.snapshot) {
            setField('proctorcore_preflight_snapshot', 1);
            setRow(panel, 'snapshot', 'passed', strings.notRequired);
        }

        if (required.camera || required.microphone) {
            setRow(panel, 'camera', required.camera ? 'running' : 'passed',
                required.camera ? strings.checking : strings.notRequired);
            setRow(panel, 'microphone', required.microphone ? 'running' : 'passed',
                required.microphone ? strings.checking : strings.notRequired);
            try {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error(strings.mediaUnsupported);
                }
                currentStream = await navigator.mediaDevices.getUserMedia({
                    video: required.camera,
                    audio: required.microphone,
                });
                const videoTrack = currentStream.getVideoTracks()[0] || null;
                const audioTrack = currentStream.getAudioTracks()[0] || null;
                results.camera = !required.camera || Boolean(videoTrack && videoTrack.readyState === 'live');
                results.microphone = !required.microphone || Boolean(audioTrack && audioTrack.readyState === 'live');
                setField('proctorcore_preflight_camera', results.camera ? 1 : 0);
                setField('proctorcore_preflight_microphone', results.microphone ? 1 : 0);
                setRow(panel, 'camera', results.camera ? 'passed' : 'failed',
                    results.camera ? strings.passed : strings.failed);
                setRow(panel, 'microphone', results.microphone ? 'passed' : 'failed',
                    results.microphone ? strings.passed : strings.failed);

                if (required.camera && videoTrack) {
                    const video = panel.querySelector('[data-precheck-video]');
                    if (video) {
                        video.hidden = false;
                        const placeholder = panel.querySelector('[data-precheck-video-placeholder]');
                        if (placeholder) {
                            placeholder.hidden = true;
                        }
                        video.srcObject = currentStream;
                        await waitForVideo(video);
                        await new Promise(resolve => window.setTimeout(resolve, 400));

                        setRow(panel, 'lighting', 'running', strings.checking);
                        const brightness = measureLighting(video);
                        results.lighting = brightness >= Number(config.minimumLighting || 35);
                        setField('proctorcore_preflight_brightness', brightness.toFixed(0));
                        setField('proctorcore_preflight_lighting', results.lighting ? 1 : 0);
                        setRow(panel, 'lighting', results.lighting ? 'passed' : 'failed',
                            results.lighting ? strings.passed : `${strings.tooDark} (${brightness.toFixed(0)})`);

                        if (required.snapshot) {
                            setRow(panel, 'snapshot', 'running', strings.checking);
                            results.snapshot = brightness > 0;
                            setField('proctorcore_preflight_snapshot', results.snapshot ? 1 : 0);
                            setRow(panel, 'snapshot', results.snapshot ? 'passed' : 'failed',
                                results.snapshot ? strings.snapshotCaptured : strings.failed);
                        }
                    }
                }
            } catch (error) {
                if (required.camera) {
                    results.camera = false;
                    results.lighting = false;
                    results.snapshot = !required.snapshot;
                    setField('proctorcore_preflight_camera', 0);
                    setField('proctorcore_preflight_lighting', 0);
                    setField('proctorcore_preflight_snapshot', required.snapshot ? 0 : 1);
                    setRow(panel, 'camera', 'failed', error.message || strings.permissionDenied);
                    setRow(panel, 'lighting', 'failed', strings.cameraRequiredFirst);
                    if (required.snapshot) {
                        setRow(panel, 'snapshot', 'failed', strings.cameraRequiredFirst);
                    }
                }
                if (required.microphone) {
                    results.microphone = false;
                    setField('proctorcore_preflight_microphone', 0);
                    setRow(panel, 'microphone', 'failed', error.message || strings.permissionDenied);
                }
            }
        }

        const passed = Object.values(results).every(Boolean);
        setField('proctorcore_preflight_passed', passed ? 1 : 0);
        if (summary) {
            summary.className = `local-proctorcore-precheck-summary is-${passed ? 'passed' : 'failed'}`;
            summary.textContent = passed ? strings.allPassed : strings.someFailed;
        }
        enableSubmit(panel, passed);

        if (window.ProctorCorePrecheck) {
            window.ProctorCorePrecheck.lastResult = {passed: passed, results: results};
        }
        window.dispatchEvent(new CustomEvent('proctorcore:precheckcomplete', {
            detail: {passed: passed, results: results},
        }));
    };

    return {
        /**
         * Initialises and automatically runs the checks.
         *
         * @param {Object} config Configuration.
         */
        init: function(config) {
            const panel = document.getElementById(config.panelId || 'local-proctorcore-precheck');
            if (!panel) {
                return;
            }
            const retry = panel.querySelector('[data-precheck-retry]');
            if (retry) {
                retry.addEventListener('click', () => run(config, panel));
            }
            window.ProctorCorePrecheck = {
                lastResult: null,
                getStream: () => currentStream,
                getVideoElement: () => panel.querySelector('[data-precheck-video]'),
                captureJpeg: captureJpeg,
                stop: stopStream,
            };
            window.addEventListener('beforeunload', stopStream, {once: true});
            run(config, panel);
        },
    };
});
