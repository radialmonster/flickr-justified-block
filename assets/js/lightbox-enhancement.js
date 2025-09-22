/**
 * Multi-Lightbox Enhancement for Flickr Justified Block
 * Adds Flickr attribution links for various lightbox plugins
 */
(function() {
    'use strict';

    // Get attribution settings from the gallery and first item
    function getAttributionSettings() {
        var gallery = document.querySelector('.flickr-justified-grid[data-attribution-mode]');
        var firstItem = document.querySelector('.flickr-card a[data-flickr-attribution-text]');

        if (!gallery && !firstItem) return null;

        return {
            text: firstItem ? (firstItem.getAttribute('data-flickr-attribution-text') || 'View on Flickr') : 'View on Flickr',
            mode: gallery ? gallery.getAttribute('data-attribution-mode') : 'lightbox_button'
        };
    }

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
        var settings = getAttributionSettings();
        if (!settings || settings.mode !== 'lightbox_button') {
            return; // Attribution disabled or using different method
        }

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
        var settings = getAttributionSettings();
        if (!settings) return;

        var detailsArea = document.querySelector('#slb_viewer_wrap .slb_details .inner');
        if (!detailsArea) return;

        var existingLink = document.querySelector('.flickr-lightbox-link');

        if (existingLink) {
            // Update existing link
            var linkElement = existingLink.querySelector('a');
            if (linkElement) {
                linkElement.href = flickrUrl;
                linkElement.textContent = settings.text;
                console.log('Updated existing Flickr link to:', flickrUrl);
            }
        } else {
            // Create new link
            var linkDiv = document.createElement('div');
            linkDiv.className = 'flickr-lightbox-link';

            linkDiv.innerHTML = '<div style="margin-top: 15px; text-align: center;">' +
                '<a href="' + flickrUrl + '" target="_blank" rel="noopener noreferrer" ' +
                'style="text-decoration: underline; font-size: 14px;">' +
                settings.text + '</a>' +
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

    // PhotoSwipe Integration
    function initPhotoSwipeIntegration() {
        // Set up event listeners immediately - they will trigger when PhotoSwipe initializes
        setupPhotoSwipeAttribution();

        // Also check if PhotoSwipe is available and manually trigger setup
        if (typeof PhotoSwipeLightbox !== 'undefined') {
            console.log('PhotoSwipeLightbox found immediately');
        } else {
            // Check periodically for PhotoSwipe
            var checkCount = 0;
            var checkInterval = setInterval(function() {
                if (typeof PhotoSwipeLightbox !== 'undefined' || checkCount > 40) {
                    clearInterval(checkInterval);
                    if (typeof PhotoSwipeLightbox !== 'undefined') {
                        console.log('PhotoSwipeLightbox found after waiting');
                    }
                }
                checkCount++;
            }, 250);
        }
    }

    function setupPhotoSwipeAttribution() {
        var settings = getAttributionSettings();
        if (!settings || (settings.mode !== 'lightbox_button' && settings.mode !== 'data_attributes')) return;

        // Wait for PhotoSwipe lightbox instances to be created
        document.addEventListener('pswp:uiRegister', function(e) {
            console.log('PhotoSwipe uiRegister event triggered', e.detail);

            e.detail.pswp.ui.registerElement({
                name: 'flickr-attribution',
                order: 8,
                isButton: true,
                tagName: 'a',
                html: settings.text,
                onInit: function(el, pswp) {
                    console.log('PhotoSwipe attribution button initialized');

                    el.setAttribute('target', '_blank');
                    el.setAttribute('rel', 'noopener');
                    el.style.fontSize = '14px';
                    el.style.textDecoration = 'underline';
                    el.style.color = '#fff';
                    el.style.padding = '8px';

                    // Set initial URL
                    updateAttributionUrl(el, pswp);

                    // Update link when slide changes
                    pswp.on('change', function() {
                        console.log('PhotoSwipe slide changed');
                        updateAttributionUrl(el, pswp);
                    });

                    // Handle click manually to ensure it works
                    el.addEventListener('click', function(event) {
                        event.stopPropagation();
                        var href = el.getAttribute('href');
                        if (href && href !== '#') {
                            window.open(href, '_blank', 'noopener,noreferrer');
                        }
                    });
                }
            });
        });

        function updateAttributionUrl(el, pswp) {
            try {
                var currentSlide = pswp.currSlide;
                console.log('Current slide:', currentSlide);

                if (currentSlide && currentSlide.data) {
                    var flickrUrl = null;

                    // Try to get Flickr URL from the slide's original element
                    if (currentSlide.data.element) {
                        flickrUrl = currentSlide.data.element.getAttribute('data-flickr-page');
                        console.log('Found Flickr URL from element:', flickrUrl);
                    }

                    // Fallback: try to find by matching image src or photo ID
                    if (!flickrUrl && currentSlide.data.src) {
                        var allLinks = document.querySelectorAll('.flickr-card a[data-flickr-page]');
                        for (var i = 0; i < allLinks.length; i++) {
                            var link = allLinks[i];

                            // Direct URL match
                            if (link.href === currentSlide.data.src) {
                                flickrUrl = link.getAttribute('data-flickr-page');
                                console.log('Found Flickr URL by exact URL match:', flickrUrl);
                                break;
                            }

                            // Photo ID match (for Flickr images)
                            if (currentSlide.data.src.includes('staticflickr.com')) {
                                var lightboxPhotoId = currentSlide.data.src.split('/').pop().split('_')[0];
                                var galleryPhotoId = link.href.split('/').pop().split('_')[0];

                                if (lightboxPhotoId === galleryPhotoId) {
                                    flickrUrl = link.getAttribute('data-flickr-page');
                                    console.log('Found Flickr URL by photo ID match:', flickrUrl);
                                    break;
                                }
                            }
                        }
                    }

                    if (flickrUrl) {
                        el.href = flickrUrl;
                        el.style.opacity = '1';
                        console.log('Set attribution URL to:', flickrUrl);
                    } else {
                        el.href = '#';
                        el.style.opacity = '0.5';
                        console.log('No Flickr URL found, hiding attribution');
                    }
                }
            } catch (error) {
                console.error('Error updating PhotoSwipe attribution URL:', error);
            }
        }
    }

    // Initialize PhotoSwipe integration
    initPhotoSwipeIntegration();

    // GLightbox/FancyBox Integration (they often use data-caption)
    function initOtherLightboxIntegration() {
        var settings = getAttributionSettings();
        if (!settings || settings.mode === 'disabled') return;

        // For lightboxes that support custom captions, the data-caption attribute
        // is already set in the render logic with the Flickr URL and attribution text
        console.log('Attribution data attributes ready for lightbox plugins');
    }

    initOtherLightboxIntegration();

})();