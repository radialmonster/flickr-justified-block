/**
 * Built-in PhotoSwipe Implementation for Flickr Justified Block
 * ES module version - provides native PhotoSwipe lightbox with guaranteed Flickr attribution
 */

import { getPluginUrl } from './config';
import { log, warn } from './debug';
import { normalizeRotation, shouldSwapDimensions } from './layout';

// Lazy-loaded PhotoSwipe asset paths (deferred until first use)
let _paths = null;
function getPaths() {
    if ( ! _paths ) {
        const pluginUrl = getPluginUrl();
        const base = pluginUrl ? pluginUrl.replace( /\/$/, '' ) : '';
        _paths = {
            css: base ? base + '/assets/lib/photoswipe/photoswipe.css' : '',
            js: base ? base + '/assets/lib/photoswipe/photoswipe.esm.js' : '',
        };
        if ( ! pluginUrl ) {
            console.error( 'Flickr Justified Block: Plugin URL not configured. PhotoSwipe lightbox will not work.' );
        }
    }
    return _paths;
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

// Simple fullscreen API helper (supports unprefixed and webkit-prefixed versions)
function getFullscreenAPI() {
    let api;
    let enterFS;
    let exitFS;
    let elementFS;
    let changeEvent;
    let errorEvent;

    if (document.documentElement.requestFullscreen) {
        enterFS = 'requestFullscreen';
        exitFS = 'exitFullscreen';
        elementFS = 'fullscreenElement';
        changeEvent = 'fullscreenchange';
        errorEvent = 'fullscreenerror';
    } else if (document.documentElement.webkitRequestFullscreen) {
        enterFS = 'webkitRequestFullscreen';
        exitFS = 'webkitExitFullscreen';
        elementFS = 'webkitFullscreenElement';
        changeEvent = 'webkitfullscreenchange';
        errorEvent = 'webkitfullscreenerror';
    }

    if (enterFS) {
        api = {
            request: function (el) {
                if (enterFS === 'webkitRequestFullscreen') {
                    el[enterFS](Element.ALLOW_KEYBOARD_INPUT);
                } else {
                    el[enterFS]();
                }
            },

            exit: function () {
                return document[exitFS]();
            },

            isFullscreen: function () {
                return document[elementFS];
            },

            change: changeEvent,
            error: errorEvent
        };
    }

    return api;
}

// Create fullscreen container for PhotoSwipe
let pswpContainer = null;
function getContainer() {
    if (!pswpContainer) {
        pswpContainer = document.createElement('div');
        pswpContainer.style.background = '#000';
        pswpContainer.style.width = '100%';
        pswpContainer.style.height = '100%';
        pswpContainer.style.display = 'none';
        pswpContainer.className = 'pswp-fullscreen-container';
        document.body.appendChild(pswpContainer);
    }
    return pswpContainer;
}

// Get fullscreen promise for mobile devices
function getFullscreenPromise(fullscreenAPI, container) {
    // Always resolve promise, as we want to open lightbox
    // (no matter if fullscreen is supported or not)
    return () => new Promise((resolve) => {
        if (!fullscreenAPI || fullscreenAPI.isFullscreen()) {
            resolve();
            return;
        }

        let resolved = false;

        const finish = () => {
            if (!resolved) {
                resolved = true;
                resolve();
            }
        };

        const onFullscreenChange = () => {
            container.style.display = 'block';
            // delay to make sure that browser fullscreen animation is finished
            setTimeout(finish, 300);
        };

        document.addEventListener(fullscreenAPI.change, onFullscreenChange, { once: true });

        try {
            container.style.display = 'block';
            const requestResult = fullscreenAPI.request(container);
            if (requestResult && typeof requestResult.catch === 'function') {
                requestResult.catch(() => {
                    document.removeEventListener(fullscreenAPI.change, onFullscreenChange);
                    finish();
                });
            }
        } catch (error) {
            warn('Fullscreen request failed', error);
            document.removeEventListener(fullscreenAPI.change, onFullscreenChange);
            finish();
        }

        // Safety timeout to resolve even if fullscreenchange never fires
        // Reduced timeout to prevent blocking on mobile
        setTimeout(finish, 500);
    });
}

// Check if builtin lightbox is enabled
function isBuiltinLightboxEnabled() {
    const gallery = document.querySelector('.flickr-justified-grid[data-use-builtin-lightbox="1"]');
    return gallery !== null;
}

// Get attribution settings
function getAttributionSettings() {
    const gallery = document.querySelector('.flickr-justified-grid');
    const firstItem = document.querySelector('.flickr-justified-card a[data-flickr-attribution-text]');

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
        log('PhotoSwipe CSS already loaded');
        return Promise.resolve(); // Already loaded
    }

    log('Loading PhotoSwipe CSS from:', getPaths().css);

    return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = getPaths().css;
        link.onload = () => {
            log('PhotoSwipe CSS loaded successfully');
            resolve();
        };
        link.onerror = () => {
            console.error('Failed to load PhotoSwipe CSS from local files, trying CDN fallback');
            // Fallback to CDN
            const fallbackLink = document.createElement('link');
            fallbackLink.rel = 'stylesheet';
            fallbackLink.href = 'https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css';
            fallbackLink.onload = () => {
                log('PhotoSwipe CSS loaded from CDN fallback');
                resolve();
            };
            fallbackLink.onerror = () => {
                console.error('Flickr Justified Block: Failed to load PhotoSwipe CSS from both local and CDN. Check network connectivity or Content Security Policy.');
                reject(new Error('PhotoSwipe CSS unavailable'));
            };
            document.head.appendChild(fallbackLink);
        };
        document.head.appendChild(link);
    });
}

