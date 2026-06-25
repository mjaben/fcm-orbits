/**
 * FCM Reels — uploader-monitor.js
 * Monitors file inputs globally to warn users about video file sizes.
 */
(function () {
    'use strict';

    const SIZE_LIMIT_MB = 10;
    const SIZE_LIMIT_BYTES = SIZE_LIMIT_MB * 1024 * 1024;

    function init() {
        // 1. Watch for changes on the whole body (delegation)
        document.body.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'file') {
                handleFileSelection(e.target);
            }
        }, true);

        // 2. Use MutationObserver to find file inputs as they are added (FCM is a SPA)
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType === 1) { // Element
                        const inputs = node.querySelectorAll ? node.querySelectorAll('input[type="file"]') : [];
                        inputs.forEach(input => {
                            // Ensure we haven't already attached to this
                            if (!input.dataset.fcmMonitored) {
                                input.dataset.fcmMonitored = "true";
                                input.addEventListener('change', () => handleFileSelection(input));
                            }
                        });
                    }
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
        console.log('FCM Reels: Aggressive Uploader Monitor active.');
    }

    function handleFileSelection(input) {
        if (!input.files || !input.files.length) return;

        const file = input.files[0];
        const fileName = file.name.toLowerCase();
        const videoExts = ['.mp4', '.mov', '.webm', '.avi', '.m4v', '.m3u8', '.mpd'];

        const isVideo = file.type.startsWith('video/') || videoExts.some(ext => fileName.endsWith(ext));

        if (isVideo && file.size > SIZE_LIMIT_BYTES) {
            const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
            alert(`🚫 VIDEO TOO LARGE!\n\nDetected Size: ${sizeInMB}MB\nAllowed Limit: 10MB\n\nPlease keep videos under 10MB.`);

            // Hard Clear
            input.value = "";

            // Force FCM/Vue to see the clear
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        } else if (isVideo) {
            generateAndUploadThumbnail(file);
        }
    }

    async function generateAndUploadThumbnail(file) {
        try {
            const localVideoUrl = URL.createObjectURL(file);
            const base64Image = await generateVideoThumbnail(localVideoUrl, 1);
            URL.revokeObjectURL(localVideoUrl);

            if (!window.FCMUploader || !window.FCMUploader.apiBase) {
                console.warn("FCMUploader config missing. Cannot upload thumbnail.");
                return;
            }

            const response = await fetch(`${window.FCMUploader.apiBase}/upload-thumbnail`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.FCMUploader.nonce
                },
                body: JSON.stringify({
                    filename: file.name,
                    image: base64Image
                })
            });
            
            if (!response.ok) {
                console.error("Failed to upload generated thumbnail.");
            } else {
                console.log("FCM Reels: Thumbnail generated and queued for upload successfully.");
            }
        } catch (err) {
            console.error("FCM Reels: Error generating thumbnail", err);
        }
    }

    function generateVideoThumbnail(videoUrl, targetTime = 1) {
        return new Promise((resolve, reject) => {
            const video = document.createElement('video');
            video.crossOrigin = 'anonymous'; 
            video.src = videoUrl;
            video.muted = true;
            video.playsInline = true;

            video.addEventListener('loadedmetadata', () => {
                if (targetTime > video.duration) {
                    targetTime = video.duration / 2;
                }
                video.currentTime = targetTime;
            });

            video.addEventListener('seeked', () => {
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                const dataUrl = canvas.toDataURL('image/jpeg', 0.8);
                resolve(dataUrl);
                
                video.remove();
                canvas.remove();
            });

            video.addEventListener('error', (e) => reject(e));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
