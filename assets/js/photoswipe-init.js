/**
 * Built-in PhotoSwipe Implementation for Flickr Justified Block
 * Provides native PhotoSwipe lightbox with guaranteed Flickr attribution
 */
(function() {
    'use strict';

    // PhotoSwipe local files (from assets/lib directory - committed to repo)
    const PLUGIN_URL = (window.flickrJustifiedConfig && window.flickrJustifiedConfig.pluginUrl) || '/wp-content/plugins/flickr-justified-block';
    const PHOTOSWIPE_CSS = PLUGIN_URL.replace(/\/$/, '') + '/assets/lib/photoswipe/photoswipe.css';
    const PHOTOSWIPE_JS = PLUGIN_URL.replace(/\/$/, '') + '/assets/lib/photoswipe/photoswipe.esm.js';

    function normalizeRotation(value) {
        if (value === null || value === undefined) {
            return 0;
        }

        const parsed = Number.parseFloat(value);
        if (!Number.isFinite(parsed)) {
            return 0;
        }

        let normalized = Math.round(parsed) % 360;
        if (normalized < 0) {
            normalized += 360;
        }

        return normalized;
    }

    function shouldSwapDimensions(rotation) {
        const normalized = normalizeRotation(rotation);
        return normalized === 90 || normalized === 270;
    }

    function applyRotationToImageElement(img, rotation) {
        if (!img) {
            return;
        }

        const normalized = normalizeRotation(rotation);
        const existingTransform = img.style.transform || '';
        const cleanedTransform = existingTransform.replace(/rotate\([^)]*\)/gi, '').trim();

        if (normalized) {
            const rotateString = `rotate(${normalized}deg)`;
            const newTransform = `${cleanedTransform} ${rotateString}`.trim();
            img.style.transform = newTransform;
            img.style.transformOrigin = 'center center';
            img.dataset.rotation = String(normalized);
        } else {
            if (cleanedTransform !== existingTransform) {
                if (cleanedTransform) {
                    img.style.transform = cleanedTransform;
                } else {
                    img.style.removeProperty('transform');
                }
            }
            img.style.removeProperty('transform-origin');
            if (img.dataset) {
                delete img.dataset.rotation;
            }
        }
    }

    // Check if builtin lightbox is enabled
    function isBuiltinLightboxEnabled() {
        const gallery = document.querySelector('.flickr-justified-grid[data-use-builtin-lightbox="1"]');
        return gallery !== null;
    }

    // Get attribution settings
    function getAttributionSettings() {
        const gallery = document.querySelector('.flickr-justified-grid');
        const firstItem = document.querySelector('.flickr-card a[data-flickr-attribution-text]');

        if (!gallery && !firstItem) return null;

        const galleryText = gallery ? (gallery.getAttribute('data-attribution-text') || '') : '';
        const buttonText = firstItem ? (firstItem.getAttribute('data-flickr-attribution-text') || '') : '';

        return {
            text: buttonText || galleryText || 'Flickr'
        };
    }

    // Load PhotoSwipe CSS
    function loadPhotoSwipeCSS() {
        if (document.querySelector('link[href*="photoswipe"]')) {
            console.log('PhotoSwipe CSS already loaded');
            return Promise.resolve(); // Already loaded
        }

        console.log('Loading PhotoSwipe CSS from:', PHOTOSWIPE_CSS);

        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = PHOTOSWIPE_CSS;
            link.onload = () => {
                console.log('PhotoSwipe CSS loaded successfully');
                resolve();
            };
            link.onerror = () => {
                console.error('Failed to load PhotoSwipe CSS from local files, trying CDN fallback');
                // Fallback to CDN
                const fallbackLink = document.createElement('link');
                fallbackLink.rel = 'stylesheet';
                fallbackLink.href = 'https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css';
                fallbackLink.onload = () => {
                    console.log('PhotoSwipe CSS loaded from CDN fallback');
                    resolve();
                };
                fallbackLink.onerror = reject;
                document.head.appendChild(fallbackLink);
            };
            document.head.appendChild(link);
        });
    }

    // Load PhotoSwipe JS
    function loadPhotoSwipeJS() {
        if (window.PhotoSwipe) {
            console.log('PhotoSwipe already loaded');
            return Promise.resolve(window.PhotoSwipe);
        }

        console.log('Loading PhotoSwipe from:', PHOTOSWIPE_JS);

        return import(PHOTOSWIPE_JS).then(module => {
            console.log('PhotoSwipe loaded successfully:', module);
            window.PhotoSwipe = module.default;
            return module.default;
        }).catch(error => {
            console.error('Failed to load PhotoSwipe from local files:', error);
            // Fallback to CDN
            console.log('Attempting fallback to CDN...');
            return import('https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.esm.js').then(module => {
                console.log('PhotoSwipe loaded from CDN fallback');
                window.PhotoSwipe = module.default;
                return module.default;
            });
        });
    }

    /**
     * Delegated click handler for PhotoSwipe
     */
    function delegatedClickHandler(event) {
        const clickedItem = event.target.closest('a.flickr-builtin-lightbox');
        if (!clickedItem) return;

        event.preventDefault();
        event.stopPropagation();

        const gallery = clickedItem.closest('.flickr-justified-grid');
        const items = gallery.querySelectorAll('.flickr-card a.flickr-builtin-lightbox');
        const index = parseInt(clickedItem.getAttribute('data-pswp-index'), 10) || 0;

        console.log('PhotoSwipe click handler triggered for index:', index);

        const galleryData = Array.from(items).map((item) => {
            const img = item.querySelector('img');
            const flickrPage = item.getAttribute('data-flickr-page');
            const rotationAttr = item.getAttribute('data-rotation') || item.closest('.flickr-card')?.dataset?.rotation || img?.getAttribute('data-rotation');
            const rotation = normalizeRotation(rotationAttr);

            let width = parseInt(item.getAttribute('data-width'), 10) || (img?.naturalWidth || 1200);
            let height = parseInt(item.getAttribute('data-height'), 10) || (img?.naturalHeight || 800);

            if (width > 0 && height > 0 && shouldSwapDimensions(rotation)) {
                const temp = width;
                width = height;
                height = temp;
            }

            return {
                src: item.href,
                width,
                height,
                flickrPage,
                element: item,
                rotation
            };
        });

        console.log('Opening PhotoSwipe with', galleryData.length, 'images');
        openPhotoSwipe(galleryData, index);
    }

    // Prepare gallery data for PhotoSwipe
    function prepareGalleryData() {
        const galleries = document.querySelectorAll('.flickr-justified-grid[data-use-builtin-lightbox="1"]');

        console.log('Found', galleries.length, 'galleries with built-in lightbox enabled');

        galleries.forEach(gallery => {
            // Avoid attaching twice
            if (!gallery._pswpBound) {
                gallery.addEventListener('click', delegatedClickHandler, true); // one listener per gallery
                gallery._pswpBound = true;
            }
            // Reindex after any DOM changes (cheap)
            const items = gallery.querySelectorAll('.flickr-card a.flickr-builtin-lightbox');
            console.log('Preparing', items.length, 'items in gallery');
            items.forEach((item, idx) => item.setAttribute('data-pswp-index', idx));
            gallery.setAttribute('data-photoswipe-initialized', 'true');
        });
    }


    // Open PhotoSwipe gallery
    function openPhotoSwipe(galleryData, index) {
        Promise.all([loadPhotoSwipeCSS(), loadPhotoSwipeJS()]).then(() => {
            const PhotoSwipe = window.PhotoSwipe;

            const lightbox = new PhotoSwipe({
                dataSource: galleryData,
                index: index,
                showHideAnimationType: 'zoom',
                bgOpacity: 0.9,
                spacing: 0.1,
                allowPanToNext: false,
                loop: true,
                pinchToClose: true,
                closeOnVerticalDrag: true,

                // let PS size things; don't hardcode toolbar/viewport
                initialZoomLevel: 'fit',
                secondaryZoomLevel: 2,
                maxZoomLevel: 3,

                // Give PS dynamic padding that works on mobile
                paddingFn: (viewportSize, itemData, index) => {
                    const isSmall = Math.min(viewportSize.x, viewportSize.y) <= 600;
                    // iOS safe-area insets (0 on non-notch devices/browsers)
                    const safeTop = parseInt(getComputedStyle(document.documentElement)
                        .getPropertyValue('env(safe-area-inset-top)') || '0', 10) || 0;
                    const safeBottom = parseInt(getComputedStyle(document.documentElement)
                        .getPropertyValue('env(safe-area-inset-bottom)') || '0', 10) || 0;

                    // Reserve a bit more top room for the toolbar on small screens.
                    const topPad = isSmall ? (56 + safeTop) : (48 + safeTop);
                    // Small bottom gap so image appears visually centered with the top bar shown.
                    const bottomPad = isSmall ? (16 + safeBottom) : (16 + safeBottom);

                    return { top: topPad, bottom: bottomPad, left: 0, right: 0 };
                }
            });

            // Add Flickr attribution button
            lightbox.on('uiRegister', function() {
                const attributionSettings = getAttributionSettings();
                if (!attributionSettings) return;

                lightbox.ui.registerElement({
                    name: 'flickr-attribution',
                    order: 9,
                    isButton: true,
                    tagName: 'a',
                    html: attributionSettings.text,
                    title: attributionSettings.text,
                    ariaLabel: 'View original photo on Flickr',
                    onInit: (el, pswp) => {
                        el.setAttribute('target', '_blank');
                        el.setAttribute('rel', 'noopener noreferrer');
                        el.classList.add('pswp__button--flickr-attribution');
                        updateAttributionUrl(el, pswp);
                        pswp.on('change', () => updateAttributionUrl(el, pswp));
                    }
                });
            });

            const updateContentRotation = (content) => {
                if (!content || !content.element) {
                    return;
                }

                const rotation = normalizeRotation(content.data?.rotation);
                const imgEl = content.element.querySelector('img');
                if (imgEl) {
                    applyRotationToImageElement(imgEl, rotation);
                }
            };

            lightbox.on('contentAppend', ({ content }) => updateContentRotation(content));
            lightbox.on('contentActivate', ({ content }) => updateContentRotation(content));
            lightbox.on('contentUpdate', ({ content }) => updateContentRotation(content));
            lightbox.on('zoomPanUpdate', ({ slide }) => updateContentRotation(slide?.content));

            // Open the lightbox
            lightbox.init();

        }).catch(error => {
            console.error('Failed to load PhotoSwipe:', error);
            // Fallback: open image in new tab
            window.open(galleryData[index].src, '_blank');
        });
    }

    // Update attribution URL in PhotoSwipe
    function updateAttributionUrl(el, pswp) {
        try {
            const currentSlide = pswp.currSlide;
            if (currentSlide && currentSlide.data && currentSlide.data.flickrPage) {
                el.href = currentSlide.data.flickrPage;
                el.style.opacity = '1';
                el.style.pointerEvents = 'auto';
            } else {
                el.href = '#';
                el.style.opacity = '0.5';
                el.style.pointerEvents = 'none';
            }
        } catch (error) {
            console.error('Error updating PhotoSwipe attribution URL:', error);
        }
    }

    let initializedOnce = false;

    // Initialize when DOM is ready
    function init() {
        if (!isBuiltinLightboxEnabled()) {
            console.log('Built-in PhotoSwipe lightbox not enabled');
            return;
        }
        if (initializedOnce) {
            // Still re-run prepare to reindex dynamic items, but skip heavy work
            prepareGalleryData();
            return;
        }

        // Wait a bit for other scripts to finish
        setTimeout(() => {
            prepareGalleryData();
            console.log('PhotoSwipe built-in lightbox initialized');
            initializedOnce = true;
        }, 100);
    }

    // Wait for DOM and initialize - use multiple event triggers
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Also initialize when window loads (after all resources)
    window.addEventListener('load', init);

    // Re-initialize when new galleries are added (for dynamic content)
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.querySelector &&
                        node.querySelector('.flickr-justified-grid[data-use-builtin-lightbox="1"]')) {
                        setTimeout(prepareGalleryData, 100);
                    }
                });
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Listen for custom event from lazy loading to re-initialize PhotoSwipe
    document.addEventListener('flickr-gallery-updated', function(event) {
        console.log('PhotoSwipe: Received gallery update event, re-initializing...');
        setTimeout(() => {
            prepareGalleryData(); // re-indexes items; no duplicate listeners
            console.log('PhotoSwipe: Re-initialization complete');
        }, 100);
    });

    // Keyboard activation for accessibility
    document.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target instanceof Element) {
            const a = e.target.closest('a.flickr-builtin-lightbox');
            if (a) {
                e.preventDefault();
                a.click();
            }
        }
    });

})();