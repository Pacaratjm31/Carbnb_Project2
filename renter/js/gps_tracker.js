/**
 * ============================================================
 * CARBNB GPS TRACKER
 * ============================================================
 *
 * Purpose:
 * - Continuously track renter GPS location
 * - Send location to admin/location_tracker.php
 * - Keep tracking active while navigating between pages
 * - Store booking_id in localStorage (survives closing the app)
 *
 * LIVE SERVER:
 * https://carbnb.infinityfree.me/
 *
 * API:
 * https://carbnb.infinityfree.me/admin/location_tracker.php
 * ============================================================
 */

(function () {
    'use strict';

    // ============================================================
    // PRIVATE VARIABLES
    // ============================================================

    let watchId = null;
    let isTracking = false;
    let currentBookingId = null;
    let recoveryTimeout = null;
    let lastSentPosition = null;
    let heartbeatId = null;

    const STORAGE_KEY = 'carbnb_gps_tracking';

    // watchPosition() only fires when the device's GPS position
    // actually changes. A stationary renter can go 30+ seconds with
    // no callback at all, even though location sharing is still
    // fully on - without a heartbeat, the admin dashboard's "active"
    // status (based on how recent the last saved point is) would
    // incorrectly flag them as "inactive" just for not moving.
    const HEARTBEAT_INTERVAL_MS = 15000;

    // ============================================================
    // API URL
    // ============================================================

    function getApiUrl() {

        // Uses the current website domain automatically.
        //
        // On InfinityFree:
        // https://carbnb.infinityfree.me/admin/location_tracker.php

        var apiUrl = window.location.origin + '/admin/location_tracker.php';

        console.log('[GPSTracker] API URL:', apiUrl);

        return apiUrl;
    }

    // ============================================================
    // SEND LOCATION TO SERVER
    // ============================================================

    function sendLocationToServer(position, bookingId) {

        if (!bookingId || bookingId < 1) {
            console.error(
                '[GPSTracker] Invalid booking_id:',
                bookingId
            );

            return Promise.reject(
                new Error('Invalid booking_id for location tracking')
            );
        }

        var latitude = position.coords.latitude;
        var longitude = position.coords.longitude;
        var accuracy = position.coords.accuracy || 0;

        var recorded_at = new Date()
            .toISOString()
            .slice(0, 19)
            .replace('T', ' ');

        var apiUrl = getApiUrl();

        var formData = new URLSearchParams();

        formData.append('latitude', latitude);
        formData.append('longitude', longitude);
        formData.append('accuracy', accuracy);
        formData.append('recorded_at', recorded_at);
        formData.append('booking_id', bookingId);

        console.log(
            '[GPSTracker] Sending GPS:',
            {
                booking_id: bookingId,
                latitude: latitude,
                longitude: longitude,
                accuracy: accuracy,
                recorded_at: recorded_at
            }
        );

        return fetch(apiUrl, {
            method: 'POST',

            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },

            body: formData.toString()

        })

        .then(function (response) {

            console.log(
                '[GPSTracker] Server response:',
                response.status,
                response.statusText
            );

            if (!response.ok) {

                throw new Error(
                    'HTTP ' +
                    response.status +
                    ': ' +
                    response.statusText
                );
            }

            return response.text();
        })

        .then(function (text) {

            console.log(
                '[GPSTracker] Server raw response:',
                text
            );

            var data;

            try {

                data = JSON.parse(text);

            } catch (e) {

                throw new Error(
                    'Server returned invalid JSON: ' + text
                );
            }

            if (!data.success) {

                if (data.stop_tracking) {

                    console.log(
                        '[GPSTracker] Server says booking is no longer active.'
                    );

                    stop();
                }

                throw new Error(
                    data.message || 'Server error'
                );
            }

            console.log(
                '[GPSTracker] ✅ Location saved successfully'
            );

            lastSentPosition = position;

            return data;
        });
    }

    // ============================================================
    // CLEAR CURRENT GPS WATCH
    // ============================================================

    function clearCurrentWatch() {

        if (watchId !== null) {

            navigator.geolocation.clearWatch(
                watchId
            );

            watchId = null;

            console.log(
                '[GPSTracker] GPS watch cleared'
            );
        }

        if (recoveryTimeout) {

            clearTimeout(recoveryTimeout);

            recoveryTimeout = null;
        }

        if (heartbeatId) {

            clearInterval(heartbeatId);

            heartbeatId = null;

            console.log(
                '[GPSTracker] Heartbeat cleared'
            );
        }

        isTracking = false;
    }

    // ============================================================
    // START GPS WATCH
    // ============================================================

    function startWatch(bookingId) {

        if (!bookingId || bookingId < 1) {

            console.error(
                '[GPSTracker] Cannot start GPS:',
                'invalid booking_id'
            );

            return;
        }

        if (!navigator.geolocation) {

            console.error(
                '[GPSTracker] Geolocation is not supported'
            );

            return;
        }

        clearCurrentWatch();

        currentBookingId = bookingId;

        console.log(
            '[GPSTracker] ================================='
        );

        console.log(
            '[GPSTracker] Starting GPS tracking'
        );

        console.log(
            '[GPSTracker] Booking ID:',
            bookingId
        );

        console.log(
            '[GPSTracker] ================================='
        );

        // ========================================================
        // START CONTINUOUS GPS WATCH
        // ========================================================

        watchId = navigator.geolocation.watchPosition(

            function (position) {

                console.log(
                    '[GPSTracker] 📍 GPS UPDATE RECEIVED'
                );

                console.log(
                    '[GPSTracker] Latitude:',
                    position.coords.latitude
                );

                console.log(
                    '[GPSTracker] Longitude:',
                    position.coords.longitude
                );

                console.log(
                    '[GPSTracker] Accuracy:',
                    position.coords.accuracy
                );

                sendLocationToServer(
                    position,
                    bookingId
                )

                .then(function (data) {

                    console.log(
                        '[GPSTracker] ✅ GPS sent successfully'
                    );

                })

                .catch(function (error) {

                    console.error(
                        '[GPSTracker] ❌ GPS upload failed:',
                        error.message
                    );
                });
            },

            function (error) {

                console.error(
                    '[GPSTracker] ❌ GPS WATCH ERROR'
                );

                console.error(
                    '[GPSTracker] Error code:',
                    error.code
                );

                console.error(
                    '[GPSTracker] Error message:',
                    error.message
                );

                if (watchId !== null) {

                    navigator.geolocation.clearWatch(
                        watchId
                    );

                    watchId = null;
                }

                if (recoveryTimeout) {

                    clearTimeout(
                        recoveryTimeout
                    );
                }

                // Try to recover GPS after 5 seconds.

                recoveryTimeout = setTimeout(
                    function () {

                        if (
                            !watchId &&
                            currentBookingId
                        ) {

                            console.log(
                                '[GPSTracker] 🔄 Recovering GPS tracking...'
                            );

                            startWatch(
                                currentBookingId
                            );
                        }

                    },
                    5000
                );
            },

            {
                enableHighAccuracy: true,

                // Maximum time to wait for a GPS update
                timeout: 30000,

                // Accept reasonably fresh GPS positions
                maximumAge: 5000
            }
        );

        isTracking = true;

        // ========================================================
        // HEARTBEAT - resend last known position on a fixed
        // interval regardless of movement, so a stationary renter
        // still shows as "active" on the admin dashboard.
        // ========================================================

        heartbeatId = setInterval(function () {

            if (!lastSentPosition || !currentBookingId) {
                return;
            }

            console.log(
                '[GPSTracker] Heartbeat - resending last known position'
            );

            sendLocationToServer(lastSentPosition, currentBookingId)
                .catch(function (err) {
                    console.warn(
                        '[GPSTracker] Heartbeat send failed:',
                        err.message
                    );
                });

        }, HEARTBEAT_INTERVAL_MS);

        // ========================================================
        // SAVE TRACKING STATE
        // localStorage (not sessionStorage) so tracking state
        // survives the renter fully closing and reopening the
        // browser/app across a multi-day booking, not just the
        // current tab session.
        // ========================================================

        try {

            localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({
                    active: true,
                    booking_id: bookingId,
                    started_at:
                        new Date().toISOString()
                })
            );

            console.log(
                '[GPSTracker] Tracking state saved'
            );

        } catch (e) {

            console.warn(
                '[GPSTracker] Could not save tracking state:',
                e.message
            );
        }

        console.log(
            '[GPSTracker] GPS watch active'
        );

        console.log(
            '[GPSTracker] Watch ID:',
            watchId
        );
    }

    // ============================================================
    // PUBLIC: START
    // ============================================================

    function start(bookingId) {

        if (!bookingId || bookingId < 1) {

            console.error(
                '[GPSTracker] start() invalid booking_id:',
                bookingId
            );

            return;
        }

        console.log(
            '[GPSTracker] start() called:',
            bookingId
        );

        startWatch(bookingId);
    }

    // ============================================================
    // PUBLIC: STOP
    // ============================================================

    function stop() {

        console.log(
            '[GPSTracker] 🛑 STOPPING GPS TRACKING'
        );

        clearCurrentWatch();

        currentBookingId = null;

        lastSentPosition = null;

        try {

            localStorage.removeItem(
                STORAGE_KEY
            );

            console.log(
                '[GPSTracker] Tracking state removed'
            );

        } catch (e) {}

        console.log(
            '[GPSTracker] GPS tracking terminated'
        );
    }

    // ============================================================
    // PUBLIC: RESUME
    // ============================================================

    function resume() {

        console.log(
            '[GPSTracker] resume() called'
        );

        try {

            var data =
                localStorage.getItem(
                    STORAGE_KEY
                );

            if (!data) {

                console.log(
                    '[GPSTracker] No saved tracking state'
                );

                return false;
            }

            var state =
                JSON.parse(data);

            if (
                !state.active ||
                !state.booking_id
            ) {

                console.log(
                    '[GPSTracker] Saved tracking state invalid'
                );

                return false;
            }

            console.log(
                '[GPSTracker] Resuming booking:',
                state.booking_id
            );

            startWatch(
                state.booking_id
            );

            return true;

        } catch (e) {

            console.error(
                '[GPSTracker] Resume error:',
                e.message
            );

            return false;
        }
    }

    // ============================================================
    // PUBLIC: IS ACTIVE
    // ============================================================

    function isActive() {

        return (
            isTracking &&
            watchId !== null
        );
    }

    // ============================================================
    // PUBLIC: GET STATE
    // ============================================================

    function getState() {

        try {

            var data =
                localStorage.getItem(
                    STORAGE_KEY
                );

            if (data) {

                return JSON.parse(data);
            }

        } catch (e) {

            console.warn(
                '[GPSTracker] Could not read state'
            );
        }

        return null;
    }

    // ============================================================
    // PUBLIC: GET BOOKING ID
    // ============================================================

    function getBookingId() {

        return currentBookingId;
    }

    // ============================================================
    // PUBLIC: SEND HEARTBEAT NOW
    // Lets an outside caller (e.g. a service worker) trigger an
    // immediate resend of the last known position. Safe to call
    // any time; no-op if tracking isn't active.
    // ============================================================

    function sendHeartbeatNow() {

        if (!lastSentPosition || !currentBookingId) {

            console.log(
                '[GPSTracker] sendHeartbeatNow() called but nothing to send yet.'
            );

            return;
        }

        console.log(
            '[GPSTracker] Manually triggered heartbeat send.'
        );

        sendLocationToServer(lastSentPosition, currentBookingId)
            .catch(function (err) {
                console.warn(
                    '[GPSTracker] Manual heartbeat send failed:',
                    err.message
                );
            });
    }

    // ============================================================
    // EXPOSE GPS TRACKER
    // ============================================================

    window.GPSTracker = {

        start: start,

        stop: stop,

        resume: resume,

        isActive: isActive,

        getState: getState,

        getBookingId: getBookingId,

        sendHeartbeatNow: sendHeartbeatNow
    };

    // ============================================================
    // INITIALIZE
    // ============================================================

    console.log(
        '[GPSTracker] ================================='
    );

    console.log(
        '[GPSTracker] GPS TRACKER LOADED'
    );

    console.log(
        '[GPSTracker] Website:',
        window.location.origin
    );

    console.log(
        '[GPSTracker] API:',
        getApiUrl()
    );

    console.log(
        '[GPSTracker] Geolocation supported:',
        !!navigator.geolocation
    );

    console.log(
        '[GPSTracker] ================================='
    );

})();