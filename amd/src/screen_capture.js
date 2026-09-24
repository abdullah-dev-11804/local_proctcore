// This file is part of Moodle - http://moodle.org/

/** Persistent whole-display capture controller. @module local_proctorcore/screen_capture */
define([], function() {
    'use strict';

    let config;
    let room;
    let displayStream;
    let recorder;
    let uploadQueue = Promise.resolve();
    let uploadUrl = '';
    let uploadToken = '';
    let segment = 1;
    let sequence = 0;
    let stopping = false;
    let channel;
    let credentialTimer;

    const statusNode = () => document.querySelector('[data-screen-status]');
    const startButton = () => document.querySelector('[data-screen-start]');
    const stopButton = () => document.querySelector('[data-screen-stop]');

    const setStatus = (message, state = 'warning') => {
        const node = statusNode();
        if (node) {
            node.className = `alert alert-${state}`;
            node.textContent = message;
        }
    };

    const post = async(action, payload = {}, keepalive = false) => {
        const response = await fetch(config.endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive,
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({
                action, sessionId: Number(config.sessionId), sesskey: config.sesskey,
            }, payload)),
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || config.strings.failed);
        }
        return data;
    };

    const reportEvent = async(type, metadata = {}) => {
        try {
            await fetch(config.monitorEndpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'event', eventType: type, metadata,
                    sessionId: Number(config.sessionId), sesskey: config.sesskey,
                }),
            });
        } catch (error) {
            window.console.warn('Could not record screen-capture event:', error);
        }
    };

    const loadScript = url => new Promise((resolve, reject) => {
        if (window.LivekitClient) {
            resolve(window.LivekitClient);
            return;
        }
        if (!url) {
            reject(new Error('sdk_url_missing'));
            return;
        }

        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.proctorcoreLivekit = '1';

        // Moodle exposes RequireJS as an AMD loader. A UMD LiveKit bundle sees
        // that loader and registers there instead of creating
        // window.LivekitClient, which leaves this standalone controller with
        // sdk_missing. Temporarily hide the AMD marker while the UMD bundle is
        // evaluated, matching the established webcam-capture loader.
        const amdDefine = window.define;
        const amdDefineAmd = amdDefine && amdDefine.amd ? amdDefine.amd : null;
        const restoreAmd = () => {
            if (amdDefine && Object.prototype.hasOwnProperty.call(amdDefine, 'amd')) {
                amdDefine.amd = amdDefineAmd;
            }
        };
        if (amdDefine && Object.prototype.hasOwnProperty.call(amdDefine, 'amd')) {
            amdDefine.amd = false;
        }
        script.addEventListener('load', () => {
            restoreAmd();
            if (window.LivekitClient) {
                resolve(window.LivekitClient);
            } else {
                reject(new Error('sdk_missing'));
            }
        }, {once: true});
        script.addEventListener('error', () => {
            restoreAmd();
            reject(new Error('sdk_load_failed'));
        }, {once: true});
        document.head.appendChild(script);
    });

    const uploadChunk = async blob => {
        const data = new FormData();
        data.append('segment', String(segment));
        data.append('sequence', String(sequence));
        data.append('durationMs', '5000');
        data.append('stream', 'screen');
        data.append('asset', blob, `screen-${config.sessionId}-${Date.now()}.webm`);
        sequence += 1;
        const response = await fetch(uploadUrl, {
            method: 'POST', cache: 'no-store', headers: {'X-ProctorCore-Upload-Token': uploadToken}, body: data,
        });
        if (!response.ok) {
            throw new Error(`screen_chunk_upload_${response.status}`);
        }
    };

    const startRecorder = stream => {
        const mimeTypes = ['video/webm;codecs=vp8', 'video/webm;codecs=vp9', 'video/webm'];
        const mimeType = mimeTypes.find(type => window.MediaRecorder.isTypeSupported(type)) || '';
        const options = {videoBitsPerSecond: 1200000};
        if (mimeType) {
            options.mimeType = mimeType;
        }
        recorder = new MediaRecorder(stream, options);
        recorder.addEventListener('dataavailable', event => {
            if (event.data && event.data.size) {
                uploadQueue = uploadQueue.catch(() => {}).then(() => uploadChunk(event.data));
            }
        });
        recorder.start(5000);
    };

    const scheduleCredentialRefresh = expiresAt => {
        if (credentialTimer) {
            window.clearTimeout(credentialTimer);
        }
        const expiresMs = Number(expiresAt || 0) * 1000;
        const delay = expiresMs > Date.now()
            ? Math.max(60000, expiresMs - Date.now() - 300000)
            : 2700000;
        credentialTimer = window.setTimeout(async() => {
            try {
                const refreshed = await post('screen_bootstrap');
                uploadUrl = refreshed.uploadUrl || uploadUrl;
                uploadToken = refreshed.uploadToken || uploadToken;
                scheduleCredentialRefresh(refreshed.uploadTokenExpiresAt);
            } catch (error) {
                scheduleCredentialRefresh(Math.floor(Date.now() / 1000) + 360);
            }
        }, delay);
    };

    const stopCapture = async(reason = 'submitted', report = false) => {
        if (stopping) {
            return;
        }
        stopping = true;
        if (recorder && recorder.state !== 'inactive') {
            await new Promise(resolve => {
                recorder.addEventListener('stop', resolve, {once: true});
                try {
                    recorder.requestData();
                    recorder.stop();
                } catch (error) {
                    resolve();
                }
            });
        }
        await uploadQueue.catch(() => {});
        await post('screen_stop', {reason}, true).catch(() => {});
        if (room) {
            room.disconnect();
        }
        if (displayStream) {
            displayStream.getTracks().forEach(track => track.stop());
        }
        if (credentialTimer) {
            window.clearTimeout(credentialTimer);
            credentialTimer = null;
        }
        if (report) {
            await reportEvent('screen_share_ended', {reason});
        }
        setStatus(config.strings.stopped, 'warning');
        startButton().hidden = false;
        stopButton().hidden = true;
        channel?.postMessage({type: 'status', state: 'stopped'});
        stopping = false;
    };

    const startCapture = async() => {
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function'
                || !window.MediaRecorder) {
            setStatus(config.strings.unsupported, 'warning');
            await reportEvent('screen_share_unsupported', {userAgent: navigator.userAgent});
            channel?.postMessage({type: 'status', state: 'unsupported'});
            return;
        }
        startButton().disabled = true;
        let serverStarted = false;
        try {
            displayStream = await navigator.mediaDevices.getDisplayMedia({
                video: {frameRate: {ideal: 8, max: 10}}, audio: false,
                monitorTypeSurfaces: 'include', selfBrowserSurface: 'exclude', surfaceSwitching: 'exclude',
            });
            const track = displayStream.getVideoTracks()[0];
            const displaySurface = String((track.getSettings && track.getSettings().displaySurface) || 'unknown');
            if (displaySurface !== 'unknown' && displaySurface !== 'monitor') {
                track.stop();
                setStatus(config.strings.incomplete, 'warning');
                await reportEvent('screen_share_incomplete', {displaySurface});
                channel?.postMessage({type: 'status', state: 'incomplete'});
                return;
            }
            const bootstrap = await post('screen_bootstrap');
            if (!bootstrap.enabled || bootstrap.supported === false) {
                throw new Error(bootstrap.reason || 'screen_capture_unavailable');
            }
            uploadUrl = bootstrap.uploadUrl;
            uploadToken = bootstrap.uploadToken;
            scheduleCredentialRefresh(bootstrap.uploadTokenExpiresAt);
            const LivekitClient = await loadScript(bootstrap.clientScriptUrl);
            room = new LivekitClient.Room({adaptiveStream: true, dynacast: true, disconnectOnPageLeave: false});
            await room.connect(bootstrap.url, bootstrap.token);
            await room.localParticipant.publishTrack(track, {
                source: LivekitClient.Track.Source.ScreenShare, simulcast: false,
            });
            const started = await post('screen_start', {displaySurface});
            serverStarted = true;
            segment = Number(started.segment) || 1;
            sequence = Math.max(0, Number(started.nextSequence) || 0);
            startRecorder(displayStream);
            track.addEventListener('ended', () => {
                if (!stopping) {
                    stopCapture('screen_share_ended', true);
                }
            }, {once: true});
            const preview = document.querySelector('[data-screen-preview]');
            if (preview) {
                preview.srcObject = displayStream;
            }
            setStatus(config.strings.active, 'success');
            startButton().hidden = true;
            stopButton().hidden = false;
            channel?.postMessage({type: 'status', state: 'active', displaySurface});
        } catch (error) {
            if (serverStarted) {
                await post('screen_stop', {reason: 'screen_capture_start_failed'}, true).catch(() => {});
            }
            if (displayStream) {
                displayStream.getTracks().forEach(track => track.stop());
            }
            if (room) {
                room.disconnect();
            }
            if (credentialTimer) {
                window.clearTimeout(credentialTimer);
                credentialTimer = null;
            }
            const denied = error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
            setStatus(denied ? config.strings.denied : `${config.strings.failed}: ${error.message || error}`, 'warning');
            await reportEvent(denied ? 'screen_share_denied' : 'screen_share_unavailable', {
                error: String(error && (error.name || error.message) || error),
            });
            channel?.postMessage({type: 'status', state: denied ? 'denied' : 'failed'});
        } finally {
            startButton().disabled = false;
        }
    };

    return {init: options => {
        config = options;
        channel = typeof BroadcastChannel === 'function'
            ? new BroadcastChannel(`proctorcore-screen-${config.sessionId}`) : null;
        channel?.addEventListener('message', event => {
            const message = event.data || {};
            if (message.type === 'status-request') {
                channel.postMessage({type: 'status', state: recorder && recorder.state === 'recording' ? 'active' : 'pending'});
            } else if (message.type === 'finalize') {
                stopCapture('submitted', false).finally(() => {
                    channel.postMessage({type: 'finalized', requestId: message.requestId || ''});
                });
            }
        });
        startButton().addEventListener('click', startCapture);
        stopButton().addEventListener('click', () => stopCapture('user_stopped_screen_share', true));
        window.addEventListener('pagehide', () => {
            if (recorder && recorder.state === 'recording' && !stopping) {
                try {
                    recorder.requestData();
                } catch (error) {
                    // The document is already closing.
                }
                reportEvent('screen_share_ended', {reason: 'controller_closed'});
                post('screen_stop', {reason: 'controller_closed'}, true).catch(() => {});
                channel?.postMessage({type: 'status', state: 'stopped'});
            }
        });
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
            setStatus(config.strings.unsupported, 'warning');
            reportEvent('screen_share_unsupported', {userAgent: navigator.userAgent});
        }
    }};
});
