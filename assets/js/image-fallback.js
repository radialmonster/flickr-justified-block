(function() {
    'use strict';

    /**
     * Automatic image fallback handler for Flickr photos
     * Detects 404 errors and fetches fresh URLs from Flickr API
     */

    // Track images we've already tried to refresh (prevent infinite loops)
    const attemptedRefresh = new WeakSet();

    /**
     * Extract Flickr photo ID from various sources
     */
    function extractPhotoId(img) {
        // Try to extract from current src URL
        const srcMatch = img.src.match(/\/(\d{10,})_[a-f0-9]+_[a-z]\.jpg/i);
        if (srcMatch && srcMatch[1]) {
            return srcMatch[1];
        }

        // Try to extract from parent link href
        const link = img.closest('a');
        if (link) {
            const hrefMatch = link.href.match(/\/(\d{10,})_[a-f0-9]+_[a-z]\.jpg/i);
            if (hrefMatch && hrefMatch[1]) {
                return hrefMatch[1];
            }
        }

        // Try to extract from data attributes
        const card = img.closest('.flickr-card');
        if (card && card.dataset.photoId) {
            return card.dataset.photoId;
        }

        return null;
    }

    /**
     * Get the size suffix from the image URL
     */
    function getSizeSuffix(url) {
        const match = url.match(/_([a-z])\.jpg$/i);
        return match ? match[1] : 'b'; // default to 'b' (large)
    }

    /**
     * Fetch fresh URL from Flickr API via WordPress AJAX
     */
    async function fetchFreshUrl(photoId, size = 'large') {
        console.log(`🔄 Fetching fresh URL for photo ${photoId}, size: ${size}`);

        const formData = new URLSearchParams();
        formData.append('action', 'flickr_justified_refresh_photo_url');
        formData.append('photo_id', photoId);
        formData.append('size', size);

        // Get AJAX URL from localized script
        const ajaxUrl = (typeof flickrJustifiedAjax !== 'undefined' && flickrJustifiedAjax.ajaxurl)
            ? flickrJustifiedAjax.ajaxurl
            : '/wp-admin/admin-ajax.php';

        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData,
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.data && data.data.url) {
                console.log(`✅ Got fresh URL for photo ${photoId}: ${data.data.url}`);
                return data.data;
            } else {
                console.error(`❌ Failed to get fresh URL for photo ${photoId}:`, data);
                return null;
            }
        } catch (error) {
            console.error(`❌ Error fetching fresh URL for photo ${photoId}:`, error);
            return null;
        }
    }

    /**
     * Handle image load error
     */
    async function handleImageError(event) {
        const img = event.target;

        // Prevent infinite retry loops
        if (attemptedRefresh.has(img)) {
            console.warn('⚠️ Already attempted refresh for this image, skipping:', img.src);
            return;
        }

        attemptedRefresh.add(img);

        console.warn('🔍 Image failed to load:', img.src);

        // Extract photo ID
        const photoId = extractPhotoId(img);
        if (!photoId) {
            console.error('❌ Could not extract photo ID from failed image');
            return;
        }

        console.log(`📸 Extracted photo ID: ${photoId}`);

        // Determine size from current URL
        const sizeSuffix = getSizeSuffix(img.src);
        const sizeMap = {
            'o': 'original',
            'k': 'large2048',
            'h': 'large1600',
            'l': 'large1024',
            'c': 'medium800',
            'z': 'medium640',
            'm': 'medium500',
            'n': 'small320',
            's': 'small240',
            't': 'thumbnail100',
            'q': 'thumbnail150s',
            'sq': 'thumbnail75s',
            'b': 'large'
        };
        const size = sizeMap[sizeSuffix] || 'large';

        // Fetch fresh URL
        const freshData = await fetchFreshUrl(photoId, size);

        if (!freshData || !freshData.url) {
            console.error('❌ Could not get fresh URL for photo', photoId);
            // Show placeholder or error message
            img.alt = 'Image unavailable';
            img.style.opacity = '0.3';
            return;
        }

        // Update image src
        console.log(`🔄 Updating image src to: ${freshData.url}`);
        img.src = freshData.url;

        // Update dimensions if provided
        if (freshData.width && freshData.height) {
            img.setAttribute('data-width', freshData.width);
            img.setAttribute('data-height', freshData.height);
        }

        // Update parent link if it exists (for lightbox)
        const link = img.closest('a');
        if (link && link.href.includes('staticflickr.com')) {
            // Try to fetch the largest available size for lightbox
            const lightboxData = await fetchFreshUrl(photoId, 'original');
            if (lightboxData && lightboxData.url) {
                console.log(`🔄 Updating lightbox link to: ${lightboxData.url}`);
                link.href = lightboxData.url;

                // Update data attributes for PhotoSwipe
                if (lightboxData.width && lightboxData.height) {
                    link.setAttribute('data-width', lightboxData.width);
                    link.setAttribute('data-height', lightboxData.height);
                }
            }
        }

        // Trigger gallery reorganization if needed
        const gallery = img.closest('.flickr-justified-grid');
        if (gallery && window.initJustifiedGallery) {
            console.log('🔄 Re-initializing gallery after image update');
            gallery.classList.remove('justified-initialized');
            setTimeout(() => {
                window.initJustifiedGallery();
            }, 100);
        }

        // Trigger PhotoSwipe update
        if (gallery) {
            const event = new CustomEvent('flickr-gallery-updated', { detail: { gallery } });
            document.dispatchEvent(event);
        }
    }

    /**
     * Initialize fallback handler for all Flickr images
     */
    function initImageFallback() {
        // Find all Flickr gallery images
        const images = document.querySelectorAll('.flickr-justified-grid img');

        console.log(`🖼️ Initializing image fallback for ${images.length} images`);

        images.forEach(img => {
            // Listen for load errors
            img.addEventListener('error', handleImageError, { once: false });

            // If image is already in error state (complete but naturalWidth is 0)
            if (img.complete && img.naturalWidth === 0 && !attemptedRefresh.has(img)) {
                console.warn('🔍 Found already-failed image on init:', img.src);
                handleImageError({ target: img });
            }
        });
    }

    // Initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initImageFallback);
    } else {
        initImageFallback();
    }

    // Re-initialize when galleries are updated
    document.addEventListener('flickr-gallery-updated', function(event) {
        console.log('🔄 Gallery updated, re-initializing image fallback');
        initImageFallback();
    });

    // Handle dynamically loaded galleries (MutationObserver)
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver((mutations) => {
            const hasNewGalleries = mutations.some(m =>
                Array.from(m.addedNodes).some(n =>
                    n.nodeType === 1 &&
                    (n.classList && n.classList.contains('flickr-justified-grid') ||
                     n.querySelector && n.querySelector('.flickr-justified-grid'))
                )
            );

            if (hasNewGalleries) {
                console.log('🔄 New galleries detected, initializing image fallback');
                setTimeout(initImageFallback, 200);
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    console.log('✅ Flickr image fallback handler loaded');
})();
