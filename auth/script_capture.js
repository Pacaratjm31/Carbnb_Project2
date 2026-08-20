(async () => {

    const video = document.getElementById("video");
    const captureBtn = document.getElementById("captureBtn");  // FIXED: Changed from verifyBtn
    const statusMessage = document.getElementById("statusMessage");
    const faceImageInput = document.getElementById("faceImage");
    const faceEncodingInput = document.getElementById("faceEncoding");
    const faceForm = document.getElementById("faceForm");
    const loadingOverlay = document.getElementById("loadingOverlay");
    const faceIndicator = document.getElementById("faceIndicator");

    let faceDetected = false;
    let isCapturing = false;
    let modelsLoaded = false;
    let detectionInterval = null;
    let currentDetection = null;
    let modelLoadAttempts = 0;
    let videoReady = false;
    const MAX_MODEL_ATTEMPTS = 3;
    const MIN_FACE_SIZE = 40; // KEPT: Registration threshold remains 40

    function updateStatus(message, type = 'info') {
        statusMessage.textContent = message;
        statusMessage.className = 'status-message ' + type;
        console.log("[Face Capture]", message);
    }

    async function startCamera() {
        try {
            updateStatus("Starting camera...", 'info');
            
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
            
            updateStatus("Camera ready. Loading models...", 'info');
            
        } catch (error) {
            console.error("Camera error:", error);
            updateStatus("Camera error: " + error.message, 'error');
            captureBtn.disabled = true;
            loadingOverlay.classList.add('hidden');
        }
    }

    async function loadModels() {
        try {
            updateStatus("Loading face models...", 'info');
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
            loadingOverlay.classList.add('hidden');
            console.log("Models loaded successfully!");
            updateStatus("Face detection active. Show your face.", 'success');
            
            setTimeout(startFaceDetection, 500);

        } catch (error) {
            console.error("Model loading error:", error);
            modelLoadAttempts++;
            
            if (modelLoadAttempts < MAX_MODEL_ATTEMPTS) {
                updateStatus("Retrying model load (" + (modelLoadAttempts + 1) + "/" + MAX_MODEL_ATTEMPTS + ")...", 'warning');
                setTimeout(loadModels, 2000);
            } else {
                updateStatus("Failed to load face models. Check console.", 'error');
                loadingOverlay.classList.add('hidden');
                captureBtn.disabled = true;
            }
        }
    }

    function startFaceDetection() {
        if (detectionInterval) {
            clearInterval(detectionInterval);
        }

        if (!videoReady) {
            updateStatus("Waiting for camera...", 'info');
            setTimeout(startFaceDetection, 500);
            return;
        }

        updateStatus("Looking for face...", 'info');
        console.log("Face detection started");

        let noFaceCount = 0;

        detectionInterval = setInterval(async () => {
            if (!modelsLoaded || !videoReady) {
                return;
            }

            try {
                if (video.readyState < 2) {
                    return;
                }

                // ============================================================
                // MOBILE PERFORMANCE FIX: this loop just checks whether a
                // face is present/big enough for the live preview - it does
                // NOT compute the descriptor, so it doesn't need max input
                // resolution. Dropped from 512 to 320 to reduce per-tick
                // cost on mobile CPUs/GPUs. The actual capture in
                // captureFace() below still uses 512 for descriptor quality.
                // KEPT: Registration threshold remains 0.3
                // ============================================================
                const detection = await faceapi.detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 320,
                        scoreThreshold: 0.3
                    })
                );

                if (!detection) {
                    faceDetected = false;
                    captureBtn.disabled = true;
                    faceIndicator.classList.remove('show');
                    currentDetection = null;
                    noFaceCount++;
                    
                    if (noFaceCount === 10) {
                        updateStatus("No face detected. Show your face to the camera.", 'info');
                    }
                    return;
                }

                noFaceCount = 0;
                const box = detection.box;
                console.log("Face detected:", box.width, "x", box.height);
                
                if (box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE) {
                    faceDetected = false;
                    captureBtn.disabled = true;
                    faceIndicator.classList.remove('show');
                    currentDetection = null;
                    updateStatus("Move closer to the camera.", 'warning');
                    return;
                }

                faceDetected = true;
                faceIndicator.classList.add('show');
                currentDetection = detection;
                captureBtn.disabled = false;
                updateStatus("Face detected! Ready to capture.", 'success');

            } catch (error) {
                console.warn("Detection error:", error);
            }
        }, 300);
    }

    async function captureFace() {
        if (isCapturing) {
            updateStatus("Capture in progress...", 'info');
            return;
        }
        if (!modelsLoaded) {
            updateStatus("Models still loading...", 'warning');
            return;
        }
        if (!faceDetected || !currentDetection) {
            updateStatus("No face detected.", 'warning');
            return;
        }

        isCapturing = true;
        captureBtn.disabled = true;
        updateStatus("Capturing face...", 'info');

        try {
            // KEPT: Registration threshold remains 0.3
            const fullDetection = await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 512,
                        scoreThreshold: 0.3
                    })
                )
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!fullDetection) {
                updateStatus("Face capture failed. Try again.", 'error');
                captureBtn.disabled = false;
                isCapturing = false;
                return;
            }

            const descriptor = Array.from(fullDetection.descriptor);
            
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = canvas.toDataURL('image/png');

            faceImageInput.value = imageData;
            faceEncodingInput.value = JSON.stringify(descriptor);

            updateStatus("Face captured! Saving...", 'success');

            setTimeout(() => {
                faceForm.submit();
            }, 800);

        } catch (error) {
            console.error("Capture error:", error);
            updateStatus("Capture error. Try again.", 'error');
            captureBtn.disabled = false;
            isCapturing = false;
        }
    }

    captureBtn.addEventListener('click', captureFace);

    await startCamera();
    setTimeout(loadModels, 500);

})();