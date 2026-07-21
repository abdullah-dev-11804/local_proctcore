<<<<<<< HEAD
// Placeholder for small Moodle AMD behaviours used by ProctorCore pages.
export const init = () => {};
=======
/* eslint-disable promise/catch-or-return */
define([], function() {
    'use strict';

    let room = null;
    let config = null;
    let captureMode = 'serverb';
    let submitting = false;
    let allowFinalSubmit = false;
    let failureSent = false;
    let pageLeaving = false;

    let localStream = null;
    let mediaRecorder = null;
    let localSegment = 1;
    let localSequence = 0;
    let localUploadUrl = '';
    let localUploadQueue = Promise.resolve();
    let localUploadError = null;

    const originalDisabled = new Map();

    const apiRequest = async(action, payload = {}, keepalive = false) => {
        const response = await fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: Boolean(keepalive),
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({
                action: action,
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
            throw new Error(data.error || config.strings.captureFailed);
        }
        return data;
    };

    const setAttemptLocked = locked => {
        const form = document.getElementById('responseform') || document.querySelector('form[action*="processattempt.php"]');
        if (!form) {
            return;
        }
        form.querySelectorAll('button, input:not([type="hidden"]), select, textarea').forEach(element => {
            if (!originalDisabled.has(element)) {
                originalDisabled.set(element, Boolean(element.disabled));
            }
            element.disabled = Boolean(locked) || originalDisabled.get(element);
        });
        form.setAttribute('aria-busy', locked ? 'true' : 'false');
    };

    const loadScript = url => new Promise((resolve, reject) => {
        if (window.LivekitClient) {
            resolve(window.LivekitClient);
            return;
        }
        if (!url) {
            reject(new Error(config.strings.sdkFailed));
            return;
        }

        const existing = document.querySelector('script[data-proctorcore-livekit="1"]');
        if (existing) {
            existing.addEventListener('load', () => resolve(window.LivekitClient), {once: true});
            existing.addEventListener('error', () => reject(new Error(config.strings.sdkFailed)), {once: true});
            return;
        }

        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.proctorcoreLivekit = '1';
        script.addEventListener('load', () => {
            if (window.LivekitClient) {
                resolve(window.LivekitClient);
            } else {
                reject(new Error(config.strings.sdkFailed));
            }
        }, {once: true});
        script.addEventListener('error', () => reject(new Error(config.strings.sdkFailed)), {once: true});
        document.head.appendChild(script);
    });

    const buildPanel = () => {
        let panel = document.getElementById('local-proctorcore-capture-status');
        if (panel) {
            return panel;
        }

        panel = document.createElement('aside');
        panel.id = 'local-proctorcore-capture-status';
        panel.className = 'local-proctorcore-capture is-connecting';
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');

        const header = document.createElement('div');
        header.className = 'local-proctorcore-capture-header';
        const dot = document.createElement('span');
        dot.className = 'local-proctorcore-capture-dot';
        dot.setAttribute('aria-hidden', 'true');
        const title = document.createElement('strong');
        title.dataset.proctorcoreCaptureTitle = '1';
        title.textContent = config.strings.connecting;
        header.append(dot, title);

        const video = document.createElement('video');
        video.className = 'local-proctorcore-capture-video';
        video.dataset.proctorcoreLocalVideo = '1';
        video.autoplay = true;
        video.muted = true;
        video.playsInline = true;

        const message = document.createElement('div');
        message.className = 'local-proctorcore-capture-message';
        message.dataset.proctorcoreCaptureMessage = '1';
        message.textContent = config.strings.permissions;

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-secondary local-proctorcore-capture-retry';
        retry.dataset.proctorcoreCaptureRetry = '1';
        retry.textContent = config.strings.retry;
        retry.hidden = true;
        retry.addEventListener('click', () => window.location.reload());

        panel.append(header, video, message, retry);
        document.body.appendChild(panel);
        return panel;
    };

    const updateStatus = (state, title, message, retryVisible = false) => {
        const panel = buildPanel();
        panel.className = `local-proctorcore-capture is-${state}`;
        const titleNode = panel.querySelector('[data-proctorcore-capture-title]');
        const messageNode = panel.querySelector('[data-proctorcore-capture-message]');
        const retryNode = panel.querySelector('[data-proctorcore-capture-retry]');
        if (titleNode) {
            titleNode.textContent = title;
        }
        if (messageNode) {
            messageNode.textContent = message || '';
        }
        if (retryNode) {
            retryNode.hidden = !retryVisible;
        }
    };

    const panelVideo = () => buildPanel().querySelector('[data-proctorcore-local-video]');

    const attachLiveKitVideo = LivekitClient => {
        const video = panelVideo();
        if (!video || !room || !room.localParticipant) {
            return;
        }

        const publication = room.localParticipant.getTrackPublication(LivekitClient.Track.Source.Camera);
        if (publication && publication.videoTrack) {
            publication.videoTrack.attach(video);
            const mediaTrack = publication.videoTrack.mediaStreamTrack;
            if (mediaTrack) {
                mediaTrack.addEventListener(
                    'ended',
                    () => {
                        window.dispatchEvent(new CustomEvent('proctorcore:mediaended', {
                            detail: {kind: 'video', reason: 'media_track_ended'},
                        }));
                        signalFailure('media_track_ended', 'Camera track ended.');
                    },
                    {once: true}
                );
            }
        }

        const micPublication = room.localParticipant.getTrackPublication(LivekitClient.Track.Source.Microphone);
        if (micPublication && micPublication.audioTrack && micPublication.audioTrack.mediaStreamTrack) {
            micPublication.audioTrack.mediaStreamTrack.addEventListener(
                'ended',
                () => {
                    window.dispatchEvent(new CustomEvent('proctorcore:mediaended', {
                        detail: {kind: 'audio', reason: 'media_track_ended'},
                    }));
                    signalFailure('media_track_ended', 'Microphone track ended.');
                },
                {once: true}
            );
        }
    };

    const attachLocalStream = async stream => {
        const video = panelVideo();
        if (!video) {
            return;
        }
        video.srcObject = stream;
        await video.play();
    };

    const disconnectRoom = () => {
        if (!room) {
            return;
        }
        try {
            room.disconnect();
        } catch (error) {
            window.console.warn('ProctorCore could not disconnect the media room:', error);
        }
        room = null;
    };

    const stopLocalTracks = () => {
        if (!localStream) {
            return;
        }
        localStream.getTracks().forEach(track => {
            try {
                track.stop();
            } catch (error) {
                window.console.warn('ProctorCore could not stop a local media track:', error);
            }
        });
        localStream = null;
    };

    const uploadLocalAsset = async(blob, kind, reason, violationId = null) => {
        if (!localUploadUrl) {
            throw new Error(config.strings.invalidResponse);
        }

        const formData = new FormData();
        formData.append('sesskey', config.sesskey);
        formData.append('sessionid', String(config.sessionId));
        formData.append('kind', kind);
        formData.append('reason', reason || '');
        formData.append('segment', String(localSegment));
        formData.append('sequence', String(localSequence));
        if (violationId) {
            formData.append('violationid', String(violationId));
        }

        const extension = kind === 'snapshot' ? 'jpg' : 'webm';
        formData.append(
            'asset',
            blob,
            `proctorcore-${kind}-session-${config.sessionId}-${Date.now()}.${extension}`
        );

        const response = await fetch(localUploadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            body: formData,
        });
        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = {ok: false, error: config.strings.invalidResponse};
        }
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || config.strings.captureFailed);
        }
        return data;
    };

    const enqueueLocalUpload = (blob, kind, reason, violationId = null) => {
        localUploadQueue = localUploadQueue
            .catch(() => {})
            .then(() => uploadLocalAsset(blob, kind, reason, violationId))
            .catch(error => {
                localUploadError = error;
                throw error;
            });
        return localUploadQueue;
    };

    const waitForLocalUploads = async() => {
        try {
            await localUploadQueue;
        } catch (error) {
            throw localUploadError || error;
        }
        if (localUploadError) {
            throw localUploadError;
        }
    };

    const captureLocalSnapshot = async(reason, violationId = null) => {
        const video = panelVideo();
        if (!video || !video.videoWidth || !video.videoHeight) {
            throw new Error('The camera preview is not ready for a snapshot.');
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const context = canvas.getContext('2d', {alpha: false});
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(result => {
                if (result) {
                    resolve(result);
                } else {
                    reject(new Error('Could not create the camera snapshot.'));
                }
            }, 'image/jpeg', 0.85);
        });
        return enqueueLocalUpload(blob, 'snapshot', reason, violationId);
    };

    const chooseRecorderMime = () => {
        const candidates = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp9,opus',
            'video/webm',
        ];
        return candidates.find(type => window.MediaRecorder.isTypeSupported(type)) || '';
    };

    const startLocalRecorder = chunkMilliseconds => {
        if (!window.MediaRecorder || !localStream) {
            throw new Error('MediaRecorder is not supported by this browser.');
        }

        const mimeType = chooseRecorderMime();
        const options = {
            videoBitsPerSecond: 600000,
            audioBitsPerSecond: 64000,
        };
        if (mimeType) {
            options.mimeType = mimeType;
        }

        try {
            mediaRecorder = new MediaRecorder(localStream, options);
        } catch (error) {
            mediaRecorder = new MediaRecorder(localStream);
        }

        mediaRecorder.addEventListener('dataavailable', event => {
            if (!event.data || event.data.size === 0) {
                return;
            }
            localSequence += 1;
            enqueueLocalUpload(event.data, 'video_chunk', 'continuous_recording').catch(error => {
                if (!submitting) {
                    signalFailure('local_upload_failed', error.message || 'Local recording upload failed.');
                }
            });
        });

        mediaRecorder.addEventListener('error', event => {
            const message = event.error && event.error.message ? event.error.message : 'Local recorder error.';
            signalFailure('media_recorder_error', message);
        });

        mediaRecorder.start(Math.max(2000, Number(chunkMilliseconds) || 5000));
    };

    const stopLocalRecorder = async() => {
        if (!mediaRecorder || mediaRecorder.state === 'inactive') {
            await waitForLocalUploads();
            return;
        }

        await new Promise(resolve => {
            mediaRecorder.addEventListener('stop', resolve, {once: true});
            try {
                mediaRecorder.requestData();
            } catch (error) {
                // Some browsers do not allow requestData during shutdown.
            }
            mediaRecorder.stop();
        });
        await waitForLocalUploads();
    };

    const signalFailure = (reason, message) => {
        if (failureSent || submitting) {
            return;
        }
        failureSent = true;
        setAttemptLocked(true);
        updateStatus('failed', config.strings.interrupted, config.strings.connectionLost, true);

        if (captureMode === 'localtest') {
            stopLocalRecorder()
                .catch(() => {})
                .then(() => apiRequest('media_failure', {reason: reason, message: message}, true))
                .catch(() => {});
            stopLocalTracks();
        } else {
            apiRequest('media_failure', {reason: reason, message: message}, true).catch(() => {});
            disconnectRoom();
        }

        window.dispatchEvent(new CustomEvent('proctorcore:capturefailed', {
            detail: {reason: reason, message: message},
        }));
    };

    const requestSnapshot = (reason, violationId = null, keepalive = false) => {
        if (captureMode === 'localtest') {
            return captureLocalSnapshot(reason, violationId);
        }
        const payload = {reason: reason};
        if (violationId) {
            payload.violationId = Number(violationId);
        }
        return apiRequest('snapshot', payload, keepalive);
    };

    const isFinalSubmission = (form, submitter) => {
        const submitterName = submitter ? String(submitter.getAttribute('name') || '') : '';
        return submitterName === 'finishattempt' || Boolean(form.querySelector('input[name="finishattempt"]'));
    };

    const finishLocalSubmission = async(form, submitter) => {
        updateStatus('stopping', config.strings.finalising, config.strings.submissionSnapshot);
        await captureLocalSnapshot('submission');
        await stopLocalRecorder();
        await apiRequest('stop', {reason: 'submitted'});
        stopLocalTracks();

        allowFinalSubmit = true;
        setAttemptLocked(false);
        if (submitter && typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter);
        } else {
            HTMLFormElement.prototype.submit.call(form);
        }
    };

    const bindSubmission = () => {
        document.addEventListener('submit', event => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const submitter = event.submitter || null;
            const isFinal = isFinalSubmission(form, submitter);
            if (!isFinal) {
                if (captureMode === 'localtest' && mediaRecorder && mediaRecorder.state === 'recording') {
                    try {
                        mediaRecorder.requestData();
                    } catch (error) {
                        // Continue normal Moodle page navigation.
                    }
                }
                return;
            }
            if (allowFinalSubmit) {
                return;
            }
            if (submitting) {
                event.preventDefault();
                return;
            }

            submitting = true;
            if (captureMode === 'localtest') {
                event.preventDefault();
                event.stopImmediatePropagation();
                setAttemptLocked(true);
                finishLocalSubmission(form, submitter).catch(error => {
                    submitting = false;
                    failureSent = true;
                    setAttemptLocked(true);
                    updateStatus(
                        'failed',
                        config.strings.captureFailed,
                        error.message || config.strings.captureFailed,
                        true
                    );
                });
                return;
            }

            updateStatus('stopping', config.strings.finalising, config.strings.submissionSnapshot);
            requestSnapshot('submission', null, true)
                .catch(() => {})
                .then(() => apiRequest('stop', {reason: 'submitted'}, true))
                .catch(() => {});
        }, true);
    };

    const bindViolationEvents = () => {
        window.addEventListener('proctorcore:violation', event => {
            const detail = event.detail || {};
            requestSnapshot('violation', detail.violationId || null).catch(error => {
                window.console.warn('ProctorCore violation snapshot failed:', error);
            });
        });
    };

    const connectServerB = async bootstrap => {
        const LivekitClient = await loadScript(bootstrap.clientScriptUrl);

        room = new LivekitClient.Room({
            adaptiveStream: true,
            dynacast: true,
            disconnectOnPageLeave: true,
        });

        if (LivekitClient.RoomEvent) {
            room.on(LivekitClient.RoomEvent.Reconnecting, () => {
                setAttemptLocked(true);
                updateStatus('connecting', config.strings.reconnecting, config.strings.reconnectingMessage);
            });
            room.on(LivekitClient.RoomEvent.Reconnected, () => {
                failureSent = false;
                setAttemptLocked(false);
                updateStatus('recording', config.strings.recording, config.strings.recordingMessage);
            });
            room.on(LivekitClient.RoomEvent.MediaDevicesError, error => {
                signalFailure('media_device_error', error && error.message ? error.message : 'Media device error.');
            });
            room.on(LivekitClient.RoomEvent.Disconnected, () => {
                if (!pageLeaving && !submitting) {
                    signalFailure('media_connection_disconnected', 'Live media connection ended.');
                }
            });
        }

        await room.connect(bootstrap.url, bootstrap.token);
        await room.localParticipant.enableCameraAndMicrophone();
        attachLiveKitVideo(LivekitClient);
        return apiRequest('start', {reason: 'attempt_page_connected'});
    };

    const connectLocal = async bootstrap => {
        localUploadUrl = bootstrap.uploadUrl || '';
        localStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: {ideal: 1280},
                height: {ideal: 720},
            },
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
            },
        });

        localStream.getTracks().forEach(track => {
            track.addEventListener('ended', () => {
                window.dispatchEvent(new CustomEvent('proctorcore:mediaended', {
                    detail: {kind: track.kind, reason: 'media_track_ended'},
                }));
                signalFailure('media_track_ended', `${track.kind} track ended.`);
            }, {once: true});
        });
        await attachLocalStream(localStream);

        const started = await apiRequest('start', {reason: 'attempt_page_connected'});
        localSegment = Number(started.segment) || 1;
        startLocalRecorder(bootstrap.chunkMilliseconds || 5000);
        await captureLocalSnapshot('identity_verification');
        return started;
    };

    const connect = async() => {
        setAttemptLocked(true);
        buildPanel();
        updateStatus('connecting', config.strings.connecting, config.strings.permissions);

        const bootstrap = await apiRequest('bootstrap');
        captureMode = bootstrap.mode === 'localtest' ? 'localtest' : 'serverb';
        const started = captureMode === 'localtest'
            ? await connectLocal(bootstrap)
            : await connectServerB(bootstrap);

        failureSent = false;
        setAttemptLocked(false);
        updateStatus(
            'recording',
            captureMode === 'localtest' ? (config.strings.localMode || config.strings.recording) : config.strings.recording,
            captureMode === 'localtest'
                ? (config.strings.localModeMessage || config.strings.recordingMessage)
                : config.strings.recordingMessage
        );

        if (captureMode !== 'localtest') {
            // Snapshot failure must not stop an otherwise healthy full-session recording.
            requestSnapshot('identity_verification').catch(error => {
                window.console.warn('ProctorCore identity snapshot request failed:', error);
            });
        }

        window.dispatchEvent(new CustomEvent('proctorcore:captureconnected', {
            detail: {
                sessionId: Number(config.sessionId),
                serverSessionId: bootstrap.serverSessionId,
                roomId: bootstrap.roomId,
                segment: started.segment || null,
                mode: captureMode,
            },
        }));
    };

    return {
        /**
         * Starts Section 1.1 browser capture.
         *
         * @param {Object} options Configuration from Moodle PHP.
         */
        init: function(options) {
            config = options;
            window.ProctorCoreCapture = {
                getStream: () => localStream,
                getVideoElement: panelVideo,
                getMode: () => captureMode,
                requestSnapshot: requestSnapshot,
            };
            bindSubmission();
            bindViolationEvents();
            window.addEventListener('offline', () => signalFailure('browser_offline', 'Browser reported offline.'));
            window.addEventListener('beforeunload', () => {
                pageLeaving = true;
                if (captureMode === 'localtest' && mediaRecorder && mediaRecorder.state === 'recording') {
                    try {
                        mediaRecorder.requestData();
                    } catch (error) {
                        // Browser is already leaving.
                    }
                }
            });
            window.addEventListener('pagehide', () => {
                pageLeaving = true;
            });
            connect().catch(error => {
                failureSent = true;
                setAttemptLocked(true);
                disconnectRoom();
                stopLocalTracks();
                updateStatus(
                    'failed',
                    config.strings.captureFailed,
                    error.message || config.strings.captureFailed,
                    true
                );
                window.dispatchEvent(new CustomEvent('proctorcore:capturefailed', {
                    detail: {reason: 'startup_failed', message: error.message || ''},
                }));
            });
        },
    };
});
>>>>>>> origin/danial
