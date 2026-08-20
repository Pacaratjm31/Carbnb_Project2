/**
 * ============================================================
 * GPS TRACKER - Cross-page continuous GPS tracking
 * 
 * UPDATED: More robust error handling and logging.
 * ============================================================
 */

(function() {
    'use strict';

    // ============================================================
    // PRIVATE VARIABLES
    // ============================================================
    let watchId = null;
    let isTracking = false;
    let currentBookingId = null;
    let recoveryTimeout = null;
    let lastSentPosition = null;

    const STORAGE_KEY = 'carbnb_gps_tracking';

    // ============================================================
    // PRIVATE: Build reliable API URL
    // ============================================================
    function getApiUrl() {
        var protocol = window.location.protocol;
        var host = window.location.host;
        var pathname = window.location.pathname;
        
        var projectRoot = '/';
        var pathParts = pathname.split('/').filter(function(p) { return p.length > 0; });
        
        if (pathParts.indexOf('renter') !== -1 || pathParts.indexOf('admin') !== -1) {
            var projectIndex = 0;
            for (var i = 0; i < pathParts.length; i++) {
                if (pathParts[i] === 'renter' || pathParts[i] === 'admin') {
                    projectIndex = i;
                    break;
                }
            }
            if (projectIndex > 0) {
                projectRoot = '/' + pathParts.slice(0, projectIndex).join('/') + '/';
            } else {
                projectRoot = '/';
            }
        }
        if (projectRoot.length > 1 && projectRoot.charAt(projectRoot.length - 1) !== '/') {
            projectRoot += '/';
        }
        
        var apiUrl = protocol + '//' + host + projectRoot + 'admin/location_tracker.php';
        console.log('[GPSTracker] API URL resolved to:', apiUrl);
        return apiUrl;
    }

    // ============================================================
    // PRIVATE: Send GPS Location to Server
    // ============================================================
    function sendLocationToServer(position, bookingId) {
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] Invalid booking_id:', bookingId);
            return Promise.reject(new Error('Invalid booking_id for location tracking'));
        }

        var latitude = position.coords.latitude;
        var longitude = position.coords.longitude;
        var accuracy = position.coords.accuracy || 0;
        var recorded_at = new Date().toISOString().slice(0, 19).replace('T', ' ');

        var apiUrl = getApiUrl();

        var formData = new URLSearchParams();
        formData.append('latitude', latitude);
        formData.append('longitude', longitude);
        formData.append('accuracy', accuracy);
        formData.append('recorded_at', recorded_at);
        formData.append('booking_id', bookingId);

        console.log('[GPSTracker] Sending GPS for booking:', bookingId, 'lat:', latitude, 'lng:', longitude);

        return fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data.success) {
                if (data.stop_tracking) {
                    console.log('[GPSTracker] Server reports booking is no longer active - stopping tracking.');
                    stop();
                }
                throw new Error(data.message || 'Server error');
            }
            console.log('[GPSTracker] Location saved successfully');
            lastSentPosition = position;
            return data;
        });
    }

    // ============================================================
    // PRIVATE: Clear Current Watch Only (preserves state)
    // ============================================================
    function clearCurrentWatch() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            console.log('[GPSTracker] Current GPS watch cleared');
        }
        if (recoveryTimeout) {
            clearTimeout(recoveryTimeout);
            recoveryTimeout = null;
        }
        isTracking = false;
    }

    // ============================================================
    // PRIVATE: Start the Watch (internal)
    // ============================================================
    function startWatch(bookingId) {
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] Cannot start: invalid booking_id');
            return;
        }

        if (!navigator.geolocation) {
            console.error('[GPSTracker] Geolocation not supported by this browser');
            return;
        }

        clearCurrentWatch();
        console.log('[GPSTracker] Starting GPS watch for booking:', bookingId);
        currentBookingId = bookingId;

        watchId = navigator.geolocation.watchPosition(
            function(position) {
                console.log('[GPSTracker] GPS update received for booking:', bookingId);
                sendLocationToServer(position, bookingId)
                    .then(function() {
                        // Success - nothing else needed
                    })
                    .catch(function(err) {
                        console.warn('[GPSTracker] GPS save failed:', err.message);
                    });
            },
            function(error) {
                console.warn('[GPSTracker] GPS watch error:', error.code, error.message);
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                if (recoveryTimeout) {
                    clearTimeout(recoveryTimeout);
                }
                recoveryTimeout = setTimeout(function() {
                    if (!watchId && currentBookingId) {
                        console.log('[GPSTracker] Recovering GPS tracking for booking:', currentBookingId);
                        startWatch(currentBookingId);
                    }
                }, 5000);
            },
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 5000
            }
        );

        isTracking = true;

        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                active: true,
                booking_id: bookingId,
                started_at: new Date().toISOString()
            }));
            console.log('[GPSTracker] State saved to sessionStorage');
        } catch (e) {
            console.warn('[GPSTracker] Could not save tracking state:', e.message);
        }

        console.log('[GPSTracker] GPS watch active, watchId:', watchId);
    }

    // ============================================================
    // PUBLIC API
    // ============================================================

    function start(bookingId) {
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] start() called with invalid booking_id:', bookingId);
            return;
        }
        console.log('[GPSTracker] start() called for booking:', bookingId);
        startWatch(bookingId);
    }

    function stop() {
        console.log('[GPSTracker] stop() called - permanently terminating tracking');
        clearCurrentWatch();
        currentBookingId = null;
        try {
            sessionStorage.removeItem(STORAGE_KEY);
            console.log('[GPSTracker] sessionStorage cleared');
        } catch (e) {}
        console.log('[GPSTracker] GPS tracking terminated');
    }

    function resume() {
        console.log('[GPSTracker] resume() called');
        try {
            var data = sessionStorage.getItem(STORAGE_KEY);
            if (!data) {
                console.log('[GPSTracker] No tracking state found in sessionStorage');
                return false;
            }
            var state = JSON.parse(data);
            if (!state.active || !state.booking_id) {
                console.log('[GPSTracker] Tracking state is inactive or missing booking_id');
                return false;
            }
            console.log('[GPSTracker] Found tracking state for booking:', state.booking_id);
            startWatch(state.booking_id);
            return true;
        } catch (e) {
            console.warn('[GPSTracker] Error resuming tracking:', e.message);
            return false;
        }
    }

    function isActive() {
        return isTracking && watchId !== null;
    }

    function getState() {
        try {
            var data = sessionStorage.getItem(STORAGE_KEY);
            if (data) {
                return JSON.parse(data);
            }
        } catch (e) {}
        return null;
    }

    function getBookingId() {
        return currentBookingId;
    }

    // ============================================================
    // EXPOSE PUBLIC API
    // ============================================================
    window.GPSTracker = {
        start: start,
        stop: stop,
        resume: resume,
        isActive: isActive,
        getState: getState,
        getBookingId: getBookingId
    };

    console.log('[GPSTracker] Loaded successfully. API available.');
})();