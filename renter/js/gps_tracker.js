/**
 * ============================================================
 * GPS TRACKER - Cross-page continuous GPS tracking
 * 
 * This file manages GPS tracking across booking pages (book.php → paid.php)
 * using sessionStorage to persist the tracking state.
 * 
 * USAGE:
 *   // Start tracking after booking is created
 *   GPSTracker.start(bookingId);
 * 
 *   // Resume tracking on paid.php
 *   GPSTracker.resume();
 * 
 *   // Stop tracking when booking ends
 *   GPSTracker.stop();
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

    // Storage key for cross-page state persistence
    const STORAGE_KEY = 'carbnb_gps_tracking';

    // ============================================================
    // PRIVATE: Build reliable API URL
    // ============================================================
    function getApiUrl() {
        // Get the current location
        var protocol = window.location.protocol;
        var host = window.location.host;
        var pathname = window.location.pathname;
        
        // Find the project root by looking for known patterns
        // Try to find the project root by checking if we're in /renter/ or /admin/
        var projectRoot = '/';
        
        // Check if we're in a subdirectory (e.g., /carbnb/renter/book.php)
        var pathParts = pathname.split('/').filter(function(p) { return p.length > 0; });
        
        // If the path includes 'renter' or 'admin', go up one level
        if (pathParts.indexOf('renter') !== -1 || pathParts.indexOf('admin') !== -1) {
            // Find the index of the directory that contains the project root
            var projectIndex = 0;
            for (var i = 0; i < pathParts.length; i++) {
                if (pathParts[i] === 'renter' || pathParts[i] === 'admin') {
                    projectIndex = i;
                    break;
                }
            }
            
            // If we found renter/admin, the project root is everything before it
            if (projectIndex > 0) {
                projectRoot = '/' + pathParts.slice(0, projectIndex).join('/') + '/';
            } else {
                projectRoot = '/';
            }
        }
        
        // Ensure projectRoot ends with '/'
        if (projectRoot.length > 1 && projectRoot.charAt(projectRoot.length - 1) !== '/') {
            projectRoot += '/';
        }
        
        // Build the full URL
        var apiUrl = protocol + '//' + host + projectRoot + 'admin/location_tracker.php';
        
        console.log('[GPSTracker] API URL resolved to:', apiUrl);
        return apiUrl;
    }

    // ============================================================
    // PRIVATE: Send GPS Location to Server
    // ============================================================
    function sendLocationToServer(position, bookingId) {
        // Validate booking_id
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] Invalid booking_id:', bookingId);
            return Promise.reject(new Error('Invalid booking_id for location tracking'));
        }

        var latitude = position.coords.latitude;
        var longitude = position.coords.longitude;
        var accuracy = position.coords.accuracy || 0;
        var recorded_at = new Date().toISOString().slice(0, 19).replace('T', ' ');

        var apiUrl = getApiUrl();

        // Build form data
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
                throw new Error(data.message || 'Server error');
            }
            console.log('[GPSTracker] Location saved successfully');
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
        // NOTE: currentBookingId and sessionStorage are NOT cleared
    }

    // ============================================================
    // PRIVATE: Start the Watch (internal)
    // ============================================================
    function startWatch(bookingId) {
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] Cannot start: invalid booking_id');
            return;
        }

        // Check if geolocation is available
        if (!navigator.geolocation) {
            console.error('[GPSTracker] Geolocation not supported by this browser');
            return;
        }

        // Clear any existing watcher (but preserve state)
        clearCurrentWatch();

        console.log('[GPSTracker] Starting GPS watch for booking:', bookingId);
        currentBookingId = bookingId;

        // ============================================================
        // Start the watchPosition
        // ============================================================
        watchId = navigator.geolocation.watchPosition(
            // Success handler
            function(position) {
                console.log('[GPSTracker] GPS update received for booking:', bookingId);
                
                sendLocationToServer(position, bookingId)
                    .then(function() {
                        // Location saved successfully - nothing else needed
                    })
                    .catch(function(err) {
                        console.warn('[GPSTracker] GPS save failed:', err.message);
                        // Don't stop tracking on save failure - retry on next update
                    });
            },

            // Error handler - auto-recovery
            function(error) {
                console.warn('[GPSTracker] GPS watch error:', error.code, error.message);

                // Clear the failed watcher
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }

                // Automatic recovery after 5 seconds
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

            // Options
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 5000
            }
        );

        isTracking = true;

        // ============================================================
        // Save state to sessionStorage for cross-page persistence
        // ============================================================
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

    /**
     * Start GPS tracking for a booking.
     * This creates a new watchPosition() and saves state to sessionStorage.
     * 
     * @param {number} bookingId - The real booking ID from the database
     */
    function start(bookingId) {
        if (!bookingId || bookingId < 1) {
            console.error('[GPSTracker] start() called with invalid booking_id:', bookingId);
            return;
        }

        console.log('[GPSTracker] start() called for booking:', bookingId);
        startWatch(bookingId);
    }

    /**
     * Stop GPS tracking permanently.
     * This clears the watcher, cancels recovery, and removes sessionStorage state.
     * Only call this when the booking has actually ended.
     */
    function stop() {
        console.log('[GPSTracker] stop() called - permanently terminating tracking');

        // Clear the watcher
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        if (recoveryTimeout) {
            clearTimeout(recoveryTimeout);
            recoveryTimeout = null;
        }

        isTracking = false;
        currentBookingId = null;

        // Remove sessionStorage
        try {
            sessionStorage.removeItem(STORAGE_KEY);
            console.log('[GPSTracker] sessionStorage cleared');
        } catch (e) {
            // Ignore
        }

        console.log('[GPSTracker] GPS tracking terminated');
    }

    /**
     * Resume tracking from sessionStorage state.
     * Call this explicitly on pages where tracking should continue (e.g., paid.php).
     * 
     * @returns {boolean} True if tracking was resumed, false otherwise
     */
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
            
            // Start tracking with the saved booking_id
            startWatch(state.booking_id);
            return true;

        } catch (e) {
            console.warn('[GPSTracker] Error resuming tracking:', e.message);
            return false;
        }
    }

    /**
     * Check if tracking is currently active.
     * 
     * @returns {boolean} True if tracking is active
     */
    function isActive() {
        return isTracking && watchId !== null;
    }

    /**
     * Get the current tracking state from sessionStorage.
     * 
     * @returns {object|null} The tracking state or null if not found
     */
    function getState() {
        try {
            var data = sessionStorage.getItem(STORAGE_KEY);
            if (data) {
                return JSON.parse(data);
            }
        } catch (e) {
            // Ignore
        }
        return null;
    }

    /**
     * Get the current booking ID being tracked.
     * 
     * @returns {number|null} The booking ID or null if not tracking
     */
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

    // ============================================================
    // DO NOT auto-resume - leave explicit control to the pages
    // ============================================================
    console.log('[GPSTracker] Loaded successfully. API available: GPSTracker.start(), .resume(), .stop()');

})();