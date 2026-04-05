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
    const MAX_RENDERED_IDS = 10000; // Cap for renderedPhotoIds Set to prevent memory growth

    /**
     * Performance note: Queue uses Array.shift() which is O(n).
     * At 10k cap, this is ~10k operations per shift when at capacity.
     * This is acceptable for typical usage, but if you see performance issues,
     * consider a circular buffer or deque for O(1) eviction.
     */

    // ============================================================================
    // STATE MANAGEMENT - WeakMap-based (parse once, never stringify)
    // ============================================================================

    /**
     * Centralized state storage: one WeakMap for all galleries
     * @type {WeakMap<Element, GalleryState>}
     */
    const galleryStates = new WeakMap();

    /**
     * @typedef {Object} GalleryState
     * @property {Array} setMetadata - Parsed photoset metadata (parse once!)
     * @property {boolean} hasMore - Explicit flag: are there more pages to load?
     * @property {boolean} isLoading - Guard against concurrent loads
     * @property {number} lastRequestId - Token to ignore stale responses
     * @property {AbortController|null} abortController - Cancel in-flight fetches
     * @property {Set<string>} renderedPhotoIds - De-dupe Set (NEVER reassign!)
     * @property {Array<string>} renderedPhotoQueue - Insertion order queue for capping
     * @property {Array} pendingPhotos - Photos waiting to be added to gallery
     * @property {IntersectionObserver|null} io - Scroll observer
     * @property {boolean} observerTriggered - Latch to prevent observer spam
     * @property {number} lastObserverFire - Throttle observer callback spam
     * @property {number} lastReinit - Cooldown timestamp
     * @property {Array<Function>} cleanup - Teardown functions
     * @property {Object|null} lastError - Error state for backoff
     * @property {number|null} retryAt - Timestamp for next retry
     * @property {number|null} retryTimer - Timeout ID for scheduled retry
     * @property {number} failCount - Consecutive failures for backoff
     * @property {boolean} initialized - Idempotency guard
     * @property {MutationObserver|null} mutationObserver - Watches for cards to arrive
     * @property {number|null} mutationObserverTimeout - Timeout to prevent watching forever
     */

    /**
     * Get existing state (throws if not initialized)
     */
    function getState(gallery) {
        const state = galleryStates.get(gallery);
        if (!state) {
            throw new Error('Gallery state not initialized. Call getOrInitState first.');
        }
        return state;
    }

    /**
     * Get or create state (idempotent initialization)
     * NOTE: Does NOT set initialized=true - that's done in initGallery after setup succeeds
     */
    function getOrInitState(gallery, initFn) {
        let state = galleryStates.get(gallery);
        if (!state) {
            state = initFn();
            // Don't set initialized here - wait until setup completes
            galleryStates.set(gallery, state);
        }
        return state;
    }

    /**
     * Create initial state from DOM attributes (parse once!)
     */
    function createInitialState(gallery) {
        const setMetadataAttr = gallery.getAttribute('data-set-metadata');
        if (!setMetadataAttr) {
            return {
                disabled: true,
                initialized: true,
                setMetadata: [],
                hasMore: false,
                isLoading: false,
                lastRequestId: 0,
                abortController: null,
                renderedPhotoIds: new Set(),
                renderedPhotoQueue: [],
                pendingPhotos: [],
                io: null,
                observerTriggered: false,
                lastObserverFire: 0,
                lastReinit: 0,
                cleanup: [],
                lastError: 'missing_metadata',
                retryAt: null,
                retryTimer: null,
                failCount: 0,
                initialized: true,
                mutationObserver: null,
                mutationObserverTimeout: null,
                galleryHeight: 0,
            };
        }

        let setMetadata;
        try {
            setMetadata = JSON.parse(setMetadataAttr);
        } catch (e) {
            throw new Error(`Failed to parse set metadata: ${e.message}`);
        }

        if (!Array.isArray(setMetadata) || setMetadata.length === 0) {
            throw new Error('Set metadata is empty or invalid');
        }

        // Check if any sets have more pages to load
        const hasMore = setMetadata.some(set => set.current_page < set.total_pages);

        return {
            setMetadata,
            hasMore,  // Explicit boolean - easier to check than implicit logic
            isLoading: false,
            lastRequestId: 0,
            abortController: null,
            renderedPhotoIds: new Set(),  // NEVER reassign this Set!
            renderedPhotoQueue: [],  // Insertion order queue for capping
            pendingPhotos: [],  // Store in state, not on DOM element
            io: null,
            observerTriggered: false,  // Latch to prevent observer spam
            lastObserverFire: 0,
            lastReinit: 0,
            cleanup: [],
            lastError: null,
            retryAt: null,
            retryTimer: null,
            failCount: 0,
            initialized: false,
            mutationObserver: null,  // Watches for cards to arrive
            mutationObserverTimeout: null,  // Timeout to prevent watching forever
            galleryHeight: 0,
        };
    }

    /**
     * Teardown gallery (disconnect observers, remove listeners, clear state)
     */
    function destroyGallery(gallery) {
        const state = galleryStates.get(gallery);
        if (!state) return;

        // Disconnect observers
        if (state.io) {
            state.io.disconnect();
            state.io = null;
        }

        // Disconnect mutation observer
        if (state.mutationObserver) {
            state.mutationObserver.disconnect();
            state.mutationObserver = null;
        }

        // Clear mutation observer timeout
        if (state.mutationObserverTimeout) {
            clearTimeout(state.mutationObserverTimeout);
            state.mutationObserverTimeout = null;
        }

        // Abort in-flight requests
        if (state.abortController) {
            state.abortController.abort();
            state.abortController = null;
        }

        // Clear retry timer
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }

        // Run cleanup functions (event listeners, etc.)
        state.cleanup.forEach(fn => {
            try {
                fn();
            } catch (e) {
                console.error('Cleanup error:', e);
            }
        });

        // Remove from WeakMap (optional - will be GC'd anyway)
        galleryStates.delete(gallery);

        console.log('🧹 Gallery destroyed:', gallery.id);
    }

    /**
     * Dynamically resolve helpers - prevents stale reference if helpers load late/reload
     *
     * Resolves helpers at call time instead of module load time, ensuring we always
     * get the current helpers object even if justified-helpers.js loads after this file.
     */
    function getHelpersOrThrow() {
        const h = window.flickrJustified?.helpers;
        const required = ['getLoadedCount', 'setLoadedCount', 'getPhotoLimit'];
        const missing = required.filter(name => typeof h?.[name] !== 'function');

        if (missing.length) {
            const error =
                `Flickr Justified Block: Required helper functions missing: ${missing.join(', ')}. ` +
                `Ensure justified-helpers.js is loaded before justified-lazy-loading.js`;
            console.error(error);
            throw new Error(error);
        }
        return h;
    }

    // ============================================================================
    // HELPERS
    // ============================================================================

    /**
     * Stop lazy loading permanently - disconnect observer and clear all operational state
     * Use this for early returns BEFORE active loading starts
     */
    function stopLazyLoading(state) {
        state.hasMore = false;
        state.isLoading = false;
        state.observerTriggered = false;
        if (state.io) {
            state.io.disconnect();
            state.io = null;
        }
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }
        state.retryAt = null;
    }

    /**
     * Stop future loads but defer clearing in-flight flags (isLoading, observerTriggered)
     * Use this DURING active loading to prevent reentrancy - finally will reset flags safely
     */
    function stopLazyLoadingDeferFlags(state) {
        state.hasMore = false;
        if (state.io) {
            state.io.disconnect();
            state.io = null;
        }
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }
        state.retryAt = null;
    }

    function getLastImageInGallery(gallery) {
        const cards = gallery.querySelectorAll(':scope > .flickr-justified-row .flickr-justified-card, :scope > .flickr-justified-card');
        if (!cards.length) return null;
        const lastCard = cards[cards.length - 1];
        return lastCard.querySelector('img');
    }

    /**
     * Initialize a single gallery (idempotent)
     */
    function initGallery(gallery) {
        // Idempotent: Don't re-initialize if already set up
        const existingState = galleryStates.get(gallery);
        if (existingState && existingState.initialized) {
            console.log('Gallery already initialized, skipping:', gallery.id);
            return;
        }

        console.log(`🎯 Initializing lazy loading for gallery:`, gallery.id);

        try {
            // Parse once, store in WeakMap (state not marked initialized yet)
            const state = getOrInitState(gallery, () => createInitialState(gallery));
            console.log(`📊 Loaded set metadata for ${state.setMetadata.length} photosets`);

            // Set up intersection observer (stored in state)
            const observerSetup = setupIntersectionObserver(gallery, state);

            // Mark as fully initialized ONLY if setup succeeded
            // This ensures failed setups (no images yet) can be retried on next initGallery call
            if (observerSetup) {
                state.initialized = true;
                console.log(`✅ Gallery ${gallery.id} fully initialized`);
            } else {
                console.log(`⏸️ Gallery ${gallery.id} setup incomplete - waiting for cards to arrive`);

                // Only set up MutationObserver if we don't already have one
                if (!state.mutationObserver) {
                    state.mutationObserver = new MutationObserver((mutations) => {
                        for (const mutation of mutations) {
                            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                                // Check if any added node is a card
                                for (const node of mutation.addedNodes) {
                                    if (node.nodeType === Node.ELEMENT_NODE &&
                                        (node.classList?.contains('flickr-justified-card') ||
                                         node.querySelector?.('.flickr-justified-card'))) {
                                        console.log(`🔄 Cards detected in ${gallery.id}, retrying initialization`);

                                        // Clean up observer and timeout
                                        state.mutationObserver.disconnect();
                                        state.mutationObserver = null;
                                        if (state.mutationObserverTimeout) {
                                            clearTimeout(state.mutationObserverTimeout);
                                            state.mutationObserverTimeout = null;
                                        }

                                        initGallery(gallery);
                                        return;
                                    }
                                }
                            }
                        }
                    });

                    state.mutationObserver.observe(gallery, { childList: true, subtree: true });

                    // Set timeout to prevent watching forever (30s)
                    state.mutationObserverTimeout = setTimeout(() => {
                        if (state.mutationObserver) {
                            console.warn(`⚠️ Gallery ${gallery.id}: No cards arrived after 30s, stopping MutationObserver`);
                            state.mutationObserver.disconnect();
                            state.mutationObserver = null;
                            state.mutationObserverTimeout = null;
                        }
                    }, 30000);

                    console.log(`👁️ MutationObserver set up for ${gallery.id} (30s timeout)`);
                } else {
                    console.log(`👁️ MutationObserver already watching ${gallery.id}`);
                }
            }

        } catch (error) {
            console.error(`Failed to initialize gallery ${gallery.id}:`, error);
            // Don't set initialized=true on error, allowing retry
        }
    }

    /**
     * Set up or update intersection observer for gallery
     * @returns {boolean} true if successfully observing, false if setup failed
     */
    function setupIntersectionObserver(gallery, state) {
        // Disconnect existing observer if any
        if (state.io) {
            state.io.disconnect();
            state.io = null;
        }

        const lastImage = getLastImageInGallery(gallery);
        if (!lastImage) {
            console.log('⚠️ No last image found for intersection observer - cannot set up lazy loading');
            return false; // ✅ Return false on failure
        }

        // Create new observer and store in state (not on element)
        state.io = new IntersectionObserver((entries) => {
            const now = Date.now();
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;

                // Throttle observer spam while sentinel stays visible (300ms debounce)
                if (now - state.lastObserverFire < 300) continue;
                state.lastObserverFire = now;

                // Use latch to prevent observer spam while sentinel is visible
                // Safe because latch is only set inside loadNextPages AFTER all guards pass
                if (state.observerTriggered) continue;

                console.log('🔄 Last image visible - triggering loadNextPages');
                loadNextPages(gallery);
            }
        }, { rootMargin: '500px' });

        state.io.observe(lastImage);
        console.log('👁️ Observing last image for lazy loading');
        return true; // ✅ Return true on success
    }

    /**
     * Initialize all galleries in a root element (default: document)
     * Supports dynamic DOM insertion
     */
    function initAllGalleries(root = document) {
        // Validate helpers before initializing any galleries (fail fast)
        getHelpersOrThrow();

        const galleriesWithSets = root.querySelectorAll('.flickr-justified-grid[data-set-metadata]');

        if (galleriesWithSets.length === 0) {
            console.log('⏭️ No galleries with set metadata found');
            return;
        }

        console.log(`👁️ Setting up lazy loading for ${galleriesWithSets.length} galleries`);
        galleriesWithSets.forEach(gallery => initGallery(gallery));
    }

    // ============================================================================
    // PAGINATION & LOADING (Module-level scope for IntersectionObserver access)
    // ============================================================================

    async function loadNextPages(gallery) {
            const state = getState(gallery);

            // Concurrency guard: prevent double-loading
            if (state.isLoading) {
                console.log('⏸️ Already loading, skipping');
                return;
            }

            // Cooldown check (prevent rapid-fire requests)
            const now = Date.now();
            const timeSinceLastReinit = now - state.lastReinit;
            if (timeSinceLastReinit < 2000) {
                console.log('🧊 Cooldown active, skipping load');
                return;
            }

            // Error backoff: check if we should retry yet
            if (state.retryAt && now < state.retryAt) {
                console.log('⏸️ In backoff period, skipping load');
                return;
            }

            // Explicit hasMore check (early exit if no more pages)
            if (!state.hasMore) {
                console.log('✅ No more pages to load');
                stopLazyLoading(state);
                return;
            }

            // Photo limit check
            const helpers = getHelpersOrThrow();
            const photoLimit = helpers.getPhotoLimit(gallery);
            // Use DOM count as source of truth (always accurate, no timing issues with helper updates)
            const loadedBefore = gallery.querySelectorAll('.flickr-justified-card').length;
            if (photoLimit > 0 && loadedBefore >= photoLimit) {
                console.log('🧮 Photo limit reached, stopping lazy loading');
                stopLazyLoading(state);
                return;
            }

            // Find sets that still have pages to load
            const pendingSets = state.setMetadata.filter(set => !set.loadingError && set.current_page < set.total_pages);

            if (pendingSets.length === 0) {
                console.log('✅ All pages loaded or errored');
                stopLazyLoading(state);
                return;
            }

            // Set loading flag and latch (after all guards pass)
            // Latch prevents observer spam while sentinel is visible
            state.observerTriggered = true;
            state.isLoading = true;
            state.lastRequestId++;
            const thisRequestId = state.lastRequestId;

            // Track whether we reinit or schedule retry (for latch safety reset)
            let didReinit = false;
            let scheduledRetry = false;
            let skipHasMoreRecalc = false;  // Track if we should skip hasMore recalculation (proactive stops)

            // Define reinitializeGallery once per loadNextPages call (closes over gallery/state)
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

                // Add new photos from state (not DOM)
                if (state.pendingPhotos.length > 0) {
                    state.pendingPhotos.forEach(photoData => {
                        const card = createPhotoCard(photoData, gallery);
                        if (card) gallery.appendChild(card);
                    });
                    state.pendingPhotos = []; // Clear after adding
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

                const h = getHelpersOrThrow();
                h.setLoadedCount(gallery, gallery.querySelectorAll('.flickr-justified-card').length);

                // Rebuild layout - browser will handle scroll anchoring
                gallery.classList.remove('justified-initialized');
                if (window.flickrJustified?.initGallery) {
                    window.flickrJustified.initGallery();
                }

                // Reset latch and re-setup intersection observer for new last image
                // Use double requestAnimationFrame for deterministic DOM/layout timing
                state.observerTriggered = false;
                if (state.hasMore) {
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            setupIntersectionObserver(gallery, state);
                        });
                    });
                }

                // Notify PhotoSwipe
                document.dispatchEvent(new CustomEvent('flickr-gallery-updated', {
                    detail: { gallery }
                }));

                state.lastReinit = Date.now();
                console.log('✅ Gallery reinitialization complete');
            }

            // Abort any existing request
            if (state.abortController) {
                state.abortController.abort();
            }
            state.abortController = new AbortController();

            console.log(`📄 Loading next pages for ${pendingSets.length} sets (request #${thisRequestId})`);

            let loadingIndicator = gallery.querySelector('.flickr-loading-more');
            const indicatorNodeBeforeReinit = loadingIndicator;
            const indicatorWasPersisting = loadingIndicator?.dataset?.shouldPersist === 'true';
            const baseLoadingMessage = '⏳ Loading more photos...';
            let indicatorMessage = baseLoadingMessage;
            let indicatorShouldPersist = false;
            let shouldRemoveIndicator = false;
            let scheduledRetryDelay = null;

            try {
                // Check if request is stale
                if (thisRequestId !== state.lastRequestId) {
                    console.log(`⏭️ Request #${thisRequestId} superseded by #${state.lastRequestId}, ignoring`);
                    return;
                }

                // Sequential fetching to enforce photoLimit globally
                // (concurrent fetching would cause all sets to race with same loadedBefore)
                const results = [];
                for (const setData of pendingSets) {
                    // Check remaining capacity before each fetch
                    if (photoLimit > 0) {
                        const currentLoaded = loadedBefore + state.pendingPhotos.length;
                        if (currentLoaded >= photoLimit) {
                            console.log(`🛑 Photo limit reached (${currentLoaded}/${photoLimit}), stopping lazy loading`);
                            skipHasMoreRecalc = true;
                            stopLazyLoadingDeferFlags(state);
                            break;
                        }
                    }

                    // Check if request is still current
                    if (thisRequestId !== state.lastRequestId) {
                        console.log(`⏭️ Request #${thisRequestId} superseded during sequential fetch`);
                        break;
                    }

                    const result = await loadSetPage(gallery, setData, state, thisRequestId, photoLimit, loadedBefore);
                    results.push(result);

                    // Stop if we hit a non-retryable error
                    if (result?.noRetry) {
                        console.log(`🛑 Non-retryable error from set ${setData.photoset_id}, stopping fetch loop`);
                        break;
                    }
                }

                // Double-check request is still current
                if (thisRequestId !== state.lastRequestId) {
                    console.log(`⏭️ Request #${thisRequestId} superseded, ignoring results`);
                    return;
                }

                const hasSuccess = results.some(result => result && result.status === 'success');
                const recoverableResults = results.filter(result => result && result.status === 'recoverable-error');
                const hasRecoverable = recoverableResults.length > 0;
                const loadedNewPhotos = results.some(r => r?.status === 'success' && (r.photos?.length || 0) > 0);
                const allCancelled = results.length > 0 && results.every(r => r?.status === 'cancelled');

                // Check for non-retryable errors FIRST, regardless of state.hasMore
                // This ensures auth expiry messages always show even if hasMore is false
                const noRetryResult = recoverableResults.find(r => r?.noRetry);

                if (noRetryResult) {
                    indicatorShouldPersist = true;
                    indicatorMessage = noRetryResult.message || '⚠️ Authorization expired. Please refresh the page.';
                    scheduledRetryDelay = null;

                    // Make stop resilient - mark all sets as errored
                    state.setMetadata.forEach(set => { set.loadingError = true; });

                    // Prevent any later recompute from resurrecting hasMore
                    skipHasMoreRecalc = true;

                    // Hard stop during active load
                    stopLazyLoadingDeferFlags(state);

                    // Flush any photos we already successfully fetched before the error
                    if (state.pendingPhotos.length > 0) {
                        didReinit = true;
                        reinitializeGallery();
                    }

                    console.log(`⚠️ Non-retryable error: ${noRetryResult.error} - user must refresh`);
                } else {
                    if (allCancelled) {
                        // Nothing actually happened, do not show "success" or keep a spinner around
                        shouldRemoveIndicator = true;
                    }

                    // Update explicit hasMore flag based on metadata (skip if proactive stop already occurred)
                    if (!skipHasMoreRecalc) {
                        state.hasMore = state.setMetadata.some(set => !set.loadingError && set.current_page < set.total_pages);
                    }

                    // Disconnect observer if no more pages to load (skip if proactive stop already occurred)
                    if (!state.hasMore && !skipHasMoreRecalc) {
                        stopLazyLoadingDeferFlags(state);
                        console.log('👁️ Disconnected observer - no more pages to load');
                    }

                    if (loadedNewPhotos) {
                        // Reset error state on success
                        state.failCount = 0;
                        state.lastError = null;
                        state.retryAt = null;

                        // Clear any pending retry timer
                        if (state.retryTimer) {
                            clearTimeout(state.retryTimer);
                            state.retryTimer = null;
                        }

                        console.log('🔄 Reinitializing gallery with new photos...');

                        // Disconnect observer before reinit (will be reconnected after)
                        if (state.io) {
                            state.io.disconnect();
                            state.io = null;
                        }

                        didReinit = true;
                        reinitializeGallery();
                    } else if (hasSuccess && !allCancelled) {
                        // Success but no new photos (Flickr returned empty page)
                        // Reset error state but no need to reinit
                        state.failCount = 0;
                        state.lastError = null;
                        state.retryAt = null;

                        if (state.retryTimer) {
                            clearTimeout(state.retryTimer);
                            state.retryTimer = null;
                        }

                        console.log('✓ Load succeeded but no new photos returned');
                    }

                    if (skipHasMoreRecalc && photoLimit > 0 && loadedBefore + state.pendingPhotos.length >= photoLimit) {
                        // Limit reached: show completion message
                        indicatorShouldPersist = true;
                        indicatorMessage = '✅ Photo limit reached.';
                        shouldRemoveIndicator = false;
                        console.log('✅ Photo limit reached, lazy loading stopped');
                    } else if (hasRecoverable && state.hasMore) {
                        // Recoverable error: set backoff and schedule retry
                        state.failCount++;
                        const exponentialDelay = Math.min(5000 * Math.pow(2, state.failCount - 1), 30000); // Exponential backoff, max 30s

                        // Respect per-set retry delay suggestions (e.g., Retry-After from rate limits)
                        const suggestedDelay = Math.max(...recoverableResults.map(r => r.retryDelay || 0));
                        const backoffDelay = Math.max(exponentialDelay, suggestedDelay);

                        state.retryAt = Date.now() + backoffDelay;

                        indicatorShouldPersist = true;
                        const firstRecoverableMessage = recoverableResults.find(r => r.message)?.message;
                        indicatorMessage = firstRecoverableMessage || '⚠️ Temporary issue loading images. Retrying shortly...';
                        scheduledRetryDelay = backoffDelay;

                        console.log(`⚠️ Recoverable error, will retry in ${backoffDelay}ms (exponential: ${exponentialDelay}ms, suggested: ${suggestedDelay}ms, attempt ${state.failCount})`);
                    } else if (!state.hasMore) {
                        shouldRemoveIndicator = true;
                    } else if (hasSuccess && !hasRecoverable) {
                        shouldRemoveIndicator = true;
                    }

                    // Schedule retry for recoverable errors
                    if (indicatorShouldPersist && scheduledRetryDelay) {
                        // Clear any existing retry timer (prevents unbounded cleanup growth)
                        if (state.retryTimer) {
                            clearTimeout(state.retryTimer);
                        }
                        scheduledRetry = true;
                        state.retryTimer = setTimeout(() => {
                            state.retryTimer = null;
                            state.retryAt = null; // Clear backoff
                            loadNextPages(gallery);
                        }, scheduledRetryDelay);

                        // NOTE: No cleanup.push() needed - retryTimer is already cleared in:
                        // 1. destroyGallery()
                        // 2. On success (lines 329-332)
                        // 3. Here when replaced
                        // Pushing cleanup repeatedly would cause unbounded growth
                    } else if (shouldRemoveIndicator && state.retryTimer) {
                        clearTimeout(state.retryTimer);
                        state.retryTimer = null;
                    }
                }

            } catch (error) {
                // Don't count aborted requests as failures
                if (error.name === 'AbortError') {
                    console.log('⏭️ Request aborted, not counting as failure');
                    return; // Exit early, don't update error state
                }

                console.error('Failed to load album pages:', error);

                // Store error for debugging (real errors only)
                state.lastError = {
                    message: error.message,
                    timestamp: Date.now()
                };
                state.failCount++;

                const stillExpectingPages = state.setMetadata.some(set => !set.loadingError && set.current_page < set.total_pages);
                if (stillExpectingPages) {
                    // Set exponential backoff
                    const backoffDelay = Math.min(5000 * Math.pow(2, state.failCount - 1), 30000);
                    state.retryAt = Date.now() + backoffDelay;

                    indicatorShouldPersist = true;
                    indicatorMessage = '⚠️ Temporary issue loading images. Retrying shortly...';

                    console.log(`⚠️ Error, will retry in ${backoffDelay}ms (attempt ${state.failCount})`);
                } else {
                    if (state.retryTimer) {
                        clearTimeout(state.retryTimer);
                        state.retryTimer = null;
                    }
                    shouldRemoveIndicator = true;
                }
            } finally {
                // Always reset loading flag
                state.isLoading = false;

                // Safety reset: if we didn't reinit or schedule retry, clear the latch
                // (future-proof against early returns or hasMore becoming false)
                if (!didReinit && !scheduledRetry) {
                    state.observerTriggered = false;
                }

                // Guard against missing helper (prevents finally block from throwing)
                const h = window.flickrJustified?.helpers;
                if (typeof h?.maintainLoadingIndicator === 'function') {
                    loadingIndicator = h.maintainLoadingIndicator({
                        gallery,
                        loadingIndicator,
                        baseLoadingMessage,
                        indicatorMessage,
                        shouldPersist: !shouldRemoveIndicator && (indicatorShouldPersist || indicatorWasPersisting),
                        shouldRemoveIndicator,
                        createIndicator: () => h.createLoadingIndicatorElement?.(baseLoadingMessage),
                        fallbackIndicator: indicatorNodeBeforeReinit
                    });
                }
            }
    }

    async function loadSetPage(gallery, setData, state, thisRequestId, photoLimit, loadedBefore) {
            setData.isLoading = true;
            const nextPage = setData.current_page + 1;

            console.log(`Loading page ${nextPage} for set ${setData.photoset_id}`);

            try {
                // Check if request is still current before starting
                if (thisRequestId !== state.lastRequestId) {
                    console.log(`⏭️ Request superseded, skipping fetch`);
                    return { status: 'cancelled' };
                }

                // Get REST API URL from localized script (required - no hardcoded fallback)
                if (typeof flickrJustifiedRest === 'undefined' || !flickrJustifiedRest.url) {
                    console.error('Flickr Justified Block: REST API URL not configured. Album lazy loading will not work.');
                    throw new Error('REST API URL not configured');
                }

                const sortOrder = gallery.dataset.sortOrder || 'input';

                // Compute current loaded count including pending photos (for accurate server-side clamping)
                const loadedSoFar = loadedBefore + state.pendingPhotos.length;

                // Use AbortController for cancellable fetch
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
                        loaded_count: loadedSoFar
                    }),
                    signal: state.abortController?.signal
                });

                if (!response.ok) {
                    // Handle rate limiting with Retry-After header
                    if (response.status === 429) {
                        const retryAfterHeader = response.headers.get('Retry-After');
                        const seconds = parseInt((retryAfterHeader || '').trim(), 10);
                        const retryAfterMs = Number.isFinite(seconds) ? seconds * 1000 : 5000;
                        const clamped = Math.min(Math.max(retryAfterMs, 0), 30000);
                        return {
                            status: 'recoverable-error',
                            error: 'HTTP 429',
                            retryDelay: clamped,
                            message: '⏸️ Rate limited - retrying in a moment...'
                        };
                    }

                    // Handle auth expiry (nonce expiry, session timeout)
                    if (response.status === 401 || response.status === 403) {
                        return {
                            status: 'recoverable-error',
                            error: `HTTP ${response.status}`,
                            retryDelay: 0,
                            noRetry: true,
                            message: '⚠️ Authorization expired. Please refresh the page.'
                        };
                    }

                    // Handle 502/503/504 with optional Retry-After header
                    if (response.status === 502 || response.status === 503 || response.status === 504) {
                        const retryAfterHeader = response.headers.get('Retry-After');
                        const seconds = parseInt((retryAfterHeader || '').trim(), 10);
                        const retryAfterMs = Number.isFinite(seconds) ? seconds * 1000 : 5000;
                        const clamped = Math.min(Math.max(retryAfterMs, 0), 30000);
                        return {
                            status: 'recoverable-error',
                            error: `HTTP ${response.status}`,
                            retryDelay: clamped,
                            message: '⚠️ Server busy. Retrying shortly...'
                        };
                    }

                    // Treat other 5xx as transient/recoverable
                    if (response.status >= 500 && response.status < 600) {
                        return {
                            status: 'recoverable-error',
                            error: `HTTP ${response.status}`,
                            retryDelay: 5000,
                            message: '⚠️ Server error. Retrying shortly...'
                        };
                    }

                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                // Validate payload shape
                if (data && data.rate_limited) {
                    return {
                        status: 'recoverable-error',
                        error: 'Rate limited',
                        retryDelay: 5000,
                        message: '⏸️ Rate limited - retrying in a moment...'
                    };
                }

                if (!data || !Array.isArray(data.photos)) {
                    return {
                        status: 'recoverable-error',
                        error: 'Bad payload shape',
                        retryDelay: 5000,
                        message: '⚠️ Bad response payload. Retrying shortly...'
                    };
                }

                if (data.photos.length > 0) {
                    // 1) Compute candidates without mutating state (de-dupe check only)
                    const candidates = [];
                    for (const photo of data.photos) {
                        const photoId = photo.id || photo.url; // Use ID or URL as key
                        if (state.renderedPhotoIds.has(photoId)) {
                            console.log(`⏭️ Skipping duplicate photo: ${photoId}`);
                            continue;
                        }
                        candidates.push({ photo, photoId });
                    }

                    // 2) Clamp using loadedBefore (global count), not queue length
                    let accepted = candidates;
                    if (photoLimit > 0) {
                        const alreadyLoaded = loadedBefore + state.pendingPhotos.length;
                        const remaining = photoLimit - alreadyLoaded;
                        if (remaining <= 0) {
                            accepted = [];
                        } else if (candidates.length > remaining) {
                            accepted = candidates.slice(0, remaining);
                            console.log(`📏 Clamped ${candidates.length} candidates to ${remaining} to respect photoLimit=${photoLimit} (loaded: ${alreadyLoaded})`);
                        }
                    }

                    // 3) Now commit accepted IDs into Set + queue (only accepted photos!)
                    for (const { photoId } of accepted) {
                        state.renderedPhotoIds.add(photoId);
                        state.renderedPhotoQueue.push(photoId);

                        // Evict oldest entries when cap exceeded (NEVER reassign Set!)
                        while (state.renderedPhotoQueue.length > MAX_RENDERED_IDS) {
                            const oldestId = state.renderedPhotoQueue.shift();
                            state.renderedPhotoIds.delete(oldestId);
                        }
                    }

                    // Log cap status if relevant
                    if (state.renderedPhotoQueue.length === MAX_RENDERED_IDS) {
                        console.log(`📊 De-dupe at capacity: tracking ${MAX_RENDERED_IDS} most recent photo IDs`);
                    }

                    console.log(`✅ Loaded ${data.photos.length} photos (${candidates.length} unique, ${accepted.length} accepted) from page ${nextPage}`);

                    // 4) Push accepted photos
                    const acceptedPhotos = accepted.map(x => x.photo);
                    if (acceptedPhotos.length > 0) {
                        state.pendingPhotos.push(...acceptedPhotos);
                    }

                    // Update state in memory (no DOM write needed!)
                    setData.current_page = nextPage;

                    if (nextPage >= setData.total_pages || !data.has_more) {
                        console.log(`🏁 Reached last page (${nextPage}/${setData.total_pages}) for set ${setData.photoset_id}`);
                    }

                    return { status: 'success', photos: acceptedPhotos };
                } else {
                    console.log(`ℹ️ No more photos returned for page ${nextPage}`);
                    // Update state in memory (no DOM write needed!)
                    setData.current_page = setData.total_pages;
                    return { status: 'success', photos: [] };
                }
            } catch (error) {
                // Handle abort errors (request was cancelled)
                if (error.name === 'AbortError') {
                    console.log(`⏭️ Request aborted for set ${setData.photoset_id}`);
                    return { status: 'cancelled' };
                }

                console.error(`Failed to load page ${nextPage}:`, error);

                const msg = String(error?.message || '');
                const isNetworkish =
                    error?.name === 'TypeError' ||
                    /failed to fetch|networkerror|load failed/i.test(msg);

                const isJsonParse =
                    error?.name === 'SyntaxError' ||
                    /unexpected token|json/i.test(msg);

                if (isNetworkish || isJsonParse) {
                    return {
                        status: 'recoverable-error',
                        error: error.message,
                        retryDelay: 5000,
                        message: isJsonParse
                            ? '⚠️ Bad JSON response. Retrying shortly...'
                            : '⚠️ Network issue. Retrying shortly...'
                    };
                }

                // Hard failure: stop trying this set
                setData.loadingError = true;
                return { status: 'error', error: error.message };
            } finally {
                setData.isLoading = false;
            }
    }

    function createPhotoCard(photoData, gallery) {
            const card = document.createElement('article');
            card.className = 'flickr-justified-card';

            const position = photoData.position || 0;
            const views = photoData.views ?? photoData.view_count ?? 0;
            const comments = photoData.comments ?? photoData.comment_count ?? 0;
            const favorites = photoData.favorites ?? photoData.favorite_count ?? 0;
            const photoId = photoData.id || null;

            card.setAttribute('data-position', position);
            card.setAttribute('data-views', views);
            card.setAttribute('data-comments', comments);
            card.setAttribute('data-favorites', favorites);
            if (photoId) {
                card.setAttribute('data-photo-id', photoId);
            }

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
            const captionText = photoData.caption || photoData.title || attributionText;
            const altText = captionText || attributionText;

            const anchor = document.createElement('a');
            anchor.href = photoData.lightbox_url || photoData.image_url || photoData.url;
            anchor.className = lightboxClass;
            anchor.setAttribute('data-gallery', blockId);
            if (photoId) {
                anchor.setAttribute('data-photo-id', photoId);
            }

            const lightboxWidth = photoData.lightbox_width || photoData.width;
            const lightboxHeight = photoData.lightbox_height || photoData.height;

            if (lightboxWidth && lightboxHeight) {
                anchor.setAttribute('data-width', lightboxWidth);
                anchor.setAttribute('data-height', lightboxHeight);
            }

            if (photoData.rotation) {
                anchor.setAttribute('data-rotation', photoData.rotation);
            }

            anchor.setAttribute('data-flickr-page', photoData.flickr_page || photoData.attribution_url || photoData.url);
            anchor.setAttribute('data-flickr-attribution-text', attributionText);
            anchor.setAttribute('data-caption', captionText);
            anchor.setAttribute('data-title', captionText);
            anchor.title = captionText;

            const img = document.createElement('img');
            img.src = photoData.image_url || photoData.url;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.alt = altText;

            if (photoData.srcset) {
                img.srcset = photoData.srcset;
                if (photoData.sizes) {
                    img.sizes = photoData.sizes;
                }
            }

            if (photoData.width && photoData.height) {
                img.width = photoData.width;
                img.height = photoData.height;
                img.setAttribute('data-width', photoData.width);
                img.setAttribute('data-height', photoData.height);
            }

            if (photoData.rotation) {
                img.setAttribute('data-rotation', photoData.rotation);
                // Don't apply CSS rotation - dimensions are already swapped
            }

            anchor.appendChild(img);
            card.appendChild(anchor);

            return card;
    }

    // ============================================================================
    // INIT FUNCTION
    // ============================================================================

    function initFlickrAlbumLazyLoading() {
        'use strict';
        // Initialize all galleries on page
        initAllGalleries();
    }

    // ============================================================================
    // EXPOSE API
    // ============================================================================

    window.flickrJustified = window.flickrJustified || {};
    window.flickrJustified.initAlbumLazyLoading = initFlickrAlbumLazyLoading;
    window.flickrJustified.initAllGalleries = initAllGalleries; // For dynamic DOM insertion
    window.flickrJustified.destroyGallery = destroyGallery; // For cleanup

    // Debug helper (dev only - check state in console)
    if (typeof window !== 'undefined' && !window.location.hostname.includes('prod')) {
        window.__galleryDebugGetState = (gallery) => {
            const state = galleryStates.get(gallery);
            if (!state) {
                console.log('Gallery not initialized');
                return null;
            }
            // Return a serializable snapshot
            return {
                setMetadata: state.setMetadata,
                isLoading: state.isLoading,
                lastRequestId: state.lastRequestId,
                renderedPhotoCount: state.renderedPhotoIds.size,
                renderedPhotoQueueLength: state.renderedPhotoQueue.length,
                hasObserver: !!state.io,
                observerTriggered: state.observerTriggered,
                lastReinit: state.lastReinit,
                lastError: state.lastError,
                retryAt: state.retryAt,
                failCount: state.failCount,
                initialized: state.initialized
            };
        };
    }

    console.log('Flickr Justified Gallery: Lazy loading module loaded');
})();
