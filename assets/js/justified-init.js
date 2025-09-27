(function() {
    'use strict';

    function calculateOptimalRowHeight(aspectRatios, containerWidth, gap) {
        const totalAspectRatio = aspectRatios.reduce((s, ar) => s + ar, 0);
        const availableWidth = containerWidth - (gap * (aspectRatios.length - 1));
        return availableWidth / totalAspectRatio;
    }

    function getAspectRatioForCard(card) {
        const img = card.querySelector('img');
        if (!img) return 1; // safe default
        const natW = img.naturalWidth, natH = img.naturalHeight;
        if (natW > 0 && natH > 0) return natW / natH;

        // fallback to data-* on img, <a>, or card
        const a = card.querySelector('a');
        const w = parseInt(img.getAttribute('data-width') || a?.getAttribute('data-width') || card.getAttribute('data-width') || 0, 10);
        const h = parseInt(img.getAttribute('data-height') || a?.getAttribute('data-height') || card.getAttribute('data-height') || 0, 10);
        if (w > 0 && h > 0) return w / h;

        return 1.5; // sensible default for landscape-ish galleries
    }

    function getImagesPerRow(containerWidth, breakpoints, responsiveSettings) {
        const sortedBreakpoints = Object.entries(breakpoints).sort((a, b) => b[1] - a[1]);
        for (const [breakpointName, breakpointWidth] of sortedBreakpoints) {
            if (containerWidth >= breakpointWidth) {
                return responsiveSettings[breakpointName] || 1;
            }
        }
        const smallestBreakpoint = Object.entries(breakpoints).sort((a, b) => a[1] - b[1])[0];
        return smallestBreakpoint ? (responsiveSettings[smallestBreakpoint[0]] || 1) : 1;
    }

    function initJustifiedGallery() {
        const grids = document.querySelectorAll('.flickr-justified-grid:not(.justified-initialized)');
        grids.forEach(grid => {
            const cards = grid.querySelectorAll('.flickr-card');
            if (cards.length === 0) return;

            // Build rows immediately (use data-* aspect ratios / fallbacks).
            // Fine-tuning happens inside processRows() when decode/load resolves.
            // This prevents the "1 image per row" tall column flash.
            // (Chrome defers load on lazy images, so we must not gate on it.)
            // ----------------------------------------------------------------

            function processRows() {
                const containerWidth = grid.offsetWidth;
                const gap = parseInt(getComputedStyle(grid).getPropertyValue('--gap'), 10) || 12;

                let responsiveSettings = {};
                let breakpoints = {};
                let rowHeightMode = 'auto';
                let rowHeight = 280;
                let maxViewportHeight = 80;
                let singleImageAlignment = 'center';

                try {
                    const responsiveData = grid.getAttribute('data-responsive-settings');
                    const breakpointsData = grid.getAttribute('data-breakpoints');
                    const rowHeightModeData = grid.getAttribute('data-row-height-mode');
                    const rowHeightData = grid.getAttribute('data-row-height');
                    const maxViewportHeightData = grid.getAttribute('data-max-viewport-height');
                    const singleImageAlignmentData = grid.getAttribute('data-single-image-alignment');

                    if (responsiveData) responsiveSettings = JSON.parse(responsiveData);
                    if (breakpointsData) breakpoints = JSON.parse(breakpointsData);
                    if (rowHeightModeData) rowHeightMode = rowHeightModeData;
                    if (rowHeightData) rowHeight = parseInt(rowHeightData, 10) || 280;
                    if (maxViewportHeightData) maxViewportHeight = parseInt(maxViewportHeightData, 10) || 80;
                    if (singleImageAlignmentData) singleImageAlignment = singleImageAlignmentData || 'center';
                } catch (e) {
                    console.warn('Error parsing responsive settings:', e);
                    // Use the same hardcoded fallbacks as server-side for consistency
                    responsiveSettings = { mobile: 1, mobile_landscape: 1, tablet_portrait: 2, tablet_landscape: 3, desktop: 3, large_desktop: 4, extra_large: 4 };
                    breakpoints = { mobile: 320, mobile_landscape: 480, tablet_portrait: 600, tablet_landscape: 768, desktop: 1024, large_desktop: 1280, extra_large: 1440 };
                    rowHeightMode = 'auto';
                    rowHeight = 280;
                    maxViewportHeight = 80;
                    singleImageAlignment = 'center';
                }

                const imagesPerRow = getImagesPerRow(containerWidth, breakpoints, responsiveSettings);
                const maxAllowedHeight = Math.max(50, Math.min(100, maxViewportHeight)) / 100 * window.innerHeight;

                const allCards = Array.from(cards);
                if (allCards.length === 0) return;

                const cardsData = allCards.map(card => ({
                    element: card,
                    img: card.querySelector('img'),
                    ar: getAspectRatioForCard(card)
                }));
                grid.innerHTML = '';

                if (cardsData.length > 1) {
                    grid.style.alignItems = '';
                    grid.style.justifyContent = '';
                }

                let previousRowHeight = null;
                for (let i = 0; i < cardsData.length; i += imagesPerRow) {
                    const rowCards = cardsData.slice(i, i + imagesPerRow);
                    const isLastRow = i + imagesPerRow >= cardsData.length;
                    const isSingleImageLastRow = isLastRow && rowCards.length === 1 && previousRowHeight !== null;

                    let actualRowHeight;
                    if (isSingleImageLastRow) {
                        const aspectRatio = rowCards[0].ar;
                        const maxHeight = previousRowHeight;
                        const maxWidth = containerWidth;
                        const heightFromWidth = maxWidth / aspectRatio;
                        actualRowHeight = Math.min(maxHeight, heightFromWidth, maxAllowedHeight);
                    } else if (rowHeightMode === 'auto') {
                        const aspectRatios = rowCards.map(c => c.ar);
                        const optimalHeight = calculateOptimalRowHeight(aspectRatios, containerWidth, gap);
                        actualRowHeight = Math.min(optimalHeight, maxAllowedHeight);
                    } else {
                        actualRowHeight = Math.min(rowHeight, maxAllowedHeight);
                    }
                    previousRowHeight = actualRowHeight;

                    const row = document.createElement('div');
                    row.className = 'flickr-row';
                    row.style.height = `${actualRowHeight}px`;

                    rowCards.forEach(({ element: card, img, ar: aspectRatio }) => {
                        const width = actualRowHeight * aspectRatio;
                        card.style.width = `${width}px`;
                        card.style.height = `${actualRowHeight}px`;
                        row.appendChild(card);
                    });

                    grid.appendChild(row);
                }

                grid.classList.add('justified-initialized');

                // Optional refinement: relayout when images decode for pixel-perfect sizing
                const imgsNeedingDecode = Array.from(grid.querySelectorAll('.flickr-card img'))
                    .filter(img => !(img.complete && img.naturalWidth > 0));

                if (imgsNeedingDecode.length > 0) {
                    imgsNeedingDecode.forEach(img => {
                        const relayout = () => {
                            // schedule a single reflow per grid
                            if (grid._pendingRelayout) return;
                            grid._pendingRelayout = true;
                            requestAnimationFrame(() => {
                                grid.classList.remove('justified-initialized');
                                initJustifiedGallery();
                                grid._pendingRelayout = false;
                            });
                        };
                        if (typeof img.decode === 'function') {
                            img.decode().then(relayout).catch(relayout);
                        } else {
                            img.addEventListener('load', relayout, { once: true });
                            img.addEventListener('error', relayout, { once: true });
                        }
                    });
                }

                const reinitEvent = new CustomEvent('flickrGalleryReorganized', { detail: { grid: grid } });
                document.dispatchEvent(reinitEvent);
            }

            // Do the initial layout right now.
            processRows();

            // (Optional) Progressive refinement as images load is already handled
            // by decode-based relayout inside processRows(). No need to gate
            // the first render on image loads.
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initJustifiedGallery);
    } else {
        initJustifiedGallery();
    }

    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            const windowWidth = window.innerWidth;
            console.log(`🔄 Window resized to ${windowWidth}px, reinitializing galleries...`);

            const initializedGrids = document.querySelectorAll('.flickr-justified-grid.justified-initialized');
            console.log(`Found ${initializedGrids.length} initialized galleries to resize`);

            initializedGrids.forEach((grid, index) => {
                const containerWidth = grid.offsetWidth;
                console.log(`Gallery ${index + 1}: container width = ${containerWidth}px`);

                // Temporarily disable lazy loading cooldown during resize
                const wasResizing = grid._isResizing;
                grid._isResizing = true;
                grid._resizeFlagTs = Date.now();

                grid.classList.remove('justified-initialized');

                // Reset cooldown after a brief delay to allow resize to complete
                setTimeout(() => {
                    // only clear if no newer resize flagged it again
                    if (grid._isResizing && (Date.now() - (grid._resizeFlagTs || 0)) > 900) {
                        delete grid._isResizing;
                        delete grid._resizeFlagTs;
                    }
                }, 1000);
            });

            initJustifiedGallery();
            console.log('✅ Gallery reinitialization after resize complete');
        }, 250);
    });

    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((mutations) => {
            const shouldInit = mutations.some(m =>
                Array.from(m.addedNodes).some(n => n.nodeType === 1 && n.classList && n.classList.contains('flickr-justified-grid') && !n.classList.contains('justified-initialized'))
            );
            if (shouldInit) setTimeout(initJustifiedGallery, 150);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // Make initJustifiedGallery accessible to lazy loading function
    window.initJustifiedGallery = initJustifiedGallery;

    // Initialize lazy loading for Flickr albums
    initFlickrAlbumLazyLoading();
})();

