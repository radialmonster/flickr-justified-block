(function() {
    'use strict';

    function calculateOptimalRowHeight(images, containerWidth, gap) {
        // Calculate total aspect ratio for this row
        let totalAspectRatio = 0;
        images.forEach(img => {
            const aspectRatio = img.naturalWidth / img.naturalHeight;
            totalAspectRatio += aspectRatio;
        });

        // Calculate available width (container width minus gaps)
        const availableWidth = containerWidth - (gap * (images.length - 1));

        // Calculate optimal height that fits all images without cropping
        return availableWidth / totalAspectRatio;
    }

    function getImagesPerRow(containerWidth, breakpoints, responsiveSettings) {
        // Sort breakpoints by width (descending) to check from largest to smallest
        const sortedBreakpoints = Object.entries(breakpoints)
            .sort((a, b) => b[1] - a[1]);

        // Find the matching breakpoint (container width must be >= breakpoint width)
        for (const [breakpointName, breakpointWidth] of sortedBreakpoints) {
            if (containerWidth >= breakpointWidth) {
                return responsiveSettings[breakpointName] || 1;
            }
        }

        // Fallback to smallest breakpoint setting
        const smallestBreakpoint = Object.entries(breakpoints)
            .sort((a, b) => a[1] - b[1])[0];

        return smallestBreakpoint ? (responsiveSettings[smallestBreakpoint[0]] || 1) : 1;
    }

    function initJustifiedGallery() {
        const grids = document.querySelectorAll('.flickr-justified-grid:not(.justified-initialized)');

        grids.forEach(grid => {
            // Check if we have cards to work with
            const cards = grid.querySelectorAll('.flickr-card');
            if (cards.length === 0) return;

            // Wait for all images to load
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

                // Get responsive settings, breakpoints, and row height from data attributes
                let responsiveSettings = {};
                let breakpoints = {};
                let rowHeightMode = 'auto';
                let rowHeight = 280;

                try {
                    const responsiveData = grid.getAttribute('data-responsive-settings');
                    const breakpointsData = grid.getAttribute('data-breakpoints');
                    const rowHeightModeData = grid.getAttribute('data-row-height-mode');
                    const rowHeightData = grid.getAttribute('data-row-height');

                    if (responsiveData) {
                        responsiveSettings = JSON.parse(responsiveData);
                    }

                    if (breakpointsData) {
                        breakpoints = JSON.parse(breakpointsData);
                    }

                    if (rowHeightModeData) {
                        rowHeightMode = rowHeightModeData;
                    }

                    if (rowHeightData) {
                        rowHeight = parseInt(rowHeightData, 10) || 280;
                    }
                } catch (e) {
                    console.warn('Error parsing responsive settings:', e);
                    // Fallback to default settings
                    responsiveSettings = {
                        mobile: 1,
                        mobile_landscape: 1,
                        tablet_portrait: 2,
                        tablet_landscape: 3,
                        desktop: 3,
                        large_desktop: 4,
                        extra_large: 4
                    };
                    breakpoints = {
                        mobile: 320,
                        mobile_landscape: 480,
                        tablet_portrait: 600,
                        tablet_landscape: 768,
                        desktop: 1024,
                        large_desktop: 1280,
                        extra_large: 1440
                    };
                    rowHeightMode = 'auto';
                    rowHeight = 280;
                }

                const imagesPerRow = getImagesPerRow(containerWidth, breakpoints, responsiveSettings);


                // Get all cards from the grid
                const allCards = Array.from(grid.querySelectorAll('.flickr-card')).filter(card => {
                    const img = card.querySelector('img');
                    return img && img.naturalWidth > 0 && img.naturalHeight > 0;
                });

                if (allCards.length === 0) return;

                // Clear existing rows but keep the cards
                const cardsData = allCards.map(card => ({
                    element: card,
                    img: card.querySelector('img')
                }));

                grid.innerHTML = '';

                // Group cards into new rows based on screen size
                let previousRowHeight = null;

                for (let i = 0; i < cardsData.length; i += imagesPerRow) {
                    const rowCards = cardsData.slice(i, i + imagesPerRow);
                    const rowImages = rowCards.map(cardData => cardData.img);
                    const isLastRow = i + imagesPerRow >= cardsData.length;
                    const isSingleImageLastRow = isLastRow && rowCards.length === 1 && previousRowHeight !== null;

                    let actualRowHeight;

                    if (isSingleImageLastRow) {
                        // Make single last image double the previous row height
                        actualRowHeight = previousRowHeight * 2;
                    } else if (rowHeightMode === 'auto') {
                        // Use optimal height calculation that fills container width
                        actualRowHeight = calculateOptimalRowHeight(rowImages, containerWidth, gap);
                    } else {
                        // Use fixed height
                        actualRowHeight = rowHeight;
                    }

                    // Store this row's height for potential use by next row
                    previousRowHeight = actualRowHeight;

                    // Create new row
                    const row = document.createElement('div');
                    row.className = 'flickr-row';
                    row.style.height = `${actualRowHeight}px`;

                    rowCards.forEach((cardData) => {
                        const { element: card, img } = cardData;
                        const aspectRatio = img.naturalWidth / img.naturalHeight;
                        const width = actualRowHeight * aspectRatio;

                        // Set dimensions
                        card.style.width = `${width}px`;
                        card.style.height = `${actualRowHeight}px`;
                        img.style.width = `${width}px`;
                        img.style.height = `${actualRowHeight}px`;

                        // Move card to new row
                        row.appendChild(card);
                    });

                    grid.appendChild(row);
                }

                grid.classList.add('justified-initialized');
            }

            // Check if images are already loaded
            if (allImages.length === 0) {
                processRows();
                return;
            }

            allImages.forEach(img => {
                if (img.complete && img.naturalWidth > 0) {
                    checkAllLoaded();
                } else {
                    img.addEventListener('load', checkAllLoaded);
                    img.addEventListener('error', checkAllLoaded);
                }
            });
        });
    }

    // Run on initial load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initJustifiedGallery);
    } else {
        initJustifiedGallery();
    }

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            // Remove initialized class and re-run
            document.querySelectorAll('.flickr-justified-grid.justified-initialized').forEach(grid => {
                grid.classList.remove('justified-initialized');
            });
            initJustifiedGallery();
        }, 250);
    });

    // Use a MutationObserver to initialize on grids that are added later
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((mutations) => {
            const shouldInit = mutations.some(m =>
                Array.from(m.addedNodes).some(n =>
                    n.nodeType === 1 && (n.classList.contains('flickr-justified-grid') || n.querySelector('.flickr-justified-grid'))
                )
            );
            if (shouldInit) {
                setTimeout(initJustifiedGallery, 150);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
