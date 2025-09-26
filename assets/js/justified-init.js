(function() {
    'use strict';

    function calculateOptimalRowHeight(images, containerWidth, gap) {
        let totalAspectRatio = 0;
        images.forEach(img => {
            const aspectRatio = img.naturalWidth / img.naturalHeight;
            totalAspectRatio += aspectRatio;
        });
        const availableWidth = containerWidth - (gap * (images.length - 1));
        return availableWidth / totalAspectRatio;
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

            const allImages = Array.from(cards).map(card => card.querySelector('img')).filter(img => img);
            let loadedCount = 0;

            function checkAllLoaded() {
                loadedCount++;
                if (loadedCount === allImages.length) {
                    processRows();
                }
            }

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

                const allCards = Array.from(cards).filter(card => {
                    const img = card.querySelector('img');
                    return img && img.naturalWidth > 0 && img.naturalHeight > 0;
                });
                if (allCards.length === 0) return;

                const cardsData = allCards.map(card => ({ element: card, img: card.querySelector('img') }));
                grid.innerHTML = '';

                if (cardsData.length > 1) {
                    grid.style.alignItems = '';
                    grid.style.justifyContent = '';
                }

                let previousRowHeight = null;
                for (let i = 0; i < cardsData.length; i += imagesPerRow) {
                    const rowCards = cardsData.slice(i, i + imagesPerRow);
                    const rowImages = rowCards.map(cardData => cardData.img);
                    const isLastRow = i + imagesPerRow >= cardsData.length;
                    const isSingleImageLastRow = isLastRow && rowCards.length === 1 && previousRowHeight !== null;

                    let actualRowHeight;
                    if (isSingleImageLastRow) {
                        const img = rowImages[0];
                        const aspectRatio = img.naturalWidth / img.naturalHeight;
                        const maxHeight = previousRowHeight;
                        const maxWidth = containerWidth;
                        const heightFromWidth = maxWidth / aspectRatio;
                        actualRowHeight = Math.min(maxHeight, heightFromWidth, maxAllowedHeight);
                    } else if (rowHeightMode === 'auto') {
                        const optimalHeight = calculateOptimalRowHeight(rowImages, containerWidth, gap);
                        actualRowHeight = Math.min(optimalHeight, maxAllowedHeight);
                    } else {
                        actualRowHeight = Math.min(rowHeight, maxAllowedHeight);
                    }
                    previousRowHeight = actualRowHeight;

                    const row = document.createElement('div');
                    row.className = 'flickr-row';
                    row.style.height = `${actualRowHeight}px`;

                    rowCards.forEach(({ element: card, img }) => {
                        const aspectRatio = img.naturalWidth / img.naturalHeight;
                        const width = actualRowHeight * aspectRatio;
                        card.style.width = `${width}px`;
                        card.style.height = `${actualRowHeight}px`;
                        img.style.width = `${width}px`;
                        img.style.height = `${actualRowHeight}px`;
                        row.appendChild(card);
                    });

                    grid.appendChild(row);
                }

                grid.classList.add('justified-initialized');
                const reinitEvent = new CustomEvent('flickrGalleryReorganized', { detail: { grid: grid } });
                document.dispatchEvent(reinitEvent);
            }

            if (allImages.length === 0) {
                processRows();
                return;
            }
            allImages.forEach(img => {
                if (img.complete && img.naturalWidth > 0) {
                    checkAllLoaded();
                } else if (!img.hasAttribute('data-listeners-added')) {
                    img.addEventListener('load', checkAllLoaded);
                    img.addEventListener('error', checkAllLoaded);
                    img.setAttribute('data-listeners-added', 'true');
                }
            });
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

                grid.classList.remove('justified-initialized');

                // Reset cooldown after a brief delay to allow resize to complete
                setTimeout(() => {
                    if (!wasResizing) {
                        delete grid._isResizing;
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
        observer.observe(document.body, { childList: true, subtree: false });
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

        // Create intersection observer to detect when user scrolls near bottom
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                console.log('👁️ Intersection observer triggered. Is intersecting:', entry.isIntersecting);
                if (entry.isIntersecting) {
                    console.log('✨ Trigger element is visible, calling loadNextPages');
                    loadNextPages(gallery, setMetadata);
                }
            });
        }, {
            rootMargin: '1000px' // Load when 1000px from bottom (increased for large galleries)
        });

        // Create and observe a trigger element at the bottom of the gallery
        const trigger = document.createElement('div');
        trigger.style.cssText = `
            height: 10px;
            width: 100%;
            clear: both;
            visibility: hidden;
            position: relative;
            display: block;
        `;
        trigger.className = 'flickr-lazy-trigger';
        gallery.appendChild(trigger);
        observer.observe(trigger);

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
            // No more pages to load, clean up observer and remove trigger
            const trigger = gallery.querySelector('.flickr-lazy-trigger');
            if (trigger) {
                // Get the observer from gallery data if it exists
                const observer = gallery._flickrLazyObserver;
                if (observer) {
                    observer.unobserve(trigger);
                    observer.disconnect();
                    delete gallery._flickrLazyObserver;
                }
                trigger.remove();
            }
            gallery._flickrLoading = false;
            return;
        }

        // Show loading indicator with better styling and positioning
        const loadingIndicator = document.createElement('div');
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
        loadingIndicator.textContent = '⏳ Please Wait, Loading More Images...';

        console.log('🔔 Creating loading indicator...');

        // Insert loading indicator before the trigger
        const trigger = gallery.querySelector('.flickr-lazy-trigger');
        if (trigger) {
            console.log('🔔 Inserting loading indicator before trigger');
            gallery.insertBefore(loadingIndicator, trigger);
        } else {
            console.log('🔔 Appending loading indicator to gallery end');
            gallery.appendChild(loadingIndicator);
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

        try {
            console.log(`⏳ Waiting for ${loadPromises.length} page load promises to complete...`);
            await Promise.all(loadPromises);
            console.log('✅ All page load promises completed successfully');

            // Remove loading indicator
            if (loadingIndicator && loadingIndicator.parentNode) {
                loadingIndicator.remove();
            }

            // Re-initialize the justified layout with new photos
            console.log('🔄 Starting gallery reinitialization...');
            gallery.classList.remove('justified-initialized');

            // Create trigger BEFORE reinitialization to ensure it's always there
            let triggerElement = gallery.querySelector('.flickr-lazy-trigger');
            if (!triggerElement) {
                console.log('🆕 Creating trigger element before reinitialization');
                triggerElement = document.createElement('div');
                triggerElement.style.cssText = `
                    height: 10px;
                    width: 100%;
                    clear: both;
                    visibility: hidden;
                    position: relative;
                    display: block;
                `;
                triggerElement.className = 'flickr-lazy-trigger';
                gallery.appendChild(triggerElement);
            } else {
                console.log('✅ Trigger element already exists');
            }

            // Wait for all images to load before reinitialization to prevent layout thrashing
            setTimeout(() => {
                console.log('📐 Waiting for images to load before reinitialization...');

                // Get only newly added images (without data-load-listener-added attribute)
                const allImages = gallery.querySelectorAll('.flickr-card img');
                const newImages = Array.from(allImages).filter(img => !img.hasAttribute('data-load-listener-added'));
                console.log(`📊 Image count: ${allImages.length} total, ${newImages.length} newly added`);
                let loadedCount = 0;
                const totalImages = newImages.length;

                if (totalImages === 0) {
                    // No lazy loading images, proceed immediately
                    reinitializeGallery();
                    return;
                }

                console.log(`📸 Waiting for ${totalImages} images to load...`);

                const checkAllLoaded = () => {
                    loadedCount++;
                    // Only log progress at key milestones to reduce console spam
                    if (loadedCount === 1 || loadedCount % 10 === 0 || loadedCount === totalImages) {
                        console.log(`📸 Image loaded (${loadedCount}/${totalImages})`);
                    }
                    if (loadedCount >= totalImages) {
                        console.log('📸 All images loaded, reinitializing gallery...');
                        reinitializeGallery();
                    }
                };

                // Add load listeners to all new images
                newImages.forEach((img, index) => {
                    if (img.complete && img.naturalWidth > 0) {
                        // Image already loaded
                        checkAllLoaded();
                    } else if (!img.hasAttribute('data-load-listener-added')) {
                        // Wait for image to load - avoid duplicate listeners
                        img.addEventListener('load', checkAllLoaded, { once: true });
                        img.addEventListener('error', checkAllLoaded, { once: true }); // Count errors too
                        img.setAttribute('data-load-listener-added', 'true');
                    } else {
                        // Listener already added but image not loaded, count anyway
                        checkAllLoaded();
                    }
                });

                // Fallback timeout in case some images never load
                setTimeout(() => {
                    if (loadedCount < totalImages) {
                        console.log(`⏰ Timeout reached, reinitializing anyway (${loadedCount}/${totalImages} loaded)`);
                        reinitializeGallery();
                    }
                }, 5000);

                function reinitializeGallery() {
                    console.log('📐 Dispatching gallery reorganized event...');
                    const event = new CustomEvent('flickrGalleryReorganized', { detail: { grid: gallery } });
                    document.dispatchEvent(event);

                    // Re-initialize gallery layout
                    console.log('🏗️ Re-initializing gallery layout...');
                    window.initJustifiedGallery();

                    // Re-initialize PhotoSwipe lightbox for new images
                    console.log('🔦 Re-initializing PhotoSwipe lightbox for new images...');

                    // Multiple attempts to ensure PhotoSwipe integration
                    const triggerPhotoSwipeUpdate = () => {
                        const photoswipeEvent = new CustomEvent('flickr-gallery-updated', {
                            detail: { gallery: gallery }
                        });
                        document.dispatchEvent(photoswipeEvent);
                    };

                    // Immediate trigger
                    triggerPhotoSwipeUpdate();

                    // Delayed trigger to ensure DOM is fully updated
                    setTimeout(triggerPhotoSwipeUpdate, 50);

                    // Final trigger after potential layout changes
                    setTimeout(triggerPhotoSwipeUpdate, 200);

                    // Always ensure we have a valid trigger element
                    let finalTriggerElement = gallery.querySelector('.flickr-lazy-trigger');

                    // Check if the trigger is valid (has proper dimensions and is attached)
                    const isValidTrigger = finalTriggerElement &&
                                         finalTriggerElement.parentNode === gallery &&
                                         finalTriggerElement.offsetHeight > 0 &&
                                         finalTriggerElement.offsetWidth > 0;

                    console.log(`🔍 Checking for trigger after reinitialization: ${finalTriggerElement ? (isValidTrigger ? 'VALID' : 'INVALID') : 'DESTROYED'}`);

                    if (!isValidTrigger) {
                        // Remove any invalid trigger elements first
                        const oldTriggers = gallery.querySelectorAll('.flickr-lazy-trigger');
                        oldTriggers.forEach(trigger => trigger.remove());

                        console.log('🔄 Creating new trigger element with proper dimensions');
                        finalTriggerElement = document.createElement('div');
                        finalTriggerElement.style.cssText = `
                            height: 10px;
                            width: 100%;
                            clear: both;
                            visibility: hidden;
                            position: relative;
                            display: block;
                        `;
                        finalTriggerElement.className = 'flickr-lazy-trigger';
                        gallery.appendChild(finalTriggerElement);

                        // Force reflow to ensure element has dimensions
                        const rect = finalTriggerElement.getBoundingClientRect();
                        console.log(`✅ New trigger dimensions: ${rect.width}x${rect.height}, positioned at ${rect.top}`);
                    }

                    // ALWAYS re-observe the trigger element
                    const observer = gallery._flickrLazyObserver;
                    if (observer && finalTriggerElement) {
                        console.log('👁️ Re-observing trigger element (required after gallery reinitialization)');
                        // First unobserve in case it's still connected to avoid duplicate observations
                        try {
                            observer.unobserve(finalTriggerElement);
                        } catch (e) {
                            // Element might not have been observed before
                        }
                        observer.observe(finalTriggerElement);

                        // Debug: Check trigger element positioning
                        setTimeout(() => {
                            const rect = finalTriggerElement.getBoundingClientRect();
                            const galleryRect = gallery.getBoundingClientRect();
                            console.log(`📏 Trigger position: top=${Math.round(rect.top)}, bottom=${Math.round(rect.bottom)}, height=${rect.height}`);
                            console.log(`📏 Gallery position: top=${Math.round(galleryRect.top)}, bottom=${Math.round(galleryRect.bottom)}, height=${Math.round(galleryRect.height)}`);
                            console.log(`📏 Viewport height: ${window.innerHeight}`);
                            console.log(`📏 Is trigger visible: ${rect.top < window.innerHeight && rect.bottom > 0}`);
                            console.log(`📏 Distance to trigger: ${Math.round(rect.top - window.innerHeight)} pixels`);
                        }, 50);
                    } else {
                        console.log('⚠️ No observer or trigger element found for re-observation!');
                    }

                    // Set timestamp to prevent immediate re-triggering after layout changes
                    gallery._lastReinit = Date.now();
                    console.log('🏁 Gallery reinitialization complete');
                }
            }, 100);

        } catch (error) {
            console.error('Failed to load album pages:', error);
            // Remove loading indicator on error
            if (loadingIndicator && loadingIndicator.parentNode) {
                loadingIndicator.remove();
            }
        } finally {
            // Always reset loading flag
            gallery._flickrLoading = false;

            // Ensure any orphaned loading indicators are removed
            const orphanedIndicators = gallery.querySelectorAll('.flickr-loading-indicator');
            orphanedIndicators.forEach(indicator => indicator.remove());
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

            // Add new photos to the gallery (only if we have photos)
            if (result.photos && result.photos.length > 0) {
                result.photos.forEach(photoData => {
                    // Validate photo data before creating card
                    if (!photoData || !photoData.image_url) {
                        console.warn('Invalid photo data received:', photoData);
                        return;
                    }

                    const card = createPhotoCard(photoData, gallery);
                    if (card) {
                        // Insert before the lazy trigger
                        const trigger = gallery.querySelector('.flickr-lazy-trigger');
                        if (trigger) {
                            console.log('📍 Inserting new photo card before trigger');
                            gallery.insertBefore(card, trigger);
                        } else {
                            console.log('⚠️ No trigger found, appending card to end');
                            gallery.appendChild(card);
                        }
                    }
                });
            }

            // Update set metadata
            setData.current_page = result.page;
            setData.loaded_photos = (setData.loaded_photos || 0) + result.photos.length;
            setData.isLoading = false;

            console.log(`📊 Updated set metadata: current_page=${setData.current_page}, total_pages=${setData.total_pages}, loaded_photos=${setData.loaded_photos}`);

            // Update the data attribute on the gallery with all set metadata
            gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));
            console.log('💾 Updated DOM with new metadata');

        } catch (error) {
            console.error(`Failed to load page ${nextPage} for set ${setData.photoset_id}:`, error);

            // Implement retry logic for temporary network errors
            if (!setData.retryCount) setData.retryCount = 0;

            if (setData.retryCount < 2 && (error.name === 'AbortError' || error.message.includes('network'))) {
                // Retry after delay for network errors
                setData.retryCount++;
                setData.isLoading = false;

                setTimeout(() => {
                    if (setData.retryCount <= 2) {
                        console.log(`Retrying page ${nextPage} for set ${setData.photoset_id} (attempt ${setData.retryCount + 1})`);
                        loadSetPage(gallery, setData, setMetadata);
                    }
                }, 2000 * setData.retryCount); // Exponential backoff
            } else {
                // Max retries reached or non-recoverable error
                setData.loadingError = true;
                setData.isLoading = false;
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

        // Get the gallery ID from the existing gallery structure
        const existingItems = gallery.querySelectorAll('.flickr-builtin-lightbox[data-gallery]');
        const galleryId = existingItems.length > 0 ?
            existingItems[0].getAttribute('data-gallery') :
            'flickr-gallery-' + Date.now();
        link.setAttribute('data-gallery', galleryId);

        // Add dimensions for lightbox (required for proper lightbox functionality)
        if (photoData.width && photoData.height) {
            link.setAttribute('data-width', photoData.width);
            link.setAttribute('data-height', photoData.height);
        }

        // Add Flickr attribution attributes to match server-side structure
        if (photoData.is_flickr && photoData.flickr_page) {
            link.setAttribute('data-flickr-page', photoData.flickr_page);
            link.setAttribute('data-flickr-attribution-text', 'View on Flickr');

            // Add additional lightbox caption attributes (matches server-side)
            link.setAttribute('data-caption', 'View on Flickr');
            link.setAttribute('data-title', 'View on Flickr');
            link.setAttribute('title', 'View on Flickr');
        }

        const img = document.createElement('img');
        img.src = photoData.image_url;
        img.alt = '';
        img.loading = 'lazy';
        img.setAttribute('decoding', 'async'); // Match server-side attributes

        link.appendChild(img);
        card.appendChild(link);

        return card;
    }
}

