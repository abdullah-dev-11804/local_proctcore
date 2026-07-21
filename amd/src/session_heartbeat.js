// This file is part of Moodle - http://moodle.org/

/**
 * Sends browser heartbeats for Section 5.3 connection recovery.
 *
 * @module local_proctorcore/session_heartbeat
 */
define([], function() {
    const emit = (name, detail) => {
        window.dispatchEvent(new CustomEvent(name, {detail: detail}));
    };

    const send = async(sessionId) => {
        const body = new URLSearchParams();
        body.set('sessionid', String(sessionId));
        body.set('sesskey', M.cfg.sesskey);

        const response = await fetch(M.cfg.wwwroot + '/local/proctorcore/heartbeat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
        });

        const result = await response.json();
        if (!response.ok || result.ok !== true) {
            throw new Error(result.message || result.error || 'Heartbeat failed');
        }

        if (result.status === 'interrupted') {
            emit('proctorcore:interrupted', result);
        } else {
            emit('proctorcore:heartbeat', result);
        }
        return result;
    };

    return {
        /**
         * Starts heartbeat delivery.
         *
         * @param {Object} config Configuration.
         * @param {Number} config.sessionId Local session id.
         * @param {Number} config.intervalSeconds Heartbeat interval.
         */
        init: function(config) {
            const sessionId = Number(config.sessionId || 0);
            const intervalSeconds = Math.max(5, Number(config.intervalSeconds || 15));
            if (!sessionId) {
                return;
            }

            let timer = null;
            const tick = async() => {
                try {
                    await send(sessionId);
                } catch (error) {
                    emit('proctorcore:connectionlost', {
                        sessionId: sessionId,
                        message: error.message,
                    });
                }
            };

            tick();
            timer = window.setInterval(tick, intervalSeconds * 1000);

            window.addEventListener('online', tick);
            window.addEventListener('beforeunload', function() {
                if (timer) {
                    window.clearInterval(timer);
                }
            }, {once: true});
        },
    };
});
