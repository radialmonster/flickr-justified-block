/**
 * Flickr Justified Gallery - Lazy Loading Module
 *
 * Handles infinite scroll lazy loading for album galleries.
 * Uses Intersection Observer to detect when user scrolls near bottom,
 * then fetches and adds more photos progressively.
 */

(function() {
    'use strict';

    // Constants
    const SORT_VIEWS_DESC = 'views_desc';

    // Helper to access utilities
    const helpers = window.flickrJustified?.helpers || {};
    const { getLoadedCount, setLoadedCount, getPhotoLimit, maintainLoadingIndicator, createLoadingIndicatorElement } = helpers;

    function initFlickrAlbumLazyLoading() {
        'use strict';

        // Helper visible to all inner functions
        function getLastImageInGallery(gallery) {
            const cards = gallery.querySelectorAll(':scope > .flickr-justified-row .flickr-justified-card, :scope > .flickr-justified-card');
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
            const hasSetMetadata = gallery.hasAttribute('data-set-metadata');
            console.log(`Gallery ${index + 1}:`, {
                id: gallery.id,
                hasSetMetadata,
                attributes: attributes.filter(a => a.startsWith('data-'))
            });
        });

        if (galleriesWithSets.length === 0) {
            console.log('⏭️ No galleries with set metadata found - skipping lazy loading setup');
            return;
        }

        console.log(`👁️ Setting up lazy loading for ${galleriesWithSets.length} galleries`);

        galleriesWithSets.forEach((gallery, index) => {
            console.log(`🎯 Initializing lazy loading for gallery ${index + 1}:`, gallery.id);

            // Parse set metadata
            const setMetadataAttr = gallery.getAttribute('data-set-metadata');
            if (!setMetadataAttr) {
                console.warn('Gallery has data-set-metadata attribute but it\'s empty');
                return;
            }

            let setMetadata;
            try {
                setMetadata = JSON.parse(setMetadataAttr);
            } catch (e) {
                console.error('Failed to parse set metadata:', e);
                return;
            }

            if (!Array.isArray(setMetadata) || setMetadata.length === 0) {
                console.log('Set metadata is empty or invalid');
                return;
            }

            console.log(`📊 Loaded set metadata for ${setMetadata.length} photosets:`, setMetadata);

            // Set up intersection observer
            function observeLastImage() {
                if (gallery._flickrLazyObserver) {
                    gallery._flickrLazyObserver.disconnect();
                }

                const lastImage = getLastImageInGallery(gallery);
                if (!lastImage) {
                    console.log('No last image found for intersection observer');
                    return;
                }

                gallery._flickrLazyObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            console.log('🔄 Last image visible - triggering loadNextPages');
                            loadNextPages(gallery, setMetadata);
                        }
                    });
                }, { rootMargin: '500px' });

                gallery._flickrLazyObserver.observe(lastImage);
                gallery._lastObservedImage = lastImage;
                console.log('👁️ Observing last image for lazy loading');
            }

            observeLastImage();
        });

        async function loadNextPages(gallery, originalSetMetadata) {
            // Prevent concurrent loading
            if (gallery._flickrLoading) {
                console.log('⏸️ Already loading, skipping');
                return;
            }

            // Cooldown check
            const now = Date.now();
            const lastReinit = gallery._lastReinit || 0;
            const timeSinceLastReinit = now - lastReinit;

            if (timeSinceLastReinit < 2000) {
                console.log('🧊 Cooldown active, skipping load');
                return;
            }

            // Photo limit check
            const photoLimit = getPhotoLimit(gallery);
            const loadedBefore = getLoadedCount(gallery);

            if (photoLimit > 0 && loadedBefore >= photoLimit) {
                console.log('🧮 Photo limit reached, stopping lazy loading');
                const obs = gallery._flickrLazyObserver;
                if (obs) {
                    obs.disconnect();
                    console.log('👁️ Disconnected intersection observer - limit reached');
                }
                return;
            }

            gallery._flickrLoading = true;
            console.log('🔄 loadNextPages triggered by scroll');

            const currentMetadataAttr = gallery.getAttribute('data-set-metadata');
            let setMetadata = originalSetMetadata;
            if (currentMetadataAttr) {
                try {
                    setMetadata = JSON.parse(currentMetadataAttr);
                } catch (e) {
                    console.error('Failed to parse current metadata:', e);
                    setMetadata = originalSetMetadata;
                }
            }

            const pendingSets = setMetadata.filter(set => !set.loadingError && set.current_page < set.total_pages);

            if (pendingSets.length === 0) {
                console.log('✅ All pages loaded or errored');
                gallery._flickrLoading = false;
                return;
            }

            console.log(`📄 Loading next pages for ${pendingSets.length} sets`);

            let loadingIndicator = gallery.querySelector('.flickr-loading-more');
            const indicatorNodeBeforeReinit = loadingIndicator;
            const indicatorWasPersisting = loadingIndicator?.dataset?.shouldPersist === 'true';
            const baseLoadingMessage = '⏳ Loading more photos...';
            let indicatorMessage = baseLoadingMessage;
            let indicatorShouldPersist = false;
            let shouldRemoveIndicator = false;
            let scheduledRetryDelay = null;

            try {
                const results = await Promise.all(
                    pendingSets.map(setData => loadSetPage(gallery, setData, setMetadata))
                );

                const hasSuccess = results.some(result => result && result.status === 'success');
                const recoverableResults = results.filter(result => result && result.status === 'recoverable-error');
                const hasRecoverable = recoverableResults.length > 0;
                const pendingSets = setMetadata.some(set => !set.loadingError && set.current_page < set.total_pages);

                if (hasSuccess) {
                    // Simplified reinitialization: browser handles scroll anchoring automatically
                    // No manual anchor tracking needed - CSS overflow-anchor does this for free
                    console.log('🔄 Reinitializing gallery with new photos...');

                    // Stop observing the old last image before reinitialization
                    const observer = gallery._flickrLazyObserver;
                    if (observer && gallery._lastObservedImage) {
                        observer.unobserve(gallery._lastObservedImage);
                    }

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
                    console.log('📐 Reinitializing gallery layout...');

                    // Move existing cards out of row wrappers
                    gallery.querySelectorAll(':scope > .flickr-justified-row').forEach(row => {
                        row.querySelectorAll(':scope > .flickr-justified-card').forEach(card =>
                            gallery.appendChild(card)
                        );
                        row.remove();
                    });

                    gallery.querySelector('.flickr-staging')?.remove();

                    // Add new photos
                    if (gallery._pendingPhotos?.length > 0) {
                        gallery._pendingPhotos.forEach(photoData => {
                            const card = createPhotoCard(photoData, gallery);
                            if (card) gallery.appendChild(card);
                        });
                        delete gallery._pendingPhotos;
                    }

                    // Sort if needed
                    const sortOrder = gallery.dataset.sortOrder || 'input';
                    if (sortOrder === SORT_VIEWS_DESC) {
                        const cards = Array.from(gallery.querySelectorAll('.flickr-justified-card'));
                        cards.sort((a, b) => {
                            const viewsDiff = parseInt(b.dataset.views || '0', 10) -
                                            parseInt(a.dataset.views || '0', 10);
                            if (viewsDiff !== 0) return viewsDiff;
                            return parseInt(a.dataset.position || '0', 10) -
                                   parseInt(b.dataset.position || '0', 10);
                        });
                        cards.forEach(card => gallery.appendChild(card));
                    }

                    setLoadedCount(gallery, gallery.querySelectorAll('.flickr-justified-card').length);

                    // Rebuild layout - browser will handle scroll anchoring
                    gallery.classList.remove('justified-initialized');
                    if (window.flickrJustified?.initGallery) {
                        window.flickrJustified.initGallery();
                    }

                    // Re-observe new last image for lazy loading
                    setTimeout(() => {
                        const obs = gallery._flickrLazyObserver;
                        if (obs) {
                            const newLastImage = getLastImageInGallery(gallery);
                            if (newLastImage) {
                                obs.observe(newLastImage);
                                gallery._lastObservedImage = newLastImage;
                            }
                        }
                    }, 100);

                    // Notify PhotoSwipe
                    document.dispatchEvent(new CustomEvent('flickr-gallery-updated', {
                        detail: { gallery }
                    }));

                    gallery._lastReinit = Date.now();
                    console.log('✅ Gallery reinitialization complete');
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

                loadingIndicator = helpers.maintainLoadingIndicator({
                    gallery,
                    loadingIndicator,
                    baseLoadingMessage,
                    indicatorMessage,
                    shouldPersist: indicatorShouldPersist || indicatorWasPersisting,
                    shouldRemoveIndicator,
                    createIndicator: () => helpers.createLoadingIndicatorElement(baseLoadingMessage),
                    fallbackIndicator: indicatorNodeBeforeReinit
                });
            }
        }

        async function loadSetPage(gallery, setData, setMetadata) {
            setData.isLoading = true;
            const nextPage = setData.current_page + 1;

            console.log(`Loading page ${nextPage} for set ${setData.photoset_id}`);

            try {
                // Get REST API URL from localized script (required - no hardcoded fallback)
                if (typeof flickrJustifiedRest === 'undefined' || !flickrJustifiedRest.url) {
                    console.error('Flickr Justified Block: REST API URL not configured. Album lazy loading will not work.');
                    throw new Error('REST API URL not configured');
                }

                const sortOrder = gallery.dataset.sortOrder || 'input';
                const photoLimit = getPhotoLimit(gallery);
                const loadedBefore = getLoadedCount(gallery);

                const response = await fetch(flickrJustifiedRest.url + '/load-album-page', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: setData.user_id,
                        photoset_id: setData.photoset_id,
                        page: nextPage,
                        sort_order: sortOrder,
                        max_photos: photoLimit,
                        loaded_count: loadedBefore
                    }),
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                setData.isLoading = false;

                if (data.photos && data.photos.length > 0) {
                    console.log(`✅ Loaded ${data.photos.length} photos from page ${nextPage}`);

                    if (!gallery._pendingPhotos) {
                        gallery._pendingPhotos = [];
                    }
                    gallery._pendingPhotos.push(...data.photos);

                    setData.current_page = nextPage;

                    if (nextPage >= setData.total_pages || !data.has_more) {
                        console.log(`🏁 Reached last page (${nextPage}/${setData.total_pages}) for set ${setData.photoset_id}`);
                    }

                    gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));

                    return { status: 'success', photos: data.photos };
                } else {
                    console.log(`ℹ️ No more photos returned for page ${nextPage}`);
                    setData.current_page = setData.total_pages;
                    gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));
                    return { status: 'success', photos: [] };
                }
            } catch (error) {
                setData.isLoading = false;
                console.error(`Failed to load page ${nextPage}:`, error);

                if (error.message.includes('429') || error.message.includes('rate limit')) {
                    const retryAfter = 5000;
                    console.warn(`Rate limited - will retry after ${retryAfter}ms`);
                    return {
                        status: 'recoverable-error',
                        error: error.message,
                        retryDelay: retryAfter,
                        message: '⏸️ Rate limited - retrying in a moment...'
                    };
                }

                setData.loadingError = true;
                gallery.setAttribute('data-set-metadata', JSON.stringify(setMetadata));
                return { status: 'error', error: error.message };
            }
        }

        function createPhotoCard(photoData, gallery) {
            const card = document.createElement('article');
            card.className = 'flickr-justified-card';

            const position = photoData.position || 0;
            const views = photoData.views || 0;
            const comments = photoData.comments || 0;
            const favorites = photoData.favorites || 0;

            card.setAttribute('data-position', position);
            card.setAttribute('data-views', views);
            card.setAttribute('data-comments', comments);
            card.setAttribute('data-favorites', favorites);

            if (photoData.width && photoData.height) {
                card.setAttribute('data-width', photoData.width);
                card.setAttribute('data-height', photoData.height);
            }

            if (photoData.rotation) {
                card.setAttribute('data-rotation', photoData.rotation);
            }

            const blockId = gallery.id;
            const lightboxClass = 'flickr-builtin-lightbox';
            const attributionText = gallery.getAttribute('data-attribution-text') || 'Flickr';

            const anchor = document.createElement('a');
            anchor.href = photoData.lightbox_url || photoData.url;
            anchor.className = lightbox_class;
            anchor.setAttribute('data-gallery', blockId);

            if (photoData.lightbox_width && photoData.lightbox_height) {
                anchor.setAttribute('data-width', photoData.lightbox_width);
                anchor.setAttribute('data-height', photoData.lightbox_height);
            }

            if (photoData.rotation) {
                anchor.setAttribute('data-rotation', photoData.rotation);
            }

            anchor.setAttribute('data-flickr-page', photoData.attribution_url || photoData.url);
            anchor.setAttribute('data-flickr-attribution-text', attributionText);
            anchor.setAttribute('data-caption', attributionText);
            anchor.setAttribute('data-title', attributionText);
            anchor.title = attributionText;

            const img = document.createElement('img');
            img.src = photoData.url;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.alt = '';

            if (photoData.width && photoData.height) {
                img.setAttribute('data-width', photoData.width);
                img.setAttribute('data-height', photoData.height);
            }

            if (photoData.rotation) {
                img.setAttribute('data-rotation', photoData.rotation);
                img.style.transform = `rotate(${photoData.rotation}deg)`;
                img.style.transformOrigin = 'center center';
            }

            anchor.appendChild(img);
            card.appendChild(anchor);

            return card;
        }
    }

    // ============================================================================
    // EXPOSE API
    // ============================================================================

    window.flickrJustified = window.flickrJustified || {};
    window.flickrJustified.initAlbumLazyLoading = initFlickrAlbumLazyLoading;

    console.log('Flickr Justified Gallery: Lazy loading module loaded');
})();
