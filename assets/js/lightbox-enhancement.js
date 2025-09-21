/**
 * Simple Lightbox Enhancement for Flickr Justified Block
 * Adds a "View on Flickr" link in lightbox
 */
(function() {
    'use strict';

    // Function to get the current Flickr URL from the lightbox
    function getCurrentFlickrUrl() {
        // Look for Flickr images in the lightbox
        var allImgs = document.querySelectorAll('#slb_viewer_wrap img');
        for (var i = 0; i < allImgs.length; i++) {
            var img = allImgs[i];
            if (img.src && img.src.indexOf('staticflickr.com') !== -1) {
                return findFlickrUrlByImageSrc(img.src);
            }
        }
        return null;
    }

    // Helper function to match image source to gallery Flickr URL
    function findFlickrUrlByImageSrc(imageSrc) {
        console.log('Searching for Flickr URL for image:', imageSrc);

        var galleryItems = document.querySelectorAll('.flickr-card a[data-flickr-page]');
        for (var i = 0; i < galleryItems.length; i++) {
            var item = galleryItems[i];
            var href = item.getAttribute('href');
            var flickrPage = item.getAttribute('data-flickr-page');

            // Extract photo ID from both URLs for comparison
            var lightboxPhotoId = imageSrc.split('/').pop().split('_')[0];
            var galleryPhotoId = href.split('/').pop().split('_')[0];

            console.log('Comparing photo IDs:', lightboxPhotoId, 'vs', galleryPhotoId);

            if (lightboxPhotoId === galleryPhotoId) {
                console.log('Found matching gallery item:', i, flickrPage);
                return flickrPage;
            }
        }

        console.log('No matching gallery item found for image');
        return null;
    }

    // Function to add and update a clickable Flickr link in lightbox
    function addLightboxNote() {
        // Try multiple times with increasing delays
        tryAddLink(1000); // First try after 1 second

        // Also set up monitoring for image changes
        setupImageChangeMonitoring();

        function tryAddLink(delay) {
            setTimeout(function() {
                updateFlickrLink();
            }, delay);
        }
    }

    // Function to update/create the Flickr link
    function updateFlickrLink() {
        // Check if Simple Lightbox is open
        var lightboxWrapper = document.querySelector('#slb_viewer_wrap');
        if (!lightboxWrapper) {
            return;
        }

        // Try to find the correct current image URL
        var flickrUrl = getCurrentFlickrUrl();

        // If we can't find the current image, retry once more
        if (!flickrUrl) {
            setTimeout(function() {
                flickrUrl = getCurrentFlickrUrl();
                if (!flickrUrl) {
                    // Final fallback to first gallery item
                    var galleryItems = document.querySelectorAll('.flickr-card a[data-flickr-page]');
                    flickrUrl = galleryItems[0] ? galleryItems[0].getAttribute('data-flickr-page') : '#';
                    console.log('Using fallback URL:', flickrUrl);
                }
                createOrUpdateLink(flickrUrl);
            }, 1000);
            return;
        }

        createOrUpdateLink(flickrUrl);
    }

    // Function to create or update the link with the correct URL
    function createOrUpdateLink(flickrUrl) {
        var detailsArea = document.querySelector('#slb_viewer_wrap .slb_details .inner');
        if (!detailsArea) return;

        var existingLink = document.querySelector('.flickr-lightbox-link');

        if (existingLink) {
            // Update existing link
            var linkElement = existingLink.querySelector('a');
            if (linkElement) {
                linkElement.href = flickrUrl;
                console.log('Updated existing Flickr link to:', flickrUrl);
            }
        } else {
            // Create new link
            var linkDiv = document.createElement('div');
            linkDiv.className = 'flickr-lightbox-link';

            linkDiv.innerHTML = '<div style="margin-top: 15px; text-align: center;">' +
                '<a href="' + flickrUrl + '" target="_blank" rel="noopener noreferrer" ' +
                'style="text-decoration: underline; font-size: 14px;">' +
                'View on Flickr</a>' +
                '</div>';

            detailsArea.appendChild(linkDiv);
            console.log('Created new Flickr link with URL:', flickrUrl);
        }
    }

    // Function to monitor for image changes in the lightbox
    function setupImageChangeMonitoring() {
        var lastImageSrc = '';

        function checkForImageChange() {
            var lightboxImg = document.querySelector('#slb_viewer_wrap img[src*="staticflickr.com"]');
            if (lightboxImg && lightboxImg.src !== lastImageSrc) {
                console.log('Image changed from', lastImageSrc, 'to', lightboxImg.src);
                lastImageSrc = lightboxImg.src;
                // Update the link after a short delay to ensure the image is fully loaded
                setTimeout(updateFlickrLink, 500);
            }
        }

        // Check for image changes every 500ms while lightbox is open
        var changeInterval = setInterval(function() {
            if (!document.querySelector('#slb_viewer_wrap')) {
                // Lightbox closed, stop monitoring
                clearInterval(changeInterval);
                return;
            }
            checkForImageChange();
        }, 500);
    }

    // Alternative approach: Monitor for lightbox opening
    function observeLightboxChanges() {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        // Check if lightbox content was added
                        if (node.className && node.className.indexOf &&
                            (node.className.indexOf('slb') !== -1 ||
                             node.querySelector && node.querySelector('[class*="slb"]'))) {
                            addLightboxNote();
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeLightboxChanges);
    } else {
        observeLightboxChanges();
    }

    // Also try to add note to any existing lightboxes
    addLightboxNote();

})();