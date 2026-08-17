(async () => {

    const video = document.getElementById("video");
    const verifyBtn = document.getElementById("verifyBtn");
    const statusMessage = document.getElementById("statusMessage");
    const registeredFaceDescriptor = window.registeredFaceDescriptor || [];
    const registeredFaceImage = window.registeredFaceImage || "";

    let faceDetected = false;
    let isVerifying = false;
    let modelsLoaded = false;
    let detectionInterval = null;

    // ============================================================
    // STEP 1: CHECK REGISTERED TEMPLATE
    // ============================================================
    if (!registeredFaceDescriptor.length) {
        statusMessage.textContent = "No registered face template found.";
        verifyBtn.disabled = true;
    }

    // ============================================================
    // STEP 2: START CAMERA IMMEDIATELY
    // ============================================================
    startCamera();

    // ============================================================
    // STEP 3: LOAD MODELS IN PARALLEL (Background)
    // ============================================================
    async function loadModels() {
        try {
            statusMessage.textContent = "Loading face models...";

            if (window.faceapi && faceapi.tf && faceapi.tf.setBackend) {
                try {
                    await faceapi.tf.setBackend("cpu");
                    await faceapi.tf.ready();
                } catch (backendError) {
                    console.warn("Could not force CPU backend:", backendError);
                }
            }

            // ============================================================
            // Load all models IN PARALLEL for speed
            // ============================================================
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri("../face-api.js-models-master/tiny_face_detector"),
                faceapi.nets.faceLandmark68Net.loadFromUri("../face-api.js-models-master/face_landmark_68"),
                faceapi.nets.faceRecognitionNet.loadFromUri("../face-api.js-models-master/face_recognition")
            ]);

            modelsLoaded = true;
            statusMessage.textContent = "Models loaded. Face detection active.";
            startFaceDetection();

        } catch (error) {
            console.error(error);
            statusMessage.textContent = "Failed to load face models.";
            verifyBtn.disabled = true;
        }
    }

    // ============================================================
    // STEP 4: START CAMERA - LOWER RESOLUTION FOR SPEED
    // ============================================================
    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { 
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: "user"
                }
            });
            video.srcObject = stream;
            statusMessage.textContent = "Camera starting...";

        } catch (error) {
            console.error(error);
            statusMessage.textContent = "Unable to access camera. Please check permissions.";
            verifyBtn.disabled = true;
        }
    }

    // ============================================================
    // STEP 5: START FACE DETECTION ONLY AFTER MODELS LOADED
    // ============================================================
    function startFaceDetection() {
        if (detectionInterval) {
            clearInterval(detectionInterval);
        }

        detectionInterval = setInterval(async () => {
            // Only run detection if models are loaded and video is playing
            if (!modelsLoaded || video.paused || video.ended || !video.srcObject) {
                return;
            }

            try {
                // ============================================================
                // FIXED: Use detectSingleFace for better reliability
                // Lowered scoreThreshold for better detection
                // ============================================================
                const detection = await faceapi.detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 512,
                        scoreThreshold: 0.3  // Lowered from 0.4 for better detection
                    })
                );

                if (!detection) {
                    faceDetected = false;
                    verifyBtn.disabled = true;
                    statusMessage.textContent = "No face detected. Position your face in the camera.";
                    return;
                }

                const box = detection.box;
                const minWidth = 80;
                const minHeight = 80;

                if (box.width < minWidth || box.height < minHeight) {
                    faceDetected = false;
                    verifyBtn.disabled = true;
                    statusMessage.textContent = "Move closer to the camera. (Face too small)";
                    return;
                }

                // ============================================================
                // Check if face is too large (prevents cropping issues)
                // ============================================================
                const videoRect = video.getBoundingClientRect();
                if (box.width > videoRect.width * 0.9 || box.height > videoRect.height * 0.9) {
                    faceDetected = false;
                    verifyBtn.disabled = true;
                    statusMessage.textContent = "Move further from the camera. (Face too large)";
                    return;
                }

                faceDetected = true;
                verifyBtn.disabled = false;
                statusMessage.textContent = "✅ Face detected. Ready to verify.";

            } catch (error) {
                console.warn("Detection error:", error);
                // Don't disable button on temporary errors
            }
        }, 300); // Faster detection (300ms instead of 500ms)
    }

    // ============================================================
    // STEP 6: START LOADING MODELS IN BACKGROUND
    // ============================================================
    loadModels();

    // ============================================================
    // STEP 7: VIDEO PLAYING EVENT
    // ============================================================
    video.addEventListener("playing", () => {
        statusMessage.textContent = modelsLoaded ? "Face detection active." : "Loading models...";
        // Force an immediate detection check
        setTimeout(() => {
            if (modelsLoaded && detectionInterval) {
                // Trigger detection manually
            }
        }, 500);
    });

    // ============================================================
    // STEP 8: HANDLE VIDEO ERRORS
    // ============================================================
    video.addEventListener("error", (e) => {
        console.error("Video error:", e);
        statusMessage.textContent = "Camera error. Please refresh and try again.";
        verifyBtn.disabled = true;
    });

    // ============================================================
    // STEP 9: VERIFY BUTTON - FIXED
    // ============================================================
    verifyBtn.addEventListener("click", async function onClick() {
        // Prevent multiple clicks
        if (isVerifying) return;
        
        // Check if models are loaded
        if (!modelsLoaded) {
            statusMessage.textContent = "⏳ Models still loading. Please wait...";
            return;
        }

        // Check if face is detected
        if (!faceDetected) {
            statusMessage.textContent = "⚠️ No face detected. Please position your face in the camera.";
            return;
        }

        // Check if registered template exists
        if (!registeredFaceDescriptor.length) {
            statusMessage.textContent = "❌ No registered face template available.";
            verifyBtn.disabled = true;
            return;
        }

        isVerifying = true;
        verifyBtn.disabled = true;
        statusMessage.textContent = "🔍 Verifying face...";

        try {
            // ============================================================
            // Capture the face with descriptor
            // ============================================================
            const detection = await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 512,
                        scoreThreshold: 0.3
                    })
                )
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                statusMessage.textContent = "❌ No face detected. Please try again.";
                verifyBtn.disabled = false;
                isVerifying = false;
                return;
            }

            // Check face size again
            const box = detection.box;
            if (box.width < 80 || box.height < 80) {
                statusMessage.textContent = "❌ Face too small. Move closer to the camera.";
                verifyBtn.disabled = false;
                isVerifying = false;
                return;
            }

            // ============================================================
            // Send to server for verification
            // ============================================================
            const response = await fetch("face_verify.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    descriptor: Array.from(detection.descriptor)
                })
            });

            const result = await response.json();

            if (result.success) {
                statusMessage.textContent = "✅ Face verified successfully! Redirecting...";
                const redirectUrl = result.redirect || "../renter/browse.php";
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 1200);
            } else {
                statusMessage.textContent = "❌ " + (result.message || "Face does not match the registered profile.");
                verifyBtn.disabled = false;
                isVerifying = false;
            }

        } catch (error) {
            console.error("Verification error:", error);
            statusMessage.textContent = "❌ Verification error. Please try again.";
            verifyBtn.disabled = false;
            isVerifying = false;
        }
    });

    // ============================================================
    // STEP 10: CLEANUP ON PAGE UNLOAD
    // ============================================================
    window.addEventListener("beforeunload", () => {
        if (detectionInterval) {
            clearInterval(detectionInterval);
            detectionInterval = null;
        }
        // Stop camera tracks
        if (video.srcObject) {
            const tracks = video.srcObject.getTracks();
            tracks.forEach(track => track.stop());
        }
    });

})();