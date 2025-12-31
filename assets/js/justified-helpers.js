/**
 * Flickr Justified Gallery - Helper Utilities
 *
 * Utility functions for loading indicators, data management, and UI helpers.
 */

(function() {
    'use strict';

    // ============================================================================
    // LOADING INDICATORS
    // ============================================================================

    function createLoadingIndicatorElement(baseLoadingMessage) {
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
        loadingIndicator.textContent = baseLoadingMessage;
        if (loadingIndicator.dataset) {
            loadingIndicator.dataset.shouldPersist = 'false';
        }
        return loadingIndicator;
    }

    function maintainLoadingIndicator(options) {
        const { gallery, loadingIndicator, baseLoadingMessage, indicatorMessage,
                shouldPersist, shouldRemoveIndicator, createIndicator, fallbackIndicator } = options;

        let indicator = loadingIndicator || fallbackIndicator || null;

        if (shouldRemoveIndicator) {
            if (indicator) {
                indicator.remove();
                if (indicator.dataset) indicator.dataset.shouldPersist = 'false';
            }
            return loadingIndicator || indicator;
        }

        if (shouldPersist) {
            if (!indicator) indicator = createIndicator();
            if (!indicator.isConnected) gallery.appendChild(indicator);
            indicator.textContent = indicatorMessage || indicator.textContent || baseLoadingMessage;
            if (indicator.dataset) indicator.dataset.shouldPersist = 'true';
            return indicator;
        }

        if (loadingIndicator?.dataset) {
            loadingIndicator.dataset.shouldPersist = 'false';
            if (!indicatorMessage) loadingIndicator.textContent = baseLoadingMessage;
        }

        return loadingIndicator;
    }

    // ============================================================================
    // DATA HELPERS
    // ============================================================================

    function getLoadedCount(gallery) {
        return parseInt(gallery.getAttribute('data-loaded-count') || '0', 10);
    }

    function setLoadedCount(gallery, count) {
        gallery.setAttribute('data-loaded-count', count);
    }

    function getPhotoLimit(gallery) {
        return parseInt(gallery.getAttribute('data-photo-limit') || '0', 10);
    }

    // ============================================================================
    // EXPOSE API
    // ============================================================================

    window.flickrJustified = window.flickrJustified || {};
    window.flickrJustified.helpers = window.flickrJustified.helpers || {};
    Object.assign(window.flickrJustified.helpers, {
        createLoadingIndicatorElement,
        maintainLoadingIndicator,
        getLoadedCount,
        setLoadedCount,
        getPhotoLimit
    });

    console.log('Flickr Justified Gallery: Helpers loaded');
})();
