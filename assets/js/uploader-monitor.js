/**
 * FCM Reels — uploader-monitor.js
 * Monitors file inputs globally to warn users about video file sizes.
 */
(function () {
    'use strict';

    const SIZE_LIMIT_MB = 10;
    const SIZE_LIMIT_BYTES = SIZE_LIMIT_MB * 1024 * 1024;

    window.handleFileSelection = function(input) {
        if (!input.files || !input.files.length) return;

        const file = input.files[0];
        const fileName = file.name.toLowerCase();
        const videoExts = ['.mp4', '.mov', '.webm', '.avi', '.m4v', '.m3u8', '.mpd'];

        const isVideo = file.type.startsWith('video/') || videoExts.some(ext => fileName.endsWith(ext));
        
        console.log("FCM Reels: File selected ->", fileName, "| isVideo:", isVideo, "| Size:", file.size);

        if (isVideo) {
            if (file.size > SIZE_LIMIT_BYTES) {
                const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
                alert(`🚫 VIDEO TOO LARGE!\n\nDetected Size: ${sizeInMB}MB\nAllowed Limit: 10MB\n\nPlease keep videos under 10MB.`);

                input.value = "";
                if (input.dispatchEvent) {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else {
                generateAndUploadThumbnail(file);
            }
        }
    };

    function init() {
        window.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'file') {
                window.handleFileSelection(e.target);
            }
        }, true);

        const originalCreateElement = document.createElement;
        document.createElement = function(tagName, options) {
            const el = originalCreateElement.call(this, tagName, options);
            if (tagName.toLowerCase() === 'input') {
                el.addEventListener('change', function(e) {
                    if (el.type === 'file') {
                        console.log("FCM Reels: Caught detached file input!");
                        window.handleFileSelection(el);
                    }
                });
            }
            return el;
        };

        window.addEventListener('drop', function(e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                window.handleFileSelection({ files: e.dataTransfer.files, value: '' });
            }
        }, true);

        // 4. Monkey-patch FormData to catch hidden Vue/Axios uploads and Drag-and-Drop
        const originalAppend = FormData.prototype.append;
        FormData.prototype.append = function(name, value, filename) {
            if (value instanceof File) {
                const fname = (filename || value.name).toLowerCase();
                const videoExts = ['.mp4', '.mov', '.webm', '.avi', '.m4v', '.m3u8', '.mpd'];
                const isVideo = value.type.startsWith('video/') || videoExts.some(ext => fname.endsWith(ext));
                
                if (isVideo) {
                    console.log("FCM Reels: Video detected in FormData ->", fname, "| Size:", value.size);
                    if (value.size <= SIZE_LIMIT_BYTES) {
                        generateAndUploadThumbnail(value);
                    } else {
                        const sizeInMB = (value.size / (1024 * 1024)).toFixed(2);
                        alert(`🚫 VIDEO TOO LARGE!\n\nDetected Size: ${sizeInMB}MB\nAllowed Limit: 10MB\n\nPlease keep videos under 10MB.`);
                    }
                }
            }
            if (filename) {
                return originalAppend.call(this, name, value, filename);
            } else {
                return originalAppend.call(this, name, value);
            }
        };

        // 5. Monkey-patch showOpenFilePicker (Modern Web API)
        if (window.showOpenFilePicker) {
            const originalPicker = window.showOpenFilePicker;
            window.showOpenFilePicker = async function() {
                const handles = await originalPicker.apply(this, arguments);
                if (handles && handles.length > 0) {
                    try {
                        const file = await handles[0].getFile();
                        window.handleFileSelection({ files: [file], value: '' });
                    } catch(e) {}
                }
                return handles;
            };
        }

        console.log('FCM Reels: Ultimate Uploader Monitor active with modern API patches.');
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
