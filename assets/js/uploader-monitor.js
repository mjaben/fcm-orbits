/**
 * FCM Reels — uploader-monitor.js
 * Monitors file inputs globally to warn users about video file sizes.
 */
(function () {
    'use strict';

    const SIZE_LIMIT_MB = 10;
    const SIZE_LIMIT_BYTES = SIZE_LIMIT_MB * 1024 * 1024;

    const processedFiles = new Set();

    window.handleFileSelection = function(input) {
        if (!input.files || !input.files.length) return;

        const file = input.files[0];
        const fileName = file.name ? file.name.toLowerCase() : 'video.mp4';
        const videoExts = ['.mp4', '.mov', '.webm', '.avi', '.m4v', '.m3u8', '.mpd'];

        const isVideo = (file.type && file.type.startsWith('video/')) || videoExts.some(ext => fileName.endsWith(ext));
        
        if (isVideo) {
            // Deduplicate processing
            const fileKey = fileName + '_' + file.size;
            if (processedFiles.has(fileKey)) {
                return; // Already generating/generated thumbnail for this file
            }
            
            console.log("FCM Reels: File selected ->", fileName, "| isVideo:", isVideo, "| Size:", file.size);

            if (file.size > SIZE_LIMIT_BYTES) {
                const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
                alert(`🚫 VIDEO TOO LARGE!\n\nDetected Size: ${sizeInMB}MB\nAllowed Limit: 10MB\n\nPlease keep videos under 10MB.`);

                if (input.value !== undefined) {
                    input.value = "";
                }
                if (input.dispatchEvent) {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else {
                processedFiles.add(fileKey);
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

        // 4. Monkey-patch URL.createObjectURL to catch local video previews
        const origCreateObjectURL = URL.createObjectURL;
        URL.createObjectURL = function(obj) {
            if (obj && typeof obj === 'object' && obj.size && obj.type) {
                let fname = obj.name || 'video.mp4';
                if (obj.type.startsWith('video/') || fname.match(/\.(mp4|mov|avi|wmv|flv|webm|mkv|m4v)$/i)) {
                    console.log("FCM Reels: Caught video via URL.createObjectURL ->", fname);
                    if (window.handleFileSelection) {
                        window.handleFileSelection({ files: [obj], value: '' });
                    }
                }
            }
            return origCreateObjectURL.apply(this, arguments);
        };

        // 5. Monkey-patch FileReader to catch local video reads
        const origReadAsDataURL = FileReader.prototype.readAsDataURL;
        FileReader.prototype.readAsDataURL = function(blob) {
            if (blob && typeof blob === 'object' && blob.size && blob.type) {
                let fname = blob.name || 'video.mp4';
                if (blob.type.startsWith('video/') || fname.match(/\.(mp4|mov|avi|wmv|flv|webm|mkv|m4v)$/i)) {
                    console.log("FCM Reels: Caught video via FileReader.readAsDataURL ->", fname);
                    if (window.handleFileSelection) {
                        window.handleFileSelection({ files: [blob], value: '' });
                    }
                }
            }
            return origReadAsDataURL.apply(this, arguments);
        };

        const origReadAsArrayBuffer = FileReader.prototype.readAsArrayBuffer;
        FileReader.prototype.readAsArrayBuffer = function(blob) {
            if (blob && typeof blob === 'object' && blob.size && blob.type) {
                let fname = blob.name || 'video.mp4';
                if (blob.type.startsWith('video/') || fname.match(/\.(mp4|mov|avi|wmv|flv|webm|mkv|m4v)$/i)) {
                    console.log("FCM Reels: Caught video via FileReader.readAsArrayBuffer ->", fname);
                    if (window.handleFileSelection) {
                        window.handleFileSelection({ files: [blob], value: '' });
                    }
                }
            }
            return origReadAsArrayBuffer.apply(this, arguments);
        };

        // 6. Monkey-patch FormData to catch hidden Vue/Axios uploads
        const originalAppend = FormData.prototype.append;
        FormData.prototype.append = function(name, value, filename) {
            console.log("FCM Reels: FormData.append called for key ->", name);
            try {
                let isVideo = false;
                let actualFilename = filename;

                // Duck-typing check to handle Vue 3 Proxies and cross-iframe File objects
                // A File/Blob always has 'size' and 'type' properties
                const isFileLike = value && typeof value === 'object' && typeof value.size === 'number' && typeof value.type === 'string';

                if (isFileLike) {
                    actualFilename = actualFilename || value.name || 'video.mp4';
                    if (value.type.startsWith('video/') || (actualFilename && actualFilename.match(/\.(mp4|mov|avi|wmv|flv|webm|mkv|m4v)$/i))) {
                        isVideo = true;
                    }
                }

                if (isVideo) {
                    console.log("FCM Reels: Video detected in FormData ->", actualFilename, "| Size:", value.size);
                    if (value.size <= SIZE_LIMIT_BYTES) {
                        generateAndUploadThumbnail(value);
                    } else {
                        const sizeInMB = (value.size / (1024 * 1024)).toFixed(2);
                        alert(`🚫 VIDEO TOO LARGE!\n\nDetected Size: ${sizeInMB}MB\nAllowed Limit: 10MB\n\nPlease keep videos under 10MB.`);
                    }
                }
            } catch(e) {
                console.error("FCM Reels: FormData intercept error", e);
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
            // Do NOT set crossOrigin='anonymous' for blob URLs, it causes security errors in some browsers.
            if (!videoUrl.startsWith('blob:')) {
                video.crossOrigin = 'anonymous'; 
            }
            video.src = videoUrl;
            video.muted = true;
            video.playsInline = true;
            video.autoplay = true; // helps some browsers trigger loadedmetadata

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
                
                // Draw Play Button Overlay
                const centerX = canvas.width / 2;
                const centerY = canvas.height / 2;
                const radius = Math.max(Math.min(canvas.width, canvas.height) * 0.08, 30); 
                
                ctx.beginPath();
                ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
                ctx.fill();
                
                const tSize = radius * 0.5;
                const tOffsetX = centerX - (tSize * 0.3);
                ctx.beginPath();
                ctx.moveTo(tOffsetX, centerY - (tSize * 0.8));
                ctx.lineTo(tOffsetX + (tSize * 1.5), centerY);
                ctx.lineTo(tOffsetX, centerY + (tSize * 0.8));
                ctx.closePath();
                ctx.fillStyle = 'white';
                ctx.fill();
                
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

    // --- IFRAME INJECTION FOR GUTENBERG BLOCK EDITOR ---
    function injectIntoIframe(iframe) {
        try {
            const win = iframe.contentWindow;
            if (!win || win._fcmMonitorInjected) return;
            
            win._fcmMonitorInjected = true;
            console.log("FCM Reels: Injecting monitor into iframe", iframe.id || iframe.name);
            
            // Patch FormData in the iframe
            const iframeAppend = win.FormData.prototype.append;
            win.FormData.prototype.append = function(name, value, filename) {
                console.log("FCM Reels (iframe): FormData.append called for key ->", name);
                try {
                    let isVideo = false;
                    let actualFilename = filename;
                    const isFileLike = value && typeof value === 'object' && typeof value.size === 'number' && typeof value.type === 'string';

                    if (isFileLike) {
                        actualFilename = actualFilename || value.name || 'video.mp4';
                        if (value.type.startsWith('video/') || (actualFilename && actualFilename.match(/\.(mp4|mov|avi|wmv|flv|webm|mkv|m4v)$/i))) {
                            isVideo = true;
                        }
                    }

                    if (isVideo) {
                        console.log("FCM Reels (iframe): Video detected ->", actualFilename);
                        // Forward to main window
                        if (window.handleFileSelection) {
                            window.handleFileSelection({ files: [value], value: '' });
                        }
                    }
                } catch(e) {}

                if (filename) {
                    return iframeAppend.call(this, name, value, filename);
                } else {
                    return iframeAppend.call(this, name, value);
                }
            };

            // Patch fetch in iframe to catch raw uploads
            const originalFetch = win.fetch;
            win.fetch = function() {
                // If it's the video-upload endpoint and the body is a FormData, our append patch catches it.
                // But just in case, we can also log here.
                return originalFetch.apply(this, arguments);
            };

        } catch (e) {
            // Cross-origin iframe, ignore
        }
    }

    // Monitor for iframes being added (e.g. Gutenberg editor-canvas)
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.tagName === 'IFRAME') {
                    node.addEventListener('load', () => injectIntoIframe(node));
                } else if (node.querySelectorAll) {
                    const iframes = node.querySelectorAll('iframe');
                    iframes.forEach(iframe => {
                        iframe.addEventListener('load', () => injectIntoIframe(iframe));
                    });
                }
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });

    // Also inject into any existing iframes
    document.querySelectorAll('iframe').forEach(iframe => {
        if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
            injectIntoIframe(iframe);
        } else {
            iframe.addEventListener('load', () => injectIntoIframe(iframe));
        }
    });

})();
