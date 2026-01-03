/**
 * Flickr Justified Gallery - Layout Engine
 *
 * Core layout calculation algorithm for creating justified photo rows.
 * Handles aspect ratios, rotation, responsive breakpoints, and row building.
 */

(function() {
    'use strict';

    // Constants
    const SORT_VIEWS_DESC = 'views_desc';

    // ============================================================================
    // ROTATION HELPERS
    // ============================================================================

    function normalizeRotation(value) {
        if (!value || typeof value !== 'number' && typeof value !== 'string') {
            return 0;
        }
        const parsed = parseInt(value, 10);
        if (isNaN(parsed)) return 0;
        const normalized = parsed % 360;
        return normalized < 0 ? normalized + 360 : normalized;
    }

    function shouldSwapDimensions(rotation) {
        const normalized = normalizeRotation(rotation);
        return normalized === 90 || normalized === 270;
    }

    // Rotation is handled by dimension swapping only (no CSS transforms needed)
    // Images are displayed in their raw orientation with swapped container dimensions

    // ============================================================================
    // LAYOUT CALCULATIONS
    // ============================================================================

    function calculateOptimalRowHeight(aspectRatios, containerWidth, gap) {
        const totalAspectRatio = aspectRatios.reduce((sum, ar) => sum + ar, 0);
        const availableWidth = containerWidth - (gap * (aspectRatios.length - 1));
        return availableWidth / totalAspectRatio;
    }

    function getAspectRatioForCard(card) {
        const img = card.querySelector('img');
        const anchor = card.querySelector('a');

        // Handle rotation for dimension swapping
        let rotationSource = card.dataset?.rotation;
        if (rotationSource === undefined && anchor) {
            rotationSource = anchor.getAttribute('data-rotation');
        }
        if (rotationSource === undefined && img) {
            rotationSource = img.getAttribute('data-rotation');
        }

        const rotation = normalizeRotation(rotationSource);
        const swapDimensions = shouldSwapDimensions(rotation);

        // Priority 1: Natural dimensions if image is loaded
        if (img && img.complete && img.naturalWidth > 0 && img.naturalHeight > 0) {
            const width = swapDimensions ? img.naturalHeight : img.naturalWidth;
            const height = swapDimensions ? img.naturalWidth : img.naturalHeight;
            return width / height;
        }

        // Priority 2: Data attributes (accurate for Flickr + cached non-Flickr)
        const widthAttr = parseInt(
            img?.getAttribute('data-width') ||
            anchor?.getAttribute('data-width') ||
            card.getAttribute('data-width') ||
            '0',
            10
        );
        const heightAttr = parseInt(
            img?.getAttribute('data-height') ||
            anchor?.getAttribute('data-height') ||
            card.getAttribute('data-height') ||
            '0',
            10
        );

        if (widthAttr > 0 && heightAttr > 0) {
            const width = swapDimensions ? heightAttr : widthAttr;
            const height = swapDimensions ? widthAttr : heightAttr;
            return width / height;
        }

        // Priority 3: Fallback (matches CSS aspect-ratio: 3/2)
        // Only reached for non-Flickr images with no cached dimensions
        return 3 / 2;
    }

    function getImagesPerRow(containerWidth, breakpoints, responsiveSettings) {
        const sortedBreakpoints = Object.entries(breakpoints).sort((a, b) => b[1] - a[1]);

        for (const [key, width] of sortedBreakpoints) {
            if (containerWidth >= width && responsiveSettings[key]) {
                return responsiveSettings[key];
            }
        }

        // Fallback: use smallest breakpoint value from responsiveSettings, or mobile-friendly default of 1
        const fallbackKeys = ['mobile', 'mobile_landscape', 'tablet_portrait', 'default'];
        for (const key of fallbackKeys) {
            if (responsiveSettings[key]) {
                return responsiveSettings[key];
            }
        }
        return 1; // Safe mobile-friendly default
    }

    // ============================================================================
    // MAIN LAYOUT FUNCTION
    // ============================================================================

    function initJustifiedGallery() {
        const grids = document.querySelectorAll('.flickr-justified-grid:not(.justified-initialized)');

        grids.forEach(grid => {
            const gap = parseInt(getComputedStyle(grid).getPropertyValue('--gap') || '12', 10);

            function processRows() {
                const containerWidth = grid.offsetWidth || grid.clientWidth || grid.getBoundingClientRect().width;
                if (containerWidth === 0) {
                    console.warn('Flickr Gallery: Container width is zero, cannot layout');
                    return;
                }

                const responsiveSettings = JSON.parse(grid.dataset.responsiveSettings || '{}');
                const breakpoints = JSON.parse(grid.dataset.breakpoints || '{}');
                const rowHeightMode = grid.dataset.rowHeightMode || 'auto';
                const targetRowHeight = parseInt(grid.dataset.rowHeight || '300', 10);
                const maxViewportHeight = parseInt(grid.dataset.maxViewportHeight || '80', 10);
                const singleImageAlignment = grid.dataset.singleImageAlignment || 'center';

                const maxRowHeightVh = Math.max(50, Math.min(window.innerHeight, window.innerHeight * (maxViewportHeight / 100)));

                const allCards = Array.from(grid.querySelectorAll(':scope > .flickr-justified-card'));
                if (allCards.length === 0) {
                    console.log('Flickr Gallery: No cards found');
                    return;
                }

                const staging = document.createElement('div');
                staging.className = 'flickr-staging';
                grid.appendChild(staging);

                // Calculate images per row based on breakpoints
                const imagesPerRow = getImagesPerRow(containerWidth, breakpoints, responsiveSettings);

                // Collect aspect ratios
                const aspectRatios = allCards.map(getAspectRatioForCard);

                // Single image case
                if (allCards.length === 1) {
                    const row = document.createElement('div');
                    row.className = 'flickr-justified-row';

                    const aspectRatio = aspectRatios[0];

                    // Start from container width and max height, size down to fit both constraints
                    const heightFromWidth = containerWidth / aspectRatio;
                    let cardHeight = Math.min(heightFromWidth, maxRowHeightVh);
                    let cardWidth = cardHeight * aspectRatio;

                    // Ensure it doesn't exceed container width
                    if (cardWidth > containerWidth) {
                        cardWidth = containerWidth;
                        cardHeight = cardWidth / aspectRatio;
                    }

                    const card = allCards[0];
                    card.style.width = Math.round(cardWidth) + 'px';
                    card.style.height = Math.round(cardHeight) + 'px';

                    // Ensure image fills the card container
                    const img = card.querySelector('img');
                    if (img) {
                        const rotation = normalizeRotation(card.dataset?.rotation || img.dataset?.rotation || 0);
                        const shouldSwap = shouldSwapDimensions(rotation);

                        // Always use percentage sizing to prevent gaps
                        img.style.width = '100%';
                        img.style.height = '100%';

                        if (shouldSwap) {
                            // Rotated images use contain to show full image
                            img.style.objectFit = 'contain';
                        } else {
                            // Normal images use cover (set in CSS, but ensure it's applied)
                            img.style.objectFit = 'cover';
                        }
                    }

                    row.appendChild(card);
                    staging.appendChild(row);

                    grid.insertBefore(staging.firstElementChild, staging);
                    staging.remove();

                    console.log(`Flickr Gallery: Single image layout complete - ${Math.round(cardWidth)}x${Math.round(cardHeight)}`);
                    return;
                }

                // Multiple images - create justified rows
                let currentRow = [];
                let currentRowAspectRatios = [];

                for (let i = 0; i < allCards.length; i++) {
                    const card = allCards[i];
                    const aspectRatio = aspectRatios[i];

                    currentRow.push(card);
                    currentRowAspectRatios.push(aspectRatio);

                    const isLastCard = (i === allCards.length - 1);
                    const rowFull = currentRow.length >= imagesPerRow;

                    if (rowFull || isLastCard) {
                        let optimalHeight;

                        if (rowHeightMode === 'fixed') {
                            optimalHeight = targetRowHeight;
                        } else {
                            optimalHeight = calculateOptimalRowHeight(currentRowAspectRatios, containerWidth, gap);
                            optimalHeight = Math.max(100, Math.min(optimalHeight, maxRowHeightVh));
                        }

                        const row = document.createElement('div');
                        row.className = 'flickr-justified-row';

                        currentRow.forEach((cardElement, idx) => {
                            const ar = currentRowAspectRatios[idx];
                            const cardWidth = Math.round(optimalHeight * ar);
                            const cardHeight = Math.round(optimalHeight);

                            cardElement.style.width = cardWidth + 'px';
                            cardElement.style.height = cardHeight + 'px';

                            // Ensure image fills the card container
                            const img = cardElement.querySelector('img');
                            if (img) {
                                const rotation = normalizeRotation(cardElement.dataset?.rotation || img.dataset?.rotation || 0);
                                const shouldSwap = shouldSwapDimensions(rotation);

                                // Always use percentage sizing to prevent gaps
                                img.style.width = '100%';
                                img.style.height = '100%';

                                if (shouldSwap) {
                                    // Rotated images use contain to show full image
                                    img.style.objectFit = 'contain';
                                } else {
                                    // Normal images use cover (set in CSS, but ensure it's applied)
                                    img.style.objectFit = 'cover';
                                }
                            }

                            row.appendChild(cardElement);
                        });

                        staging.appendChild(row);

                        currentRow = [];
                        currentRowAspectRatios = [];
                    }
                }

                // Move rows from staging to grid
                const loadingIndicator = grid.querySelector('.flickr-loading-more');
                const shouldPreserveIndicator = loadingIndicator && !loadingIndicator.dataset.removeMe;

                while (staging.firstElementChild) {
                    grid.insertBefore(staging.firstElementChild, staging);
                }

                if (loadingIndicator && shouldPreserveIndicator) {
                    grid.appendChild(loadingIndicator);
                }

                staging.remove();

                console.log(`Flickr Gallery: Layout complete - ${allCards.length} images in rows`);
            }

            try {
                processRows();
            } catch (error) {
                console.error('Flickr Gallery: Error during layout:', error);
            }

            grid.classList.add('justified-initialized');

            // No decode-triggered relayout needed: dimensions are accurate from server-side cache
            // - Flickr images: dimensions from API
            // - Non-Flickr images: dimensions from getimagesize() with caching
            // - CSS aspect-ratio fallback handles rare missing dimensions
            // - Browser's native scroll anchoring prevents scroll jumps

            const reinitEvent = new CustomEvent('flickrGalleryReorganized', { detail: { grid: grid } });
            document.dispatchEvent(reinitEvent);

            // CRITICAL: Also dispatch flickr-gallery-updated for PhotoSwipe initialization
            // This ensures PhotoSwipe event handlers are attached for synchronously-rendered galleries
            setTimeout(() => {
                const photoswipeEvent = new CustomEvent('flickr-gallery-updated', { detail: { gallery: grid } });
                document.dispatchEvent(photoswipeEvent);
                console.log('Flickr Gallery: Initialization complete');
            }, 100);
        });
    }

    // Re-layout on window resize (debounced)
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            const grids = document.querySelectorAll('.flickr-justified-grid.justified-initialized');
            grids.forEach(grid => {
                if (grid._isResizing) return;
                grid._isResizing = true;

                setTimeout(() => {
                    grid.classList.remove('justified-initialized');
                    initJustifiedGallery();
                    grid._isResizing = false;
                }, 100);
            });
        }, 250);
    });

    // ============================================================================
    // EXPOSE API
    // ============================================================================

    // Create namespaced object to avoid conflicts with other plugins
    window.flickrJustified = window.flickrJustified || {};
    window.flickrJustified.initGallery = initJustifiedGallery;
    window.flickrJustified.helpers = window.flickrJustified.helpers || {};
    Object.assign(window.flickrJustified.helpers, {
        normalizeRotation,
        shouldSwapDimensions,
        getAspectRatioForCard
    });

    console.log('Flickr Justified Gallery: Layout engine loaded');
})();
