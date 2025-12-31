/**
 * Flickr Justified Gallery - Main Orchestrator
 *
 * Loads after all modules and coordinates initialization.
 * Modules must be loaded in order:
 *   1. justified-helpers.js
 *   2. justified-layout.js
 *   3. justified-lazy-loading.js
 *   4. justified-init.js (this file)
 */

(function() {
    'use strict';

    console.log('Flickr Justified Gallery: Initializing...');

    // Verify all modules are loaded
    if (!window.flickrJustified) {
        console.error('Flickr Justified Gallery: Core modules not loaded! Check script order.');
        return;
    }

    const { initGallery, initAlbumLazyLoading } = window.flickrJustified;

    if (!initGallery) {
        console.error('Flickr Justified Gallery: Layout module (initGallery) not found!');
        return;
    }

    if (!initAlbumLazyLoading) {
        console.error('Flickr Justified Gallery: Lazy loading module not found!');
        return;
    }

    // Initialize on page load
    function initialize() {
        console.log('Flickr Justified Gallery: Starting initialization sequence...');

        // Step 1: Initialize layout (creates rows)
        if (typeof initGallery === 'function') {
            initGallery();
            console.log('✓ Layout initialized');
        }

        // Step 2: Initialize lazy loading (after layout is ready)
        // Use setTimeout to ensure DOM is stable after layout
        setTimeout(() => {
            if (typeof initAlbumLazyLoading === 'function') {
                initAlbumLazyLoading();
                console.log('✓ Lazy loading initialized');
            }
            console.log('🎉 Flickr Justified Gallery: Fully initialized!');
        }, 150);
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    // Re-initialize on dynamic content
    document.addEventListener('flickr-gallery-updated', function(event) {
        console.log('🔄 Gallery updated event received');
        // Layout module already handles this via its own event listener
        // Lazy loading module will re-observe new images automatically
    });

})();