// Module-scoped PhotoSwipe reference (replaces window.flickrJustified.PhotoSwipe)
let _PhotoSwipe = null;

// Load PhotoSwipe JS
function loadPhotoSwipeJS() {
    if (_PhotoSwipe) {
        log('PhotoSwipe already loaded');
        return Promise.resolve(_PhotoSwipe);
    }

    log('Loading PhotoSwipe from:', getPaths().js);

    return import(/* webpackIgnore: true */ getPaths().js).then(module => {
        log('PhotoSwipe loaded successfully:', module);
        _PhotoSwipe = module.default;
        return module.default;
    }).catch(error => {
        console.error('Failed to load PhotoSwipe from local files:', error);
        // Fallback to CDN (pinned version — update when upgrading PhotoSwipe)
        log('Attempting fallback to CDN...');
        return import('https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.esm.js').then(module => {
            log('PhotoSwipe loaded from CDN fallback');
            _PhotoSwipe = module.default;
            return module.default;
        }).catch(cdnError => {
            console.error('Flickr Justified Block: Failed to load PhotoSwipe JS from both local and CDN. Check network connectivity or Content Security Policy.', cdnError);
            throw cdnError;
        });
    });
}

/**
 * Delegated click handler for PhotoSwipe
 * Handles both click and touch events for better mobile support
 * Distinguishes between tap (open lightbox) and scroll (don't open)
 */
function delegatedClickHandler(event) {
    const clickedItem = event.target.closest('a.flickr-builtin-lightbox');
    if (!clickedItem) return;

    // On touch devices, check if this was a scroll gesture vs a tap
    if (event.type === 'touchend') {
        const touchData = clickedItem._touchData;

        // If no touch data or touch moved significantly, this was a scroll - don't open lightbox
        if (!touchData || touchData.moved) {
            log('PhotoSwipe: Ignoring touchend - user was scrolling');
            delete clickedItem._touchData;
            return;
        }

        // Check if touch was too long (probably a long-press, not a tap)
        const touchDuration = Date.now() - touchData.startTime;
        if (touchDuration > 500) { // More than 500ms = long press
            log('PhotoSwipe: Ignoring touchend - long press detected');
            delete clickedItem._touchData;
            return;
        }

        // Clean up touch data
        delete clickedItem._touchData;

        // Prevent double-firing for touch+click
        if (clickedItem._pswpTouchHandled) {
            return;
        }
        clickedItem._pswpTouchHandled = true;
        setTimeout(() => {
            delete clickedItem._pswpTouchHandled;
        }, 500);
    }

    event.preventDefault();
    event.stopPropagation();

    const gallery = clickedItem.closest('.flickr-justified-grid');
    const items = gallery.querySelectorAll('.flickr-justified-card a.flickr-builtin-lightbox');
    const index = parseInt(clickedItem.getAttribute('data-pswp-index'), 10) || 0;

    log('PhotoSwipe click handler triggered for index:', index);

    const galleryData = Array.from(items).map((item) => {
        const img = item.querySelector('img');
        const flickrPage = item.getAttribute('data-flickr-page');
        const rotationAttr = item.getAttribute('data-rotation') || item.closest('.flickr-justified-card')?.dataset?.rotation || img?.getAttribute('data-rotation');
        const rotation = normalizeRotation(rotationAttr);

        const fallbackDisplayWidth = img?.naturalWidth || 1200;
        const fallbackDisplayHeight = img?.naturalHeight || 800;
        const displayWidth = parseInt(img?.getAttribute('data-width') || item.getAttribute('data-width'), 10) || fallbackDisplayWidth;
        const displayHeight = parseInt(img?.getAttribute('data-height') || item.getAttribute('data-height'), 10) || fallbackDisplayHeight;
        const lightboxWidth = parseInt(item.getAttribute('data-width'), 10) || displayWidth;
        const lightboxHeight = parseInt(item.getAttribute('data-height'), 10) || displayHeight;

        let width = lightboxWidth;
        let height = lightboxHeight;

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
            rotation,
            // Use photo title/caption when available for accessibility and potential UI display
            caption: item.getAttribute('data-caption') || item.getAttribute('data-title') || ''
        };
    });

    log('Opening PhotoSwipe with', galleryData.length, 'images');
    openPhotoSwipe(galleryData, index);
}

