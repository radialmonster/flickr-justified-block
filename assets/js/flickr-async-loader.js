(function() {
    function decodeAttributes(container) {
        var rawB64 = container.getAttribute('data-attributes-b64');
        if (!rawB64) {
            return null;
        }
        try {
            var decoded = atob(rawB64);
            return JSON.parse(decoded);
        } catch (e) {
            console.error('Flickr Gallery: Failed to decode attributes', e);
            return null;
        }
    }

    function setStatus(container, text) {
        var textEl = container.querySelector('.flickr-loading-text');
        if (textEl) {
            textEl.textContent = text;
        }
    }

    function initGallery(newBlock) {
        try {
            if (window.flickrJustified && window.flickrJustified.initGallery) {
                window.flickrJustified.initGallery();
            }
            setTimeout(function() {
                if (window.flickrJustified && window.flickrJustified.initAlbumLazyLoading) {
                    window.flickrJustified.initAlbumLazyLoading();
                }
                var event = new CustomEvent('flickr-gallery-updated', { detail: { gallery: newBlock } });
                document.dispatchEvent(event);
                if (newBlock && newBlock.focus) {
                    newBlock.setAttribute('tabindex', '-1');
                    newBlock.focus({ preventScroll: true });
                }
            }, 200);
        } catch (e) {
            console.error('Flickr Gallery: Initialization failed after async load', e);
        }
    }

    function loadGallery(container) {
        container.setAttribute('aria-busy', 'true');
        var retryBtn = container.querySelector('.flickr-loading-retry-btn');
        if (retryBtn) {
            retryBtn.style.display = 'none';
        }

        var attrs = decodeAttributes(container);
        if (!attrs) {
            setStatus(container, 'Missing gallery data');
            if (retryBtn) {
                retryBtn.style.display = 'inline-block';
            }
            return;
        }

        var postId = '';
        var bodyClasses = document.body.className.match(/postid-(\d+)/);
        if (bodyClasses && bodyClasses[1]) {
            postId = bodyClasses[1];
        }

        var form = new URLSearchParams();
        form.set('action', 'flickr_justified_load_async');
        form.set('attributes', JSON.stringify(attrs));
        form.set('post_id', postId);
        form.set('nonce', (window.flickrJustifiedAsync && window.flickrJustifiedAsync.nonce) || '');

        fetch((window.flickrJustifiedAsync && window.flickrJustifiedAsync.ajaxurl) || '', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'same-origin',
            body: form.toString()
        }).then(function(res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        }).then(function(response) {
            if (response && response.success && response.data && response.data.html) {
                container.outerHTML = response.data.html;
                var newBlock = attrs._target_gallery_id ? document.getElementById(attrs._target_gallery_id) : document.querySelector('.flickr-justified-grid');
                if (newBlock) {
                    initGallery(newBlock);
                }
            } else {
                throw new Error('Bad response');
            }
        }).catch(function(err) {
            console.error('Flickr Gallery: Async load failed', err);
            setStatus(container, 'Failed to load gallery');
            container.setAttribute('aria-busy', 'false');
            if (retryBtn) {
                retryBtn.style.display = 'inline-block';
            }
        });
    }

    function attach(container) {
        if (container.getAttribute('data-fjb-processed')) {
            return;
        }
        container.setAttribute('data-fjb-processed', '1');

        var retryBtn = container.querySelector('.flickr-loading-retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function() {
                loadGallery(container);
            });
        }

        loadGallery(container);
    }

    function scan() {
        var nodes = document.querySelectorAll('.flickr-justified-loading');
        nodes.forEach(function(node) {
            attach(node);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