/**
 * Lazy loading for Flickr albums/sets - loads additional pages when user scrolls
 */
function initFlickrAlbumLazyLoading() {
    'use strict';

    // Helper visible to all inner functions
    function getLastImageInGallery(gallery) {
        const cards = gallery.querySelectorAll(':scope > .flickr-row .flickr-card, :scope > .flickr-card');
        if (!cards.length) return null;
        const lastCard = cards[cards.length - 1];
        return lastCard.querySelector('img');
    }

    // Find galleries with set metadata (indicating they have more pages to load)
    const allGalleries = document.querySelectorAll('.flickr-justified-grid');
    const galleriesWithSets = document.querySelectorAll('.flickr-justified-grid[data-set-metadata]');

    console.log(`🔍 Lazy loading check: Found ${allGalleries.length} total galleries, ${galleriesWithSets.length} with set metadata`);

    // Debug: Show what attributes each gallery has
    allGalleries.forEach((gallery, index) => {
        const attributes = Array.from(gallery.attributes).map(attr => attr.name);
        console.log(`Gallery ${index + 1} attributes:`, attributes);

        const setMetadata = gallery.getAttribute('data-set-metadata');
        if (setMetadata) {
            console.log(`Gallery ${index + 1} metadata:`, setMetadata.substring(0, 100) + '...');
        }
    });

    galleriesWithSets.forEach(gallery => {
        // Prevent duplicate observers per gallery
        if (gallery._flickrLazyObserver) return; // already wired

        const metadataAttr = gallery.getAttribute('data-set-metadata');
        if (!metadataAttr) return;

        let setMetadata;
        try {
            setMetadata = JSON.parse(metadataAttr);
        } catch (e) {
            console.warn('Failed to parse set metadata:', e);
            return;
        }

        if (!Array.isArray(setMetadata) || setMetadata.length === 0) return;

        console.log('Lazy loading initialized for gallery with metadata:', setMetadata);

        // Track loading state for each set
        setMetadata.forEach(setData => {
            setData.isLoading = false;
            setData.loadingError = false;
        });

        // Create intersection observer to detect when last image comes into view
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                console.log('👁️ Intersection observer triggered. Is intersecting:', entry.isIntersecting);
                if (entry.isIntersecting) {
                    console.log('✨ Last image is visible, calling loadNextPages');
                    loadNextPages(gallery, setMetadata);
                }
            });
        }, {
            rootMargin: '500px' // Load when last image is 500px from view
        });

        // Find and observe the last image in the gallery
        function observeLastImage() {
            const lastImage = getLastImageInGallery(gallery);
            if (lastImage) {
                observer.observe(lastImage);
                gallery._lastObservedImage = lastImage;
                console.log('👁️ Now observing last image for lazy loading');
            } else {
                console.warn('⚠️ No last image found to observe');
            }
        }

        observeLastImage();

        // Store observer reference for cleanup
        gallery._flickrLazyObserver = observer;
    });

    async function loadNextPages(gallery, originalSetMetadata) {
        console.log('🔄 loadNextPages triggered by scroll');
        // Prevent rapid-fire loading by checking if we're already loading
        if (gallery._flickrLoading) {
            console.log('⏸️ Already loading, skipping');
            return;
        }

        // Add cooldown period after reinitialization to prevent immediate re-triggering
        // But allow immediate loading during window resize
        const now = Date.now();
        const lastReinit = gallery._lastReinit || 0;
        const cooldownPeriod = 2000; // 2 seconds cooldown
        const isResizing = gallery._isResizing;

        if (!isResizing && now - lastReinit < cooldownPeriod) {
            console.log(`🧊 Cooldown active (${Math.round((cooldownPeriod - (now - lastReinit)) / 1000)}s remaining), skipping`);
            return;
        }

        if (isResizing) {
            console.log('🪟 Resize in progress, bypassing cooldown for immediate reinitialization');
        }

        console.log('🚀 Starting to load next pages');
        gallery._flickrLoading = true;

        // CRITICAL FIX: Re-read metadata from DOM to get latest state
        const currentMetadataAttr = gallery.getAttribute('data-set-metadata');
        let setMetadata = originalSetMetadata; // fallback
        if (currentMetadataAttr) {
            try {
                setMetadata = JSON.parse(currentMetadataAttr);
                console.log('✅ Re-read fresh metadata from DOM');
            } catch (e) {
                console.warn('Failed to parse updated metadata, using original:', e);
            }
        }

        // Find sets that have more pages to load
        console.log('Checking for sets to load. Current metadata:', setMetadata);
        const setsToLoad = setMetadata.filter(setData => {
            const canLoad = !setData.isLoading &&
                !setData.loadingError &&
                setData.current_page < setData.total_pages;
            console.log(`Set ${setData.photoset_id}: current_page=${setData.current_page}, total_pages=${setData.total_pages}, can_load=${canLoad}`);
            return canLoad;
        });

        console.log(`Found ${setsToLoad.length} sets to load`);
        if (setsToLoad.length === 0) {
            // No more pages to load, clean up observer
            const observer = gallery._flickrLazyObserver;
            if (observer) {
                if (gallery._lastObservedImage) {
                    observer.unobserve(gallery._lastObservedImage);
                }
                observer.disconnect();
                delete gallery._flickrLazyObserver;
                delete gallery._lastObservedImage;
                console.log('🧹 Cleaned up lazy loading observer - no more pages to load');
            }
            gallery._flickrLoading = false;
            return;
        }

        // Show (or reuse) loading indicator with better styling and positioning
        const baseLoadingMessage = '⏳ Please Wait, Loading More Images...';
        let loadingIndicator = gallery.querySelector('.flickr-loading-indicator');
        if (!loadingIndicator) {
            loadingIndicator = document.createElement('div');
            loadingIndicator.className = 'flickr-loading-indicator';
            loadingIndicator.style.cssText = `
                text-align: center;
                padding: 20px;
                font-size: 18px;
                color: #333;
                font-weight: 600;
                background: rgba(255, 255, 255, 0.95);
                border: 2px solid #007cba;
                border-radius: 8px;
                margin: 20px auto;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                position: relative;
                z-index: 1000;
            `;
            loadingIndicator.textContent = baseLoadingMessage;

            console.log('🔔 Creating loading indicator...');

            // Insert loading indicator at the end of the gallery
            console.log('🔔 Appending loading indicator to gallery end');
            gallery.appendChild(loadingIndicator);
        } else {
            loadingIndicator.textContent = baseLoadingMessage;
        }

        // Force the indicator to be visible immediately
        setTimeout(() => {
            if (loadingIndicator.parentNode) {
                console.log('🔔 Loading indicator should now be visible');
                loadingIndicator.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 50);

        // Load next page for each set (in parallel)
        const loadPromises = setsToLoad.map(setData => loadSetPage(gallery, setData, setMetadata));

        let indicatorShouldPersist = false;
        let indicatorMessage = '';
        let shouldRemoveIndicator = false;
        let scheduledRetryDelay = null;

        try {
            // ⏳ Waiting done...
            const results = await Promise.all(loadPromises);

            const hasSuccess = results.some(result => result && result.status === 'success');
            const recoverableResults = results.filter(result => result && result.status === 'recoverable-error');
            const hasRecoverable = recoverableResults.length > 0;
            const pendingSets = setMetadata.some(set => !set.loadingError && set.current_page < set.total_pages);

            if (hasSuccess) {
                // Re-initialize the justified layout with new photos
                console.log('🔄 Starting gallery reinitialization...');
                gallery.classList.remove('justified-initialized');

                // Stop observing the old last image before reinitialization
                const observer = gallery._flickrLazyObserver;
                if (observer && gallery._lastObservedImage) {
                    observer.unobserve(gallery._lastObservedImage);
                    console.log('👁️ Stopped observing old last image');
                }

                // Reinitialize immediately using aspect-ratio fallbacks (no decode wait)
                reinitializeGallery();
            }

            if (hasRecoverable && pendingSets) {
                indicatorShouldPersist = true;
                const firstRecoverableMessage = recoverableResults.find(r => r.message)?.message;
                indicatorMessage = firstRecoverableMessage || '⚠️ Temporary issue loading images. Retrying shortly...';
                const retrySuggestion = recoverableResults.find(r => typeof r.retryDelay === 'number');
                if (retrySuggestion) {
                    scheduledRetryDelay = retrySuggestion.retryDelay;
                }
            } else if (!pendingSets) {
                shouldRemoveIndicator = true;
            } else if (hasSuccess && !hasRecoverable) {
                shouldRemoveIndicator = true;
            }

            function reinitializeGallery() {
                console.log('📐 Dispatching gallery reorganized event...');
                const event = new CustomEvent('flickrGalleryReorganized', { detail: { grid: gallery } });
                document.dispatchEvent(event);

                // Stage new cards if any
                let staging = gallery.querySelector('.flickr-staging');
                if (!staging) {
                    staging = document.createElement('div');
                    staging.className = 'flickr-staging';
                    staging.style.display = 'none';
                    gallery.appendChild(staging);
                }
                if (gallery._pendingPhotos && gallery._pendingPhotos.length > 0) {
                    const frag = document.createDocumentFragment();
                    gallery._pendingPhotos.forEach(photoData => {
                        const card = createPhotoCard(photoData, gallery);
                        if (card) frag.appendChild(card);
                    });
                    staging.appendChild(frag);
                    delete gallery._pendingPhotos;
                }

                // Rebuild rows immediately
                gallery.classList.remove('justified-initialized');
                window.initJustifiedGallery();

                // Re-observe new last image
                setTimeout(() => {
                    const obs = gallery._flickrLazyObserver;
                    if (obs) {
                        const newLastImage = (function getLastImageInGallery(g){
                            const cards = g.querySelectorAll(':scope > .flickr-row .flickr-card, :scope > .flickr-card');
                            if (!cards.length) return null;
                            return cards[cards.length - 1].querySelector('img');
                        })(gallery);
                        if (newLastImage) {
                            obs.observe(newLastImage);
                            gallery._lastObservedImage = newLastImage;
                        }
                    }
                }, 100);

                // Ping PhotoSwipe
                const photoswipeEvent = new CustomEvent('flickr-gallery-updated', { detail: { gallery } });
                document.dispatchEvent(photoswipeEvent);

                gallery._lastReinit = Date.now();
                console.log('🏁 Gallery reinitialization complete');
            }

            if (indicatorShouldPersist && scheduledRetryDelay && !gallery._pendingRecoverableRetry) {
                gallery._pendingRecoverableRetry = setTimeout(() => {
                    delete gallery._pendingRecoverableRetry;
                    loadNextPages(gallery, setMetadata);
                }, scheduledRetryDelay);
            } else if (shouldRemoveIndicator && gallery._pendingRecoverableRetry) {
                clearTimeout(gallery._pendingRecoverableRetry);
                delete gallery._pendingRecoverableRetry;
            }

        } catch (error) {
            console.error('Failed to load album pages:', error);
            const stillExpectingPages = setMetadata.some(set => !set.loadingError && set.current_page < set.total_pages);
            if (stillExpectingPages) {
                indicatorShouldPersist = true;
                indicatorMessage = '⚠️ Temporary issue loading images. Retrying shortly...';
            } else {
                if (gallery._pendingRecoverableRetry) {
                    clearTimeout(gallery._pendingRecoverableRetry);
                    delete gallery._pendingRecoverableRetry;
                }

                shouldRemoveIndicator = true;
            }
        } finally {
            // Always reset loading flag
            gallery._flickrLoading = false;

            if (loadingIndicator) {
                if (indicatorShouldPersist) {
                    if (indicatorMessage) {
                        loadingIndicator.textContent = indicatorMessage;
                    }
                } else if (shouldRemoveIndicator && loadingIndicator.parentNode) {
                    loadingIndicator.remove();
                }
            }
        }
    }

    async function loadSetPage(gallery, setData, setMetadata) {
        setData.isLoading = true;
        const nextPage = setData.current_page + 1;

        console.log(`Loading page ${nextPage} for set ${setData.photoset_id}`);

        try {
            const response = await fetch('/wp-json/flickr-justified/v1/load-album-page', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: setData.user_id,
                    photoset_id: setData.photoset_id,
                    page: nextPage
                }),
                // Add timeout to prevent hanging requests (with fallback for older browsers)
                signal: typeof AbortSignal.timeout === 'function' ?
                    AbortSignal.timeout(30000) :
                    (() => {
                        const controller = new AbortController();
                        setTimeout(() => controller.abort(), 30000);
                        return controller.signal;
                    })()
            });

            if (!response.ok) {
                const errorText = await response.text().catch(() => 'Unknown error');
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }

            const result = await response.json();

            if (!result.success || !result.photos || !Array.isArray(result.photos)) {
                throw new Error('Invalid response format');
            }

            console.log(`Loaded ${result.photos.length} photos from page ${nextPage}`);

            // Store new photos for insertion after reinitialization
            if (result.photos && result.photos.length > 0) {
                if (!gallery._pendingPhotos) gallery._pendingPhotos = [];
                result.photos.forEach(photoData => {
                    // Validate photo data before storing
                    if (!photoData || !photoData.image_url) {
                        console.warn('Invalid photo data received:', photoData);
                        return;
                    }
                    gallery._pendingPhotos.push(photoData);
                });
                console.log(`📦 Stored ${result.photos.length} photos for insertion after reinitialization`);
            }

            // Update set metadata - use nextPage since that's what we requested
            setData.current_page = nextPage; // Use nextPage instead of result.page for reliability
            setData.loaded_photos = (setData.loaded_photos || 0) + result.photos.length;
            setData.isLoading = false;

            console.log(`📊 Updated set metadata: current_page=${setData.current_page}, total_pages=${setData.total_pages}, loaded_photos=${setData.loaded_photos}`);

            // Update the data attribute on the gallery with all set metadata
            gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));
            console.log('💾 Updated DOM with new metadata');

            return { status: 'success' };

        } catch (error) {
            console.error(`Failed to load page ${nextPage} for set ${setData.photoset_id}:`, error);

            // Implement retry logic for temporary network errors
            if (!setData.retryCount) setData.retryCount = 0;

            const persistMetadata = (reason) => {
                try {
                    gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));
                    console.log(`💾 Updated DOM with metadata after ${reason}`);
                } catch (metadataError) {
                    console.warn('Failed to persist metadata after error handling:', metadataError);
                }
            };

            const statusMatch = /HTTP\s+(\d{3})/i.exec(error?.message || '');
            const statusCode = error?.status || (statusMatch ? parseInt(statusMatch[1], 10) : null);
            const recoverableStatusCodes = new Set([408, 425, 429, 500, 502, 503, 504]);
            const isNetworkError = error.name === 'AbortError' || /network/i.test(error?.message || '');
            const isRecoverableStatus = statusCode ? recoverableStatusCodes.has(statusCode) : false;
            const isRecoverable = isNetworkError || isRecoverableStatus;

            if (setData.retryCount < 2 && isNetworkError) {
                // Retry after delay for network errors
                setData.retryCount++;
                setData.isLoading = false;

                const retryDelay = 2000 * setData.retryCount; // Exponential backoff

                persistMetadata('network error retry preparation');

                return {
                    status: 'recoverable-error',
                    recoverable: true,
                    message: '⚠️ Temporary network issue. Retrying shortly...',
                    retryDelay
                };
            } else if (isRecoverable) {
                setData.isLoading = false;
                const delay = Math.min(10000, 3000 * Math.max(1, setData.retryCount || 1));
                setData.retryCount++;

                persistMetadata('recoverable error handling');

                return {
                    status: 'recoverable-error',
                    recoverable: true,
                    statusCode,
                    message: statusCode === 429 ? '⚠️ Rate limit hit. Waiting before retrying...' : '⚠️ Temporary issue loading images. Retrying shortly...',
                    retryDelay: delay
                };
            } else {
                // Max retries reached or non-recoverable error
                setData.loadingError = true;
                setData.isLoading = false;

                persistMetadata('fatal error handling');

                return {
                    status: 'fatal-error',
                    recoverable: false,
                    statusCode,
                    message: error?.message || 'Unknown error'
                };
            }
        }
    }

    function createPhotoCard(photoData, gallery) {
        // Validate required photoData fields
        if (!photoData || typeof photoData !== 'object' || !photoData.image_url) {
            console.warn('Invalid photo data for card creation:', photoData);
            return null;
        }

        const card = document.createElement('article');
        card.className = 'flickr-card';
        card.style.position = 'relative'; // Match server-side positioning

        const link = document.createElement('a');
        // Match server-side class exactly - only flickr-builtin-lightbox
        link.className = 'flickr-builtin-lightbox';
        link.href = photoData.image_url;

        // expose dimensions for layout before load
        if (photoData.width && photoData.height) {
            link.setAttribute('data-width', photoData.width);
            link.setAttribute('data-height', photoData.height);
        }

        // Get the gallery ID from the existing gallery structure
        const existingItems = gallery.querySelectorAll('.flickr-builtin-lightbox[data-gallery]');
        const galleryId = existingItems.length > 0 ?
            existingItems[0].getAttribute('data-gallery') :
            'flickr-gallery-' + Date.now();
        link.setAttribute('data-gallery', galleryId);

        // Add Flickr attribution attributes to match server-side structure
        if (photoData.is_flickr && photoData.flickr_page) {
            const attributionText = gallery?.dataset?.attributionText || 'Flickr';

            link.setAttribute('data-flickr-page', photoData.flickr_page);
            link.setAttribute('data-flickr-attribution-text', attributionText);

            // Add additional lightbox caption attributes (matches server-side)
            link.setAttribute('data-caption', attributionText);
            link.setAttribute('data-title', attributionText);
            link.setAttribute('title', attributionText);
        }

        const img = document.createElement('img');
        img.src = photoData.image_url;
        img.alt = '';
        img.loading = 'lazy';
        img.setAttribute('decoding', 'async'); // Match server-side attributes
        if (photoData.width && photoData.height) {
            img.setAttribute('data-width', photoData.width);
            img.setAttribute('data-height', photoData.height);
        }

        link.appendChild(img);
        card.appendChild(link);

        // Give the card a provisional box that matches aspect-ratio
        if (photoData.width && photoData.height) {
            card.style.aspectRatio = `${photoData.width} / ${photoData.height}`;
        }

        return card;
    }
}

