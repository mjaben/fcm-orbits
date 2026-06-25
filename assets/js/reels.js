/**
 * FCM Orbits — reels.js
 * TikTok-style vertical snap-scroll video feed.
 */
(function () {
    'use strict';

    /* ── Config from wp_localize_script ── */
    const CFG = window.FCMReels || {};
    const API = CFG.apiBase || '/wp-json/fcm-reels/v1';
    const NONCE = CFG.nonce || '';
    const PER_PAGE = CFG.perPage || 10;
    const IS_LOGGED_IN = CFG.isLoggedIn || false;
    const USER_ID = CFG.userId || 0;
    const LOGIN_URL = CFG.loginUrl || '/wp-login.php';

    /* ── DOM References ── */
    const feed = document.getElementById('reels-feed');
    const slidesWrap = document.getElementById('reels-slides');
    const loader = document.getElementById('reels-loader');
    const emptyState = document.getElementById('reels-empty');
    const sentinel = document.getElementById('reels-sentinel');
    const muteBtn = document.getElementById('reels-mute-btn');
    const muteIconOn = muteBtn ? muteBtn.querySelector('.mute-icon--muted') : null;
    const muteIconOff = muteBtn ? muteBtn.querySelector('.mute-icon--unmuted') : null;
    const filterBar = document.getElementById('reels-filter-bar');
    const shareModal = document.getElementById('reels-share-modal');
    const shareOverlay = document.getElementById('reels-share-overlay');
    const shareClose = document.getElementById('reels-share-close');
    const shareInput = document.getElementById('reels-share-input');
    const copyBtn = document.getElementById('reels-copy-btn');
    const slideTpl = document.getElementById('reel-slide-tpl');

    /* ── State ── */
    let discoverySeed = Math.floor(Math.random() * 999999);
    let seenVideos = [];
    // Update key to v2 to force a global reset of seen history for all users
    const seenKey = 'fcm_reels_seen_v2_' + USER_ID;
    try {
        seenVideos = JSON.parse(localStorage.getItem(seenKey) || '[]');
    } catch (e) { }

    let nextCursor = '';
    let isLoading = false;
    let hasMore = true;
    let isMuted = true;
    let currentSpace = '';
    let activeSlide = null;
    let progressTimer = null;
    let viewTimer = null;
    let heartbeatTimer = null;
    const sessionId = 'orb_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
    let sessionViewedCount = 0;
    let sessionTotalWatchTime = 0;

    /* ─────────────────────────────────────────────
       INIT
    ───────────────────────────────────────────── */
    function init() {
        if (!slidesWrap || !slideTpl) return;

        loadSpaces();
        loadNextPage();
        setupMuteButton();
        setupShareModal();
        setupSentinelObserver();
        setupFilterBar();

        // Autoplay Watchdog: Check active video every 2 seconds
        setInterval(() => {
            if (activeSlide) {
                const v = activeSlide.querySelector('.reel-video');
                if (v && v.paused && !activeSlide.classList.contains('is-paused')) {
                    activeSlide.classList.add('is-loading');
                    v.play().catch(() => { });
                }
            }
        }, 2000);
    }

    /* ─────────────────────────────────────────────
       FETCH VIDEOS
    ───────────────────────────────────────────── */
    async function loadNextPage() {
        if (isLoading) return;

        if (!hasMore) {
            console.log('FCM Orbits: End reached. Shuffling and resetting...');
            nextCursor = '';
            hasMore = true;
            discoverySeed = Math.floor(Math.random() * 999999);
        }

        isLoading = true;
        const isFirstLoad = (slidesWrap.querySelectorAll('.reel-slide').length === 0);
        if (isFirstLoad) showLoader(true);

        let url = `${API}/feed?per_page=${PER_PAGE}`;
        if (nextCursor) {
            url += `&cursor=${encodeURIComponent(nextCursor)}`;
        } else {
            url += `&seed=${discoverySeed}`;
            const urlParams = new URLSearchParams(window.location.search);
            let shareId = urlParams.get('v');
            
            if (!shareId) {
                const pageSlug = CFG.pageSlug || 'orbits';
                const pathParts = window.location.pathname.replace(/^\/|\/$/g, '').split('/');
                const slugIndex = pathParts.indexOf(pageSlug);
                if (slugIndex > 0 && pathParts.length > slugIndex + 1) {
                    shareId = pathParts[slugIndex + 1];
                }
            }

            if (shareId) {
                url += `&share_id=${encodeURIComponent(shareId)}`;
            }
        }
        if (currentSpace) url += `&space=${currentSpace}`;

        // UX Fix: Send the last 50 seen videos to explicitly exclude them from this batch
        if (seenVideos.length > 0) {
            const recentSeen = seenVideos.slice(-50).join(',');
            url += `&seen=${encodeURIComponent(recentSeen)}`;
        }

        try {
            const resp = await fetch(url, {
                headers: { 'X-WP-Nonce': NONCE },
                credentials: 'same-origin',
            });
            if (!resp.ok) throw new Error('API error: ' + resp.status);

            const data = await resp.json();
            if (data.videos && data.videos.length > 0) {
                renderSlides(data.videos);
                nextCursor = data.next_cursor;
                hasMore = !!nextCursor || (data.videos.length >= PER_PAGE);
            } else if (!nextCursor) {
                showEmpty(true);
            }
            showLoader(false);
        } catch (err) {
            console.error('FCM Orbits fetch error:', err);
            showLoader(false);
        } finally {
            isLoading = false;
        }
    }

    /* ─────────────────────────────────────────────
       RENDER & CREATE SLIDES
    ───────────────────────────────────────────── */
    function renderSlides(videos) {
        const fragment = document.createDocumentFragment();
        const lastSlide = slidesWrap.querySelector('.reel-slide:last-child');
        
        if (lastSlide && videos.length > 0) {
            const lastId = parseInt(lastSlide.dataset.id);
            if (parseInt(videos[0].id) === lastId) {
                videos.shift();
            }
        }

        videos.forEach((video) => {
            const slide = createSlide(video);
            fragment.appendChild(slide);
        });

        slidesWrap.appendChild(fragment);

        // Garbage Collection
        const allSlides = slidesWrap.querySelectorAll('.reel-slide');
        if (allSlides.length > 40) {
            for (let i = 0; i < 10; i++) {
                const s = allSlides[i];
                const v = s.querySelector('.reel-video');
                if (v) { v.pause(); v.src = ''; v.load(); }
                s.remove();
            }
        }

        observeSlides();

        if (allSlides.length > 0 && !activeSlide) {
            activateSlide(slidesWrap.querySelector('.reel-slide'));
        }
    }

    function createSlide(video) {
        const node = slideTpl.content.cloneNode(true);
        const slide = node.querySelector('.reel-slide');

        slide.dataset.id = video.id;
        slide.dataset.postUrl = buildPostUrl(video);
        slide.dataset.liked = video.user_liked ? '1' : '0';
        slide.dataset.likesCount = video.likes_count;

        const videoEl = slide.querySelector('.reel-video');
        videoEl.muted = true;
        videoEl.defaultMuted = true;
        videoEl.setAttribute('muted', '');
        videoEl.setAttribute('playsinline', '');
        videoEl.setAttribute('autoplay', '');
        videoEl.preload = 'auto';
        videoEl.src = video.video_url;

        if (video.thumbnail_url) videoEl.poster = video.thumbnail_url;
        videoEl.load();

        slide.querySelector('.reel-tap-overlay').addEventListener('click', () => togglePlayPause(slide));

        const avatarLink = slide.querySelector('.reel-author__link');
        const avatar = slide.querySelector('.reel-author__avatar');
        const nameLink = slide.querySelector('.reel-author__name');
        const spaceEl = slide.querySelector('.reel-author__space');

        if (video.author.profile_url) {
            avatarLink.href = video.author.profile_url;
            nameLink.href = video.author.profile_url;
            
            // Engagement tracking
            avatarLink.addEventListener('click', () => markVideoAsSeen(video.id));
            nameLink.addEventListener('click', () => markVideoAsSeen(video.id));
        }
        avatar.src = video.author.avatar || '';
        nameLink.textContent = video.author.name || 'Member';

        if (video.space && video.space.title) {
            spaceEl.textContent = '# ' + video.space.title;
            spaceEl.style.display = '';
        } else {
            spaceEl.style.display = 'none';
        }

        const descEl = slide.querySelector('.reel-description');
        descEl.textContent = video.description || video.title || '';

        slide.querySelector('.reel-views-btn .reel-action-btn__count').textContent = formatCount(video.views_count || 0);
        slide.querySelector('.reel-like-btn .reel-action-btn__count').textContent = formatCount(video.likes_count || 0);
        
        const likeBtn = slide.querySelector('.reel-like-btn');
        if (video.user_liked) likeBtn.classList.add('is-liked');
        likeBtn.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            markVideoAsSeen(video.id); // Engagement tracking
            handleLike(slide, video.id); 
        });

        const commentBtn = slide.querySelector('.reel-comment-btn');
        commentBtn.href = slide.dataset.postUrl || '#';
        commentBtn.querySelector('.reel-action-btn__count').textContent = formatCount(video.comments_count);
        commentBtn.addEventListener('click', () => markVideoAsSeen(video.id)); // Engagement tracking

        slide.querySelector('.reel-share-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            markVideoAsSeen(video.id); // Engagement tracking
            
            const homeUrl = CFG.homeUrl || (window.location.origin + '/');
            const pageSlug = CFG.pageSlug || 'orbits';
            const username = video.author.username || 'member';
            const orbitUrl = `${homeUrl}${username}/${pageSlug}/${video.slug}/`;
            
            openShareModal(orbitUrl, video.title || '', video.thumbnail_url || '');
        });

        videoEl.addEventListener('timeupdate', () => updateProgress(slide, videoEl));
        videoEl.addEventListener('waiting', () => slide.classList.add('is-loading'));
        videoEl.addEventListener('playing', () => slide.classList.remove('is-loading'));
        videoEl.addEventListener('canplay', () => slide.classList.remove('is-loading'));
        videoEl.addEventListener('ended', () => {
            trackEvent(video.id, 'video_complete');
        });

        return slide;
    }

    /* ─────────────────────────────────────────────
       OBSERVER & ACTIVATION
    ───────────────────────────────────────────── */
    let slideObserver = null;
    function observeSlides() {
        if (!('IntersectionObserver' in window)) return;
        if (!slideObserver) {
            slideObserver = new IntersectionObserver((entries) => {
                let mostVisible = null;
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        if (!mostVisible || entry.intersectionRatio > mostVisible.intersectionRatio) {
                            mostVisible = entry;
                        }
                    }
                });
                if (mostVisible && mostVisible.intersectionRatio >= 0.4) activateSlide(mostVisible.target);
                entries.forEach(entry => {
                    if (!entry.isIntersecting || entry.intersectionRatio < 0.2) {
                        if (entry.target !== activeSlide) deactivateSlide(entry.target);
                    }
                });
            }, { threshold: [0, 0.2, 0.4, 0.6, 0.8, 1.0] });
        }
        slidesWrap.querySelectorAll('.reel-slide:not([data-observed])').forEach((s) => {
            s.dataset.observed = '1';
            slideObserver.observe(s);
        });
    }

    async function activateSlide(slide) {
        if (activeSlide && activeSlide !== slide) deactivateSlide(activeSlide);
        activeSlide = slide;
        const v = slide.querySelector('.reel-video');
        if (!v) return;

        v.muted = isMuted;
        if (!slide.classList.contains('is-paused')) {
            v.play().catch(() => {});
        }

        // Removed Impression-Based tracking. Seen is now Attention-Based.

        // 👁️ View Tracking
        if (viewTimer) clearTimeout(viewTimer);
        viewTimer = setTimeout(() => {
            trackEvent(parseInt(slide.dataset.id), 'video_view');
            sessionViewedCount++;
            updateSession();
        }, 2000); // 2 seconds to count as a "view"

        // 💓 Heartbeat Tracking (Watch Time)
        if (heartbeatTimer) clearInterval(heartbeatTimer);
        heartbeatTimer = setInterval(() => {
            if (!v.paused) {
                sessionTotalWatchTime += 2;
                trackEvent(parseInt(slide.dataset.id), 'heartbeat', 2);
                updateSession();
            }
        }, 2000);

        // Proactive Batching
        const allSlides = Array.from(slidesWrap.querySelectorAll('.reel-slide'));
        const currentIndex = allSlides.indexOf(slide);
        if (currentIndex >= allSlides.length - 2 && hasMore && !isLoading) {
            loadNextPage();
        }
    }

    function deactivateSlide(slide) {
        if (activeSlide === slide) {
            if (viewTimer) { clearTimeout(viewTimer); viewTimer = null; }
            if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
        }
        const v = slide.querySelector('.reel-video');
        if (v) { v.pause(); v.currentTime = 0; }
        slide.classList.remove('is-paused');
        const fill = slide.querySelector('.reel-progress-fill');
        if (fill) fill.style.width = '0%';
    }

    /* ─────────────────────────────────────────────
       CONTROLS & UI
    ───────────────────────────────────────────── */
    function togglePlayPause(slide) {
        const v = slide.querySelector('.reel-video');
        if (!v) return;
        if (v.paused) { v.play(); slide.classList.remove('is-paused'); }
        else { v.pause(); slide.classList.add('is-paused'); }
    }

    function updateProgress(slide, v) {
        if (!v.duration) return;
        const pct = (v.currentTime / v.duration) * 100;
        const fill = slide.querySelector('.reel-progress-fill');
        if (fill) fill.style.width = pct + '%';

        // MVP Attention-Based Tracking (Hybrid Model)
        // Mark as 'seen' only if watched for >= 8 seconds OR >= 35% of duration
        if (v.currentTime >= 8 || pct >= 35) {
            markVideoAsSeen(parseInt(slide.dataset.id));
        }
    }

    function setupMuteButton() {
        if (!muteBtn) return;
        muteBtn.addEventListener('click', () => {
            isMuted = !isMuted;
            syncMuteUI();
            if (activeSlide) {
                const v = activeSlide.querySelector('.reel-video');
                if (v) v.muted = isMuted;
            }
        });
    }

    function syncMuteUI() {
        if (!muteIconOn || !muteIconOff) return;
        muteIconOn.style.display = isMuted ? 'flex' : 'none';
        muteIconOff.style.display = isMuted ? 'none' : 'flex';
    }

    async function handleLike(slide, videoId) {
        if (!IS_LOGGED_IN) {
            if (confirm(CFG.labels.login_like || 'Log in to like this video.')) window.location.href = LOGIN_URL;
            return;
        }
        const likeBtn = slide.querySelector('.reel-like-btn');
        const countEl = likeBtn.querySelector('.reel-action-btn__count');
        const isLiked = likeBtn.classList.contains('is-liked');
        const prevCount = parseInt(slide.dataset.likesCount || '0', 10);
        const newCount = isLiked ? Math.max(0, prevCount - 1) : prevCount + 1;

        likeBtn.classList.toggle('is-liked');
        countEl.textContent = formatCount(newCount);
        slide.dataset.likesCount = newCount;

        try {
            const resp = await fetch(API + '/like/' + videoId, {
                method: 'POST',
                headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
            });
            if (!resp.ok) throw new Error();
            const data = await resp.json();
            countEl.textContent = formatCount(data.likes_count);
            slide.dataset.likesCount = data.likes_count;

            if (data.liked) {
                trackEvent(videoId, 'video_like');
            }
        } catch {
            likeBtn.classList.toggle('is-liked');
            countEl.textContent = formatCount(prevCount);
            slide.dataset.likesCount = prevCount;
        }
    }

    function setupShareModal() {
        if (!shareModal) return;
        if (shareOverlay) shareOverlay.addEventListener('click', closeShareModal);
        if (shareClose) shareClose.addEventListener('click', closeShareModal);
        if (copyBtn && shareInput) {
            copyBtn.addEventListener('click', () => {
                shareInput.select();
                shareInput.setSelectionRange(0, 99999);
                
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(shareInput.value);
                    } else {
                        document.execCommand('copy');
                    }
                } catch (err) {
                    console.error('Copy failed', err);
                }
                
                copyBtn.textContent = '✓ Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
            });
        }
    }

    function openShareModal(url, title, thumbnail) {
        if (!shareModal) return;
        if (shareInput) shareInput.value = url;
        const previewWrap = document.getElementById('reels-share-preview');
        if (previewWrap) {
            if (thumbnail) {
                previewWrap.innerHTML = `<img src="${thumbnail}" alt="Preview" class="reels-share-preview-img">`;
                previewWrap.style.display = 'block';
            } else { previewWrap.style.display = 'none'; }
        }
        const wa = document.getElementById('share-whatsapp');
        const tw = document.getElementById('share-twitter');
        if (wa) wa.href = 'https://wa.me/?text=' + encodeURIComponent(title + ' ' + url);
        if (tw) tw.href = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(title) + '&url=' + encodeURIComponent(url);
        shareModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeShareModal() {
        if (!shareModal) return;
        shareModal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function setupFilterBar() {
        if (!filterBar) return;
        filterBar.addEventListener('click', (e) => {
            const chip = e.target.closest('.reels-filter-chip');
            if (!chip) return;
            filterBar.querySelectorAll('.reels-filter-chip').forEach((c) => c.classList.remove('active'));
            chip.classList.add('active');
            currentSpace = chip.dataset.space || '';
            resetFeed();
        });
    }

    async function loadSpaces() {
        if (!filterBar) return;
        try {
            const resp = await fetch(API + '/spaces', { headers: { 'X-WP-Nonce': NONCE } });
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data.spaces) return;
            data.spaces.forEach((space) => {
                const chip = document.createElement('button');
                chip.className = 'reels-filter-chip';
                chip.dataset.space = space.slug;
                chip.textContent = space.title;
                filterBar.appendChild(chip);
            });
        } catch { }
    }

    function setupSentinelObserver() {
        if (!sentinel) return;
        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) loadNextPage();
        }, { rootMargin: '800px' });
        observer.observe(sentinel);
    }

    function resetFeed() {
        hasMore = true;
        nextCursor = '';
        slidesWrap.querySelectorAll('.reel-slide').forEach((s) => {
            const v = s.querySelector('.reel-video');
            if (v) { v.pause(); v.src = ''; }
            s.remove();
        });
        activeSlide = null;
        loadNextPage();
    }

    /* ─────────────────────────────────────────────
       UTILITIES
    ───────────────────────────────────────────── */
    function showLoader(show) { if (loader) loader.style.display = show ? 'flex' : 'none'; }
    function showEmpty(show) { if (emptyState) emptyState.style.display = show ? 'flex' : 'none'; }
    function buildPostUrl(video) {
        const base = CFG.portalUrl || (window.location.origin + '/');
        if (video.space && video.space.slug) return base + 'space/' + video.space.slug + '/post/' + video.slug;
        return base + 'post/' + video.slug;
    }
    function formatCount(num) {
        num = parseInt(num, 10) || 0;
        if (num >= 1000000) return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return String(num);
    }
    function markVideoAsSeen(id) {
        if (!id || seenVideos.includes(id)) return;
        seenVideos.push(id);
        if (seenVideos.length > 200) seenVideos.shift();
        localStorage.setItem(seenKey, JSON.stringify(seenVideos));
    }
    async function trackEvent(videoId, eventType, watchSeconds = 0) {
        if (!videoId || !eventType) {
            console.warn('[Orbit] Missing videoId or eventType for tracking');
            return;
        }

        const device = window.innerWidth < 768 ? 'mobile' : 'desktop';
        console.log(`[Orbit] Dispatching pulse: ${eventType} for video ${videoId}`);

        try {
            fetch(`${API}/pulse`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    video_id: videoId,
                    event_type: eventType,
                    watch_seconds: watchSeconds,
                    session_id: sessionId,
                    device: device
                })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    console.log(`[Orbit] Pulse recorded: ${eventType}`);
                } else {
                    console.error(`[Orbit] Pulse failed:`, data);
                }
            }).catch(err => {
                console.error('[Orbit] Pulse transport error:', err);
            });
        } catch (e) {
            console.error('[Orbit] Pulse execution error:', e);
        }
    }

    async function updateSession() {
        if (!sessionId) return;
        try {
            fetch(`${API}/stream-session`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    session_id: sessionId,
                    viewed_count: sessionViewedCount,
                    total_watch: sessionTotalWatchTime
                })
            });
        } catch (e) { }
    }

    async function logView(id) {
        // Deprecated: used by legacy systems, redirected to trackEvent internally in API if needed
        trackEvent(id, 'video_view');
    }

    // Interaction Boot
    const boot = () => {
        isMuted = false; syncMuteUI();
        if (activeSlide) {
            const v = activeSlide.querySelector('.reel-video');
            if (v) { v.muted = false; v.play().catch(() => {}); }
        }
        document.removeEventListener('click', boot);
        document.removeEventListener('touchstart', boot);
    };
    document.addEventListener('click', boot);
    document.addEventListener('touchstart', boot);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }

})();
