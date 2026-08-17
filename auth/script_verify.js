(async () => {

    const video = document.getElementById("video");
    const verifyBtn = document.getElementById("verifyBtn");
    const statusMessage = document.getElementById("statusMessage");

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

            if (faceapi.tf && faceapi.tf.setBackend) {
                try {
                    await faceapi.tf.setBackend('cpu');
                    await faceapi.tf.ready();
                    console.log("TensorFlow backend set to CPU");
                } catch (e) {
                    console.warn("Could not set CPU backend:", e);
                }
            }

            const modelPath = '../face-api.js-models-master';
            
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(modelPath + '/tiny_face_detector'),
                faceapi.nets.faceLandmark68Net.loadFromUri(modelPath + '/face_landmark_68'),
                faceapi.nets.faceRecognitionNet.loadFromUri(modelPath + '/face_recognition')
            ]);

            modelsLoaded = true;
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
                verifyBtn.disabled = true;
            }
        }
    }

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

        detectionInterval = setInterval(async () => {
            if (!modelsLoaded || !videoReady) {
                return;
            }

            try {
                if (video.readyState < 2) {
                    return;
                }

                const detection = await faceapi.detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions({
                        inputSize: 512,
                        scoreThreshold: 0.5
                    })
                );

                if (!detection) {
                    faceDetected = false;
                    verifyBtn.disabled = true;
                    currentDetection = null;
                    noFaceCount++;
                    
                    if (noFaceCount === 10) {
                        updateStatus("No face detected. Position your face in the camera.");
                    }
                    return;
                }

                noFaceCount = 0;
                const box = detection.box;
                console.log("Face detected:", box.width, "x", box.height);
                
                if (box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE) {
                    faceDetected = false;
                    verifyBtn.disabled = true;
                    currentDetection = null;
                    updateStatus("Move closer to the camera.");
                    return;
                }

                faceDetected = true;
                currentDetection = detection;
                verifyBtn.disabled = false;
                updateStatus("Face detected! Ready to verify.");

            } catch (error) {
                console.warn("Detection error:", error);
            }
        }, 500);
    }

    async function verifyFace() {
        if (isVerifying) return;
        if (!modelsLoaded) {
            updateStatus("Models still loading...");
            return;
        }
        if (!faceDetected || !currentDetection) {
            updateStatus("No face detected.");
            return;
        }
        if (!window.registeredFaceDescriptor || window.registeredFaceDescriptor.length === 0) {
            updateStatus("❌ No registered face template.");
            verifyBtn.disabled = true;
            return;
        }

        isVerifying = true;
        verifyBtn.disabled = true;
        updateStatus("Verifying face...");

        try {
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

            if (!fullDetection) {
                updateStatus("Face capture failed.");
                verifyBtn.disabled = false;
                isVerifying = false;
                return;
            }

            const liveDescriptor = Array.from(fullDetection.descriptor);

            const response = await fetch("face_verify.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    descriptor: liveDescriptor
                })
            });

            const result = await response.json();

            if (result.success) {
                updateStatus("Face verified! Redirecting...");
                setTimeout(() => {
                    window.location.href = result.redirect || "../renter/browse.php";
                }, 1200);
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

    await startCamera();
    setTimeout(loadModels, 500);

})();