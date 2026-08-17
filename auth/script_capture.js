(async () => {

    const video = document.getElementById("video");
    const captureBtn = document.getElementById("captureBtn");
    const statusMessage = document.getElementById("statusMessage");
    const faceImageInput = document.getElementById("faceImage");
    const faceEncodingInput = document.getElementById("faceEncoding");
    const faceForm = document.getElementById("faceForm");

    let isCapturing = false;
    let modelsLoaded = false;
    let detectionInterval = null;

    // ============================================================
    // STEP 1: START CAMERA IMMEDIATELY
    // ============================================================
    startCamera();

    // ============================================================
    // STEP 2: LOAD MODELS IN PARALLEL (Background)
    // ============================================================
    async function loadModels() {
        try {
            statusMessage.textContent = "Loading face models...";

            // Force CPU backend for WebView compatibility
            if (window.faceapi && faceapi.tf && faceapi.tf.setBackend) {
                try {
                    await faceapi.tf.setBackend("cpu");
                    await faceapi.tf.ready();
                } catch (backendError) {
                    console.warn("Could not force CPU backend:", backendError);
                }
            }

            // ============================================================
            // FIXED: Load all models IN PARALLEL (not sequentially)
            // ============================================================
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(
                    "../face-api.js-models-master/tiny_face_detector"
                ),
                faceapi.nets.faceLandmark68Net.loadFromUri(
                    "../face-api.js-models-master/face_landmark_68"
                ),
                faceapi.nets.faceRecognitionNet.loadFromUri(
                    "../face-api.js-models-master/face_recognition"
                )
            ]);

            modelsLoaded = true;
            statusMessage.textContent = "Models loaded. Face detection active.";
            startFaceDetection();

        } catch (error) {
            console.error(error);
            statusMessage.textContent = "Failed to load face models.";
        }
    }

    // ============================================================
    // STEP 3: START CAMERA - LOWER RESOLUTION FOR SPEED
    // ============================================================
    async function startCamera() {
        try {
            // FIXED: Use lower resolution for faster startup
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: "user"
                }
            });

            video.srcObject = stream;
            
            // Show camera is starting
            statusMessage.textContent = "Camera starting...";

        } catch (error) {
            console.error(error);
            statusMessage.textContent = "Unable to access camera.";
        }
    }

    // ============================================================
    // STEP 4: START FACE DETECTION ONLY AFTER MODELS LOADED
    // ============================================================
    function startFaceDetection() {
        if (detectionInterval) {
            clearInterval(detectionInterval);
        }

        detectionInterval = setInterval(async () => {
            // Only run detection if models are loaded and video is playing
            if (!modelsLoaded || video.paused || video.ended) {
                return;
            }

            try {
                const detection = await faceapi
                    .detectSingleFace(
                        video,
                        new faceapi.TinyFaceDetectorOptions({
                            inputSize: 416,
                            scoreThreshold: 0.4
                        })
                    )
                    .withFaceLandmarks();

                if (!detection) {
                    captureBtn.disabled = true;
                    statusMessage.textContent = "No face detected.";
                    return;
                }

                const box = detection.detection.box;
                const faceWidth = box.width;
                const faceHeight = box.height;

                if (faceWidth < 80 || faceHeight < 80) {
                    captureBtn.disabled = true;
                    statusMessage.textContent = "Move closer to the camera.";
                    return;
                }

                const landmarks = detection.landmarks;
                const leftEye = landmarks.getLeftEye();
                const rightEye = landmarks.getRightEye();

                if (leftEye.length === 0 || rightEye.length === 0) {
                    captureBtn.disabled = true;
                    statusMessage.textContent = "Remove cap or glasses.";
                    return;
                }

                captureBtn.disabled = false;
                statusMessage.textContent = "Face detected. Ready to capture.";

            } catch (error) {
                // Silently handle detection errors
                console.warn("Detection error:", error);
            }
        }, 500);
    }

    // ============================================================
    // STEP 5: START LOADING MODELS IN BACKGROUND
    // ============================================================
    // Don't await - let it run in background while camera shows
    loadModels();

    // ============================================================
    // STEP 6: VIDEO PLAYING EVENT - Reset detection if needed
    // ============================================================
    video.addEventListener("playing", () => {
        statusMessage.textContent = modelsLoaded ? "Face detection active." : "Loading models...";
    });

    // ============================================================
    // STEP 7: CAPTURE BUTTON
    // ============================================================
    captureBtn.addEventListener("click", async () => {
        if (isCapturing) return;
        if (!modelsLoaded) {
            statusMessage.textContent = "Models still loading. Please wait.";
            return;
        }

        isCapturing = true;
        captureBtn.disabled = true;
        statusMessage.textContent = "Capturing face...";

        try {
            const detection = await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 416,
                        scoreThreshold: 0.4
                    })
                )
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                statusMessage.textContent = "Face capture failed.";
                captureBtn.disabled = false;
                isCapturing = false;
                return;
            }

            const canvas = document.createElement("canvas");
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext("2d");
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = canvas.toDataURL("image/png");
            const descriptor = Array.from(detection.descriptor);

            faceImageInput.value = imageData;
            faceEncodingInput.value = JSON.stringify(descriptor);

            statusMessage.textContent = "Face captured successfully. Saving...";

            setTimeout(() => {
                faceForm.submit();
            }, 1000);

        } catch (error) {
            console.error(error);
            statusMessage.textContent = "Capture error.";
            captureBtn.disabled = false;
            isCapturing = false;
        }
    });

})();