/**
 * Track touch start to distinguish tap from scroll
 */
function handleTouchStart(event) {
    const target = event.target.closest('a.flickr-builtin-lightbox');
    if (!target) return;

    const touch = event.touches[0];
    target._touchData = {
        startX: touch.clientX,
        startY: touch.clientY,
        startTime: Date.now(),
        moved: false
    };
}

/**
 * Track touch move to detect scrolling
 */
function handleTouchMove(event) {
    const target = event.target.closest('a.flickr-builtin-lightbox');
    if (!target || !target._touchData) return;

    const touch = event.touches[0];
    const deltaX = Math.abs(touch.clientX - target._touchData.startX);
    const deltaY = Math.abs(touch.clientY - target._touchData.startY);

    // If finger moved more than 10px in any direction, consider it a scroll
    if (deltaX > 10 || deltaY > 10) {
        target._touchData.moved = true;
        log('PhotoSwipe: Touch movement detected (scroll):', deltaX, deltaY);
    }
}

// Prepare gallery data for PhotoSwipe
function prepareGalleryData() {
    const galleries = document.querySelectorAll('.flickr-justified-grid[data-use-builtin-lightbox="1"]');

    log('Found', galleries.length, 'galleries with built-in lightbox enabled');

    galleries.forEach(gallery => {
        // Avoid attaching twice
        if (!gallery._pswpBound) {
            gallery.addEventListener('click', delegatedClickHandler, true); // one listener per gallery

            // Touch gesture detection for mobile
            gallery.addEventListener('touchstart', handleTouchStart, { passive: true });
            gallery.addEventListener('touchmove', handleTouchMove, { passive: true });
            gallery.addEventListener('touchend', delegatedClickHandler, true);

            gallery._pswpBound = true;
            log('PhotoSwipe: Event handlers bound to gallery', gallery.id || 'unnamed');
        }
        // Reindex after any DOM changes (cheap)
        const items = gallery.querySelectorAll('.flickr-justified-card a.flickr-builtin-lightbox');
        log('Preparing', items.length, 'items in gallery');
        items.forEach((item, idx) => item.setAttribute('data-pswp-index', idx));
        gallery.setAttribute('data-photoswipe-initialized', 'true');
    });
}


