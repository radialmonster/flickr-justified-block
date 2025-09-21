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
            document.querySelectorAll('.flickr-justified-grid.justified-initialized').forEach(grid => {
                grid.classList.remove('justified-initialized');
            });
            initJustifiedGallery();
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
})();

