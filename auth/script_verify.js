(async () => {

    const video = document.getElementById("video");
    const verifyBtn = document.getElementById("verifyBtn");
    const statusMessage = document.getElementById("statusMessage");
    const loadingOverlay = document.getElementById("loadingOverlay");
    const faceIndicator = document.getElementById("faceIndicator");

    // Get the descriptor from window
    const registeredFaceDescriptor = window.registeredFaceDescriptor || [];

    console.log("=== SCRIPT VERIFY START ===");
    console.log("registeredFaceDescriptor length:", registeredFaceDescriptor.length);

    let faceDetected = false;
    let isVerifying = false;
    let modelsLoaded = false;
    let detectionInterval = null;
    let currentDetection = null;
    let modelLoadAttempts = 0;
    let videoReady = false;
    const MAX_MODEL_ATTEMPTS = 3;
    const MIN_FACE_SIZE = 80;

    // ============================================================
    // Store the latest valid descriptor for verification
    // ============================================================
    let latestLiveDescriptor = null;

    // ============================================================
    // FIX: Track if user is already recognized - this is a LOCK
    // Once true, it stays true and button stays enabled
    // ============================================================
    let isRecognized = false;

    // ============================================================
    // DIAGNOSTIC LOGGING VARIABLES
    // ============================================================
    let diagnosticLogs = [];

    function updateStatus(message) {
        statusMessage.textContent = message;
        console.log("[Face Verify]", message);
    }

    // CHECK: If no descriptor, show error immediately
    if (!registeredFaceDescriptor || registeredFaceDescriptor.length === 0) {
        updateStatus("❌ No registered face template available.");
        verifyBtn.disabled = true;
        console.error("No face descriptor found in window.registeredFaceDescriptor");
    } else {
        console.log("✅ Face descriptor found! Length:", registeredFaceDescriptor.length);
    }

    // ============================================================
    // HELPER FUNCTION TO GET FACE POSITION
    // ============================================================
    function getFacePosition(box) {
        const videoWidth = video.videoWidth || 640;
        const videoHeight = video.videoHeight || 480;
        
        const cx = box.x + (box.width / 2);
        const cy = box.y + (box.height / 2);
        
        const hCenter = cx / videoWidth;
        const vCenter = cy / videoHeight;
        
        let pos = '';
        
        // Horizontal position
        if (hCenter < 0.35) {
            pos += 'far-left';
        } else if (hCenter < 0.45) {
            pos += 'slightly-left';
        } else if (hCenter < 0.55) {
            pos += 'center';
        } else if (hCenter < 0.65) {
            pos += 'slightly-right';
        } else {
            pos += 'far-right';
        }
        
        pos += ' / ';
        
        // Vertical position
        if (vCenter < 0.35) {
            pos += 'far-up';
        } else if (vCenter < 0.45) {
            pos += 'slightly-up';
        } else if (vCenter < 0.55) {
            pos += 'center';
        } else if (vCenter < 0.65) {
            pos += 'slightly-down';
        } else {
            pos += 'far-down';
        }
        
        return pos;
    }

    // ============================================================
    // HELPER FUNCTION TO LOG DISTANCE DATA
    // ============================================================
    function logDistanceData(liveDescriptor, distance, box, isMatch) {
        const position = box ? getFacePosition(box) : 'unknown';
        const timestamp = new Date().toISOString();
        const faceSize = box ? `${Math.round(box.width)}x${Math.round(box.height)}` : 'unknown';
        
        const logEntry = {
            timestamp: timestamp,
            position: position,
            faceSize: faceSize,
            distance: distance.toFixed(4),
            threshold: 0.55,
            isMatch: isMatch,
            descriptorLength: liveDescriptor ? liveDescriptor.length : 0
        };
        
        diagnosticLogs.push(logEntry);
        
        // Keep only last 100 logs to prevent memory issues
        if (diagnosticLogs.length > 100) {
            diagnosticLogs.shift();
        }
        
        console.log(`🔍 DISTANCE: ${distance.toFixed(4)} | POSITION: ${position} | SIZE: ${faceSize} | MATCH: ${isMatch ? 'YES ✅' : 'NO ❌'}`);
        console.log(`📊 Diagnostic data:`, logEntry);
    }

    // ============================================================
    // HELPER FUNCTION TO GET DIAGNOSTIC SUMMARY
    // ============================================================
    function getDiagnosticSummary() {
        if (diagnosticLogs.length === 0) {
            return "No diagnostic data collected yet.";
        }
        
        const summary = {
            totalAttempts: diagnosticLogs.length,
            distances: diagnosticLogs.map(log => parseFloat(log.distance)),
            matches: diagnosticLogs.filter(log => log.isMatch).length,
            byPosition: {}
        };
        
        // Group by position
        diagnosticLogs.forEach(log => {
            const pos = log.position;
            if (!summary.byPosition[pos]) {
                summary.byPosition[pos] = {
                    count: 0,
                    distances: []
                };
            }
            summary.byPosition[pos].count++;
            summary.byPosition[pos].distances.push(parseFloat(log.distance));
        });
        
        // Calculate averages per position
        Object.keys(summary.byPosition).forEach(pos => {
            const data = summary.byPosition[pos];
            const avg = data.distances.reduce((a, b) => a + b, 0) / data.distances.length;
            const min = Math.min(...data.distances);
            const max = Math.max(...data.distances);
            summary.byPosition[pos].average = avg.toFixed(4);
            summary.byPosition[pos].min = min.toFixed(4);
            summary.byPosition[pos].max = max.toFixed(4);
        });
        
        return summary;
    }

    // ============================================================
    // EXPOSE DIAGNOSTIC FUNCTIONS TO CONSOLE
    // ============================================================
    window.getFaceDiagnostics = function() {
        console.log("=== FACE DIAGNOSTIC SUMMARY ===");
        const summary = getDiagnosticSummary();
        console.log(`Total attempts: ${summary.totalAttempts}`);
        console.log(`Successful matches: ${summary.matches} / ${summary.totalAttempts}`);
        console.log("By position:");
        Object.keys(summary.byPosition).forEach(pos => {
            const data = summary.byPosition[pos];
            console.log(`  ${pos}:`);
            console.log(`    Count: ${data.count}`);
            console.log(`    Avg distance: ${data.average}`);
            console.log(`    Min distance: ${data.min}`);
            console.log(`    Max distance: ${data.max}`);
        });
        console.log("=== END SUMMARY ===");
        return summary;
    };
    
    window.clearFaceDiagnostics = function() {
        diagnosticLogs = [];
        console.log("Diagnostic logs cleared.");
    };

    async function startCamera() {
        try {
            updateStatus("Starting camera...");
            
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: "user"
                },
                audio: false
            });
            
            video.srcObject = stream;
            
            await new Promise((resolve) => {
                video.onloadedmetadata = () => {
                    video.play();
                    resolve();
                };
            });

            await new Promise((resolve) => {
                video.onplaying = () => {
                    videoReady = true;
                    resolve();
                };
                if (!video.paused && !video.ended) {
                    videoReady = true;
                    resolve();
                }
            });
            
            updateStatus("Camera ready. Loading models...");
            
        } catch (error) {
            console.error("Camera error:", error);
            updateStatus("Camera error: " + error.message);
            verifyBtn.disabled = true;
        }
    }

    async function loadModels() {
        try {
            updateStatus("Loading face models...");
            console.log("Starting model load...");
            
            if (typeof faceapi === 'undefined') {
                throw new Error('face-api.js not loaded');
            }

            // ============================================================
            // MOBILE PERFORMANCE FIX: use WebGL (GPU) when available.
            // Forcing 'cpu' here was the main cause of slow recognition
            // on mobile - desktop CPUs can brute-force TensorFlow ops
            // reasonably well, but phone CPUs are far weaker than their
            // own GPUs at this. WebGL lets the phone's GPU do the work.
            // Only fall back to CPU if WebGL genuinely isn't available.
            // ============================================================
            if (faceapi.tf && faceapi.tf.setBackend) {
                try {
                    await faceapi.tf.setBackend('webgl');
                    await faceapi.tf.ready();
                    console.log("TensorFlow backend: webgl (GPU-accelerated)");
                } catch (e) {
                    console.warn("WebGL backend unavailable, falling back to CPU:", e);
                    try {
                        await faceapi.tf.setBackend('cpu');
                        await faceapi.tf.ready();
                        console.log("TensorFlow backend: cpu (fallback)");
                    } catch (e2) {
                        console.warn("Could not set any backend explicitly, using default:", e2);
                    }
                }
            }

            const modelPath = '../face-api.js-models-master';
            
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(modelPath + '/tiny_face_detector'),
                faceapi.nets.faceLandmark68Net.loadFromUri(modelPath + '/face_landmark_68'),
                faceapi.nets.faceRecognitionNet.loadFromUri(modelPath + '/face_recognition')
            ]);

            modelsLoaded = true;
            if (loadingOverlay) loadingOverlay.classList.add('hidden');
            console.log("Models loaded successfully!");
            updateStatus("Face detection active.");
            
            // Check again if descriptor exists
            if (!window.registeredFaceDescriptor || window.registeredFaceDescriptor.length === 0) {
                updateStatus("❌ No registered face template available.");
                verifyBtn.disabled = true;
                return;
            }
            
            setTimeout(startFaceDetection, 500);

        } catch (error) {
            console.error("Model loading error:", error);
            modelLoadAttempts++;
            
            if (modelLoadAttempts < MAX_MODEL_ATTEMPTS) {
                updateStatus("Retrying model load (" + (modelLoadAttempts + 1) + "/" + MAX_MODEL_ATTEMPTS + ")...");
                setTimeout(loadModels, 2000);
            } else {
                updateStatus("Failed to load face models. Check console.");
                if (loadingOverlay) loadingOverlay.classList.add('hidden');
                verifyBtn.disabled = true;
            }
        }
    }

    // ============================================================
    // DETECTION INTERVAL WITH SINGLE-MATCH RECOGNITION
    // ============================================================
    function startFaceDetection() {
        if (detectionInterval) {
            clearInterval(detectionInterval);
        }

        if (!videoReady) {
            updateStatus("Waiting for camera...");
            setTimeout(startFaceDetection, 500);
            return;
        }

        updateStatus("Looking for face...");
        console.log("Face detection started");

        let noFaceCount = 0;
        let tickCount = 0;

        // ============================================================
        // MOBILE PERFORMANCE FIX: the full pipeline below
        // (.withFaceLandmarks().withFaceDescriptor()) is the expensive
        // part - it was running on every single 300ms tick, forever,
        // even after the person was already recognized. That's fine on
        // a desktop CPU/GPU but heavy enough on mobile to visibly stall
        // the page. Now: a cheap box-only presence check runs every
        // tick for responsive UI feedback, and the expensive descriptor
        // computation only runs every 3rd tick (~900ms) - still fast
        // enough to feel instant, at a third of the cost. Once matched,
        // the whole interval is cleared since there's nothing left to
        // detect.
        // ============================================================
        const HEAVY_CHECK_EVERY_N_TICKS = 3;

        detectionInterval = setInterval(async () => {
            if (!modelsLoaded || !videoReady) {
                return;
            }

            // Already recognized - stop spending CPU/GPU on further
            // detection. The Verify button is enabled; nothing left to do.
            if (isRecognized) {
                clearInterval(detectionInterval);
                detectionInterval = null;
                return;
            }

            try {
                if (video.readyState < 2) {
                    return;
                }

                tickCount++;
                const runHeavyCheck = (tickCount % HEAVY_CHECK_EVERY_N_TICKS === 0);

                if (!runHeavyCheck) {
                    // ============================================================
                    // CHEAP TICK: box presence/size only, no landmarks or
                    // descriptor - just keeps the UI responsive between
                    // the real recognition checks.
                    // ============================================================
                    const quickDetection = await faceapi.detectSingleFace(
                        video,
                        new faceapi.TinyFaceDetectorOptions({
                            inputSize: 224,
                            scoreThreshold: 0.5
                        })
                    );

                    if (!quickDetection) {
                        faceDetected = false;
                        currentDetection = null;
                        if (faceIndicator) faceIndicator.classList.remove('show');
                        noFaceCount++;
                        if (noFaceCount === 10) {
                            updateStatus("No face detected. Position your face in the camera.");
                        }
                        return;
                    }

                    noFaceCount = 0;
                    const box = quickDetection.box;
                    if (box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE) {
                        faceDetected = false;
                        currentDetection = null;
                        if (faceIndicator) faceIndicator.classList.remove('show');
                        updateStatus("Move closer to the camera.");
                    } else {
                        faceDetected = true;
                        if (faceIndicator) faceIndicator.classList.add('show');
                        updateStatus("Face detected. Confirming identity...");
                    }
                    return;
                }

                // ============================================================
                // HEAVY TICK: SINGLE DETECTION PIPELINE
                // Gets: face existence, face size, landmarks, descriptor
                // ============================================================
                const fullDetection = await faceapi
                    .detectSingleFace(
                        video,
                        new faceapi.TinyFaceDetectorOptions({
                            inputSize: 512,
                            scoreThreshold: 0.5
                        })
                    )
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                // ============================================================
                // CASE 1: No face detected
                // ============================================================
                if (!fullDetection) {
                    faceDetected = false;
                    currentDetection = null;
                    if (faceIndicator) faceIndicator.classList.remove('show');
                    noFaceCount++;
                    
                    if (noFaceCount === 10) {
                        updateStatus("No face detected. Position your face in the camera.");
                    }
                    
                    // FIX: Do NOT reset isRecognized or disable button
                    return;
                }

                noFaceCount = 0;
                const detection = fullDetection.detection;
                const box = detection.box;
                
                // ============================================================
                // Check face size
                // ============================================================
                if (box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE) {
                    faceDetected = false;
                    currentDetection = null;
                    if (faceIndicator) faceIndicator.classList.remove('show');
                    updateStatus("Move closer to the camera.");
                    
                    // FIX: Do NOT reset isRecognized or disable button
                    return;
                }

                faceDetected = true;
                currentDetection = detection;
                if (faceIndicator) faceIndicator.classList.add('show');

                // ============================================================
                // Check if this is the registered person
                // ============================================================
                let isMatchingPerson = false;
                let distance = 0;
                
                if (window.registeredFaceDescriptor && window.registeredFaceDescriptor.length > 0) {
                    try {
                        // Get descriptor from the single detection pipeline
                        const liveDescriptor = Array.from(fullDetection.descriptor);
                        
                        // ============================================================
                        // Calculate distance for diagnostic logging and matching
                        // ============================================================
                        const len = Math.min(liveDescriptor.length, window.registeredFaceDescriptor.length);
                        for (let i = 0; i < len; i++) {
                            const diff = liveDescriptor[i] - window.registeredFaceDescriptor[i];
                            distance += diff * diff;
                        }
                        distance = Math.sqrt(distance);
                        
                        // ============================================================
                        // Log diagnostic data
                        // ============================================================
                        if (diagnosticLogs.length % 3 === 0 || distance > 0.55) {
                            logDistanceData(liveDescriptor, distance, box, distance <= 0.55);
                        }
                        
                        // ============================================================
                        // KEPT: Match threshold remains 0.55 (same as PHP)
                        // ============================================================
                        const MATCH_THRESHOLD = 0.55;
                        isMatchingPerson = distance <= MATCH_THRESHOLD;
                        
                        // ============================================================
                        // FIX: Save descriptor on successful match
                        // Once saved, it remains available forever
                        // ============================================================
                        if (isMatchingPerson) {
                            latestLiveDescriptor = liveDescriptor;
                        }
                        
                        console.log(`[Match Check] Distance: ${distance.toFixed(4)} | Threshold: ${MATCH_THRESHOLD} | Match: ${isMatchingPerson ? 'YES ✅' : 'NO ❌'}`);
                        
                    } catch (err) {
                        console.warn("Descriptor comparison error:", err);
                        isMatchingPerson = false;
                        distance = 999;
                    }
                } else {
                    // No registered face template
                    if (!isRecognized) {
                        verifyBtn.disabled = true;
                    }
                    updateStatus("❌ No registered face template available.");
                    return;
                }

                // ============================================================
                // FIX: LOCKED recognition state
                // Once isRecognized becomes true, it stays true forever
                // ============================================================
                if (isMatchingPerson && !isRecognized) {
                    // First successful recognition - LOCK IT
                    isRecognized = true;
                    verifyBtn.disabled = false;
                    updateStatus("✅ Registered person recognized. Ready to verify.");
                    // Loop will clear itself on the next tick (isRecognized check above)
                } else if (!isRecognized) {
                    // Not recognized yet - face doesn't match
                    verifyBtn.disabled = true;
                    updateStatus("👤 Face detected but doesn't match registered profile.");
                }
                // If isRecognized is true, we do nothing - state is LOCKED

            } catch (error) {
                console.warn("Detection error:", error);
                // FIX: Do NOT reset isRecognized or disable button
            }
        }, 300);
    }

    // ============================================================
    // Verify button uses ONLY cached descriptor
    // NO duplicate face detection
    // PHP remains the final authority
    // ============================================================
    async function verifyFace() {
        if (isVerifying) return;
        if (!modelsLoaded) {
            updateStatus("Models still loading...");
            return;
        }
        if (!isRecognized) {
            updateStatus("Please wait for face recognition.");
            return;
        }
        if (!window.registeredFaceDescriptor || window.registeredFaceDescriptor.length === 0) {
            updateStatus("❌ No registered face template.");
            verifyBtn.disabled = true;
            return;
        }

        // ============================================================
        // FIX: Use the saved descriptor (no expiration, no fallback)
        // ============================================================
        if (!latestLiveDescriptor || latestLiveDescriptor.length === 0) {
            updateStatus("Face verification data unavailable. Please wait for face recognition.");
            verifyBtn.disabled = true;
            isVerifying = false;
            return;
        }

        // Use the saved descriptor
        const liveDescriptor = latestLiveDescriptor;

        isVerifying = true;
        verifyBtn.disabled = true;
        updateStatus("Verifying face...");

        try {
            // ============================================================
            // PHP REMAINS FINAL AUTHORITY - Send to server
            // ============================================================
            const startTime = performance.now();
            
            const response = await fetch("face_verify.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    descriptor: liveDescriptor
                })
            });

            const endTime = performance.now();
            console.log(`⏱️ PHP verification took: ${(endTime - startTime).toFixed(0)}ms`);

            const result = await response.json();

            if (result.success) {
                updateStatus("Face verified! Redirecting...");
                setTimeout(() => {
                    window.location.href = result.redirect || "../renter/browse.php";
                }, 400);
            } else {
                updateStatus("Face does not match.");
                verifyBtn.disabled = false;
                isVerifying = false;
            }

        } catch (error) {
            console.error("Verification error:", error);
            updateStatus("Verification error.");
            verifyBtn.disabled = false;
            isVerifying = false;
        }
    }

    verifyBtn.addEventListener('click', verifyFace);

    // ============================================================
    // Start the application
    // ============================================================
    await startCamera();
    setTimeout(loadModels, 500);

})();