// Open PhotoSwipe gallery
function openPhotoSwipe(galleryData, index) {
    Promise.all([loadPhotoSwipeCSS(), loadPhotoSwipeJS()]).then(() => {
        const PhotoSwipe = _PhotoSwipe;
        const fullscreenAPI = getFullscreenAPI();
        // Use fullscreen on mobile/tablet devices in any orientation
        const isMobile = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        // Detect actual mobile devices (not tablets) by checking smallest screen dimension
        // This works in both portrait and landscape orientations
        const screenWidth = window.screen.width;
        const screenHeight = window.screen.height;
        const smallestDimension = Math.min(screenWidth, screenHeight);
        const largestDimension = Math.max(screenWidth, screenHeight);

        // Use fullscreen on phones (not tablets)
        // Most phones have smallest dimension < 500px, tablets >= 600px
        // Additional check for smaller tablets/large phones: dimensions <= 768 x 1024
        const isActualMobile = isMobile && (
            smallestDimension < 500 ||
            (smallestDimension <= 768 && largestDimension <= 1024)
        );

        const container = isActualMobile && fullscreenAPI ? getContainer() : null;
        const fullscreenPromiseFactory = container ? getFullscreenPromise(fullscreenAPI, container) : null;
        let ensureFullscreenPromise = null;

        const lightboxOptions = {
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
        };

        // Add fullscreen support for mobile (but don't block lightbox opening)
        if (isActualMobile && fullscreenAPI && container && fullscreenPromiseFactory) {
            let fullscreenPromiseInstance = null;
            ensureFullscreenPromise = () => {
                if (!fullscreenPromiseInstance) {
                    const promise = fullscreenPromiseFactory();
                    fullscreenPromiseInstance = (promise && typeof promise.then === 'function')
                        ? promise
                        : Promise.resolve();
                }
                return fullscreenPromiseInstance;
            };

            // Use openPromise to trigger fullscreen, but with shorter timeout
            lightboxOptions.openPromise = ensureFullscreenPromise;
            lightboxOptions.appendToEl = container;
            // Disable animations when using fullscreen (smoother experience)
            lightboxOptions.showAnimationDuration = 0;
            lightboxOptions.hideAnimationDuration = 0;
        }

        const lightbox = new PhotoSwipe(lightboxOptions);

        // Exit fullscreen on close
        lightbox.on('close', () => {
            if (container) {
                container.style.display = 'none';
            }
            if (fullscreenAPI && fullscreenAPI.isFullscreen()) {
                fullscreenAPI.exit();
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
            let targetElement = null;

            if (content.element.tagName && content.element.tagName.toLowerCase() === 'img') {
                targetElement = content.element;
            } else {
                targetElement = content.element.querySelector('img');
            }

            if (targetElement) {
                applyRotationToImageElement(targetElement, rotation);
            }
        };

        lightbox.on('contentAppend', ({ content }) => updateContentRotation(content));
        lightbox.on('contentActivate', ({ content }) => updateContentRotation(content));
        lightbox.on('contentUpdate', ({ content }) => updateContentRotation(content));
        lightbox.on('zoomPanUpdate', ({ slide }) => updateContentRotation(slide?.content));

        const openLightbox = () => lightbox.init();

        if (ensureFullscreenPromise) {
            ensureFullscreenPromise().finally(openLightbox);
        } else {
            openLightbox();
        }

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

// Track initialized galleries to prevent duplicate initialization
const initializedGalleries = new WeakSet();

// Initialize when DOM is ready
function init() {
    if (!isBuiltinLightboxEnabled()) {
        // No galleries yet (e.g., async loader). Observer + update events will initialize when they appear.
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
        log('PhotoSwipe built-in lightbox initialized');
        initializedOnce = true;
    }, 100);
}

// IMPORTANT: Set up MutationObserver FIRST, before any init attempts
// This ensures we catch galleries that load dynamically (AJAX/async)
const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        if (mutation.type === 'childList') {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1 && node.querySelector) {
                    // Find all galleries in the added node (or if the node itself is a gallery)
                    const galleries = [];
                    if (node.matches && node.matches('.flickr-justified-grid[data-use-builtin-lightbox="1"]')) {
                        galleries.push(node);
                    }
                    galleries.push(...node.querySelectorAll('.flickr-justified-grid[data-use-builtin-lightbox="1"]'));

                    galleries.forEach((gallery) => {
                        // Skip if already initialized
                        if (initializedGalleries.has(gallery) ||
                            gallery.getAttribute('data-photoswipe-initialized') === 'true') {
                            return;
                        }

                        log('PhotoSwipe: New gallery detected, initializing...');
                        initializedGalleries.add(gallery);
                        setTimeout(() => {
                            prepareGalleryData();
                            initializedOnce = true;
                        }, 100);
                    });
                }
            });
        }
    });
});

// Start observing and initialize once DOM is ready
function startObserver() {
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        startObserver();
        init();
    });
} else {
    startObserver();
    init();
}

// Also initialize when window loads (after all resources)
window.addEventListener('load', init);

// Listen for custom event from lazy loading to re-initialize PhotoSwipe
document.addEventListener('flickr-gallery-updated', function(event) {
    log('PhotoSwipe: Received gallery update event, re-initializing...');
    setTimeout(() => {
        prepareGalleryData(); // re-indexes items; no duplicate listeners
        log('PhotoSwipe: Re-initialization complete');
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

// Exports
export { prepareGalleryData };
export default init;
