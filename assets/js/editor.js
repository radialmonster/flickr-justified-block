(function(window) {
    'use strict';

    const RETRY_DELAY = 50;
    const MAX_ATTEMPTS = 40;

    function hasGutenbergRuntime(wp) {
        return !!(
            wp &&
            wp.blocks && typeof wp.blocks.registerBlockType === 'function' &&
            wp.i18n && typeof wp.i18n.__ === 'function' &&
            wp.components &&
            wp.blockEditor &&
            wp.element && typeof wp.element.createElement === 'function' &&
            typeof wp.apiFetch === 'function'
        );
    }

    function init(attempt) {
        const wp = window.wp;

        if (!hasGutenbergRuntime(wp)) {
            if (attempt < MAX_ATTEMPTS) {
                setTimeout(function() {
                    init(attempt + 1);
                }, RETRY_DELAY);
                return;
            }

            console.warn('Flickr Justified Block: Gutenberg packages not found after waiting – aborting block registration.');
            return;
        }

        const { registerBlockType, unregisterBlockType, getBlockType } = wp.blocks;
        const { __ } = wp.i18n;
        const {
            PanelBody,
            RangeControl,
            SelectControl,
            ToggleControl,
            TextControl,
            Button
        } = wp.components;
        const { InspectorControls, useBlockProps } = wp.blockEditor;
        const { createElement: el, useState, useEffect, useRef } = wp.element;

        // ================================================================
        // HELPERS
        // ================================================================

        function generateId() {
            return Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
        }

        function parseUrlsFromText(text) {
            if (!text || !text.trim()) return [];
            var lines = text.split(/[\r\n]+/).filter(function(l) { return l.trim(); });
            var results = [];
            lines.forEach(function(line) {
                var matches = line.match(/https?:\/\/[^\s]+/gi);
                if (matches && matches.length > 0) {
                    matches.forEach(function(u) { results.push(u.trim()); });
                } else if (line.trim()) {
                    results.push(line.trim());
                }
            });
            return results;
        }

        function urlsToImages(urls) {
            return urls.map(function(url) {
                return { id: generateId(), url: url, fullRow: false };
            });
        }

        // URL type detection helpers
        function isAlbumUrl(url) {
            return /(?:www\.)?flickr\.com\/photos\/[^/]+\/(sets|albums)\/\d+/i.test(url);
        }

        function isFlickrPhotoUrl(url) {
            return /(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test(url) && !isAlbumUrl(url);
        }

        // Flickr CDN thumbnail URLs we never want to add directly
        function isFlickrCdnUrl(url) {
            return /(?:live|farm\d*)\.staticflickr\.com\//i.test(url) ||
                   /flickr\.com\/photos\/[^/]+\/\d+\/sizes\//i.test(url);
        }

        function isSupportedUrl(url) {
            if (!url) return false;
            var trimmed = url.trim();
            // Flickr photo page or album URL — always good
            if (/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test(trimmed)) return true;
            // Direct image URL — but not Flickr CDN thumbnails
            if (/\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test(trimmed) && !isFlickrCdnUrl(trimmed)) return true;
            return false;
        }

        // Extract supported URLs from external drag-and-drop data.
        // Prioritizes Flickr photo page URLs over raw image/CDN URLs.
        function extractUrlsFromDropEvent(e) {
            var flickrPageUrls = [];
            var otherUrls = [];

            function collectUrl(u) {
                if (!isSupportedUrl(u)) return;
                if (/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test(u)) {
                    flickrPageUrls.push(u);
                } else {
                    otherUrls.push(u);
                }
            }

            function collectFromText(text) {
                var found = parseUrlsFromText(text);
                found.forEach(collectUrl);
            }

            // Try all data formats and collect everything
            var uriList = e.dataTransfer.getData('text/uri-list');
            if (uriList) {
                uriList.split(/[\r\n]+/).forEach(function(line) {
                    line = line.trim();
                    if (line && line.charAt(0) !== '#') collectFromText(line);
                });
            }

            var plain = e.dataTransfer.getData('text/plain');
            if (plain) collectFromText(plain);

            var html = e.dataTransfer.getData('text/html');
            if (html) {
                // Extract hrefs first (preferred — these are page URLs)
                var hrefMatches = html.match(/href="(https?:\/\/[^"]+)"/gi) || [];
                hrefMatches.forEach(function(attr) {
                    var m = attr.match(/"(https?:\/\/[^"]+)"/i);
                    if (m && m[1]) collectUrl(m[1]);
                });
                // Then src attributes (image URLs — only used if no page URLs found)
                var srcMatches = html.match(/src="(https?:\/\/[^"]+)"/gi) || [];
                srcMatches.forEach(function(attr) {
                    var m = attr.match(/"(https?:\/\/[^"]+)"/i);
                    if (m && m[1]) collectUrl(m[1]);
                });
            }

            // Prefer Flickr page URLs; only fall back to other URLs if none found
            var urls = flickrPageUrls.length > 0 ? flickrPageUrls : otherUrls;

            // Deduplicate
            var seen = {};
            return urls.filter(function(u) {
                if (seen[u]) return false;
                seen[u] = true;
                return true;
            });
        }

        // ================================================================
        // IMAGE CARD COMPONENT
        // ================================================================

        function ImageCard({ image, index, totalCount, isSelected, onSelect, onRemove, onToggleFullRow, onMove, dragOverIndex, dragIndex }) {
            const [imageData, setImageData] = useState(null);
            const [loading, setLoading] = useState(false);
            const [error, setError] = useState(null);
            const cardRef = useRef(null);

            var url = image.url;
            var urlIsAlbum = isAlbumUrl(url);
            // Only allow fullRow on non-album entries
            var showFullRow = !urlIsAlbum && image.fullRow;

            useEffect(function() {
                if (!url || !url.trim()) {
                    setImageData(null);
                    setError(null);
                    return;
                }

                var trimmedUrl = url.trim();
                var isCancelled = false;

                // Direct image URL
                var isImageUrl = /\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test(trimmedUrl);
                if (isImageUrl) {
                    if (!isCancelled) {
                        setImageData({ success: true, image_url: trimmedUrl, is_flickr: false });
                        setError(null);
                    }
                    return;
                }

                // Flickr URL detection
                var isFlickrPhoto = isFlickrPhotoUrl(trimmedUrl);
                var isFlickrSet = isAlbumUrl(trimmedUrl);
                var isFlickrUrl = isFlickrPhoto || isFlickrSet;

                if (!isFlickrUrl) {
                    if (!isCancelled) {
                        setImageData(null);
                        setError('Not a supported image URL');
                    }
                    return;
                }

                if (!isCancelled) {
                    setLoading(true);
                    setError(null);
                }

                wp.apiFetch({
                    path: '/flickr-justified/v1/preview-image',
                    method: 'POST',
                    data: { url: trimmedUrl }
                }).then(function(response) {
                    if (!isCancelled) {
                        if (response.success) {
                            setImageData(response);
                            setError(null);
                        } else {
                            setImageData(null);
                            setError('Failed to load image');
                        }
                    }
                }).catch(function(err) {
                    if (!isCancelled) {
                        setImageData(null);
                        setError('Error: ' + (err.message || 'Unknown'));
                    }
                }).finally(function() {
                    if (!isCancelled) setLoading(false);
                });

                return function() { isCancelled = true; };
            }, [url]);

            var isDragging = dragIndex === index;
            var isDropTarget = dragOverIndex === index;

            var cardClasses = 'fjb-image-card';
            if (isSelected) cardClasses += ' fjb-image-card--selected';
            if (showFullRow) cardClasses += ' fjb-image-card--full-row';
            if (isDragging) cardClasses += ' fjb-image-card--dragging';
            if (isDropTarget) cardClasses += ' fjb-image-card--drop-target';

            var cardStyle = {};
            if (showFullRow) {
                cardStyle.gridColumn = '1 / -1';
            }

            // Card content
            var cardContent;

            if (loading) {
                cardContent = el('div', { className: 'fjb-image-card__loading' },
                    el('span', { className: 'fjb-image-card__spinner' }),
                    el('span', null, __('Loading...', 'flickr-justified-block'))
                );
            } else if (imageData && imageData.success && imageData.is_set) {
                // Album card
                cardContent = el('div', { className: 'fjb-image-card__album' },
                    el('div', { className: 'fjb-image-card__album-icon' }, '\uD83D\uDCF8'),
                    imageData.album_title ? el('div', { className: 'fjb-image-card__album-title' }, imageData.album_title) : null,
                    el('div', { className: 'fjb-image-card__album-label' },
                        /\/with\/\d+/i.test(url.trim())
                            ? __('Flickr Album (with photo)', 'flickr-justified-block')
                            : __('Flickr Album', 'flickr-justified-block')
                    )
                );
            } else if (imageData && imageData.success && imageData.image_url) {
                // Photo thumbnail
                cardContent = el('img', {
                    src: imageData.image_url,
                    alt: '',
                    className: 'fjb-image-card__img',
                    draggable: false
                });
            } else if (error) {
                cardContent = el('div', { className: 'fjb-image-card__error' },
                    el('span', null, error)
                );
            } else {
                // Fallback: show URL
                cardContent = el('div', { className: 'fjb-image-card__url-fallback' },
                    el('span', null, url.trim())
                );
            }

            return el('div', {
                ref: cardRef,
                className: cardClasses,
                style: cardStyle,
                draggable: false,
                'data-index': index
            },
                // Number badge
                el('span', { className: 'fjb-image-card__badge' }, String(index + 1)),

                // Full row indicator (only for non-album images)
                showFullRow ? el('span', { className: 'fjb-image-card__fullrow-badge', title: __('Full width row', 'flickr-justified-block') }, '\u2194') : null,

                // Card content
                cardContent,

                // Hover overlay with actions
                el('div', { className: 'fjb-image-card__overlay' },
                    // Move up button
                    el('button', {
                        className: 'fjb-image-card__btn fjb-image-card__btn--move',
                        onClick: function(e) {
                            e.stopPropagation();
                            if (index > 0) onMove(index, index - 1);
                        },
                        title: __('Move up', 'flickr-justified-block'),
                        type: 'button',
                        disabled: index === 0
                    }, '\u2191'),
                    // Move down button
                    el('button', {
                        className: 'fjb-image-card__btn fjb-image-card__btn--move',
                        onClick: function(e) {
                            e.stopPropagation();
                            if (index < totalCount - 1) onMove(index, index + 1);
                        },
                        title: __('Move down', 'flickr-justified-block'),
                        type: 'button',
                        disabled: index === totalCount - 1
                    }, '\u2193'),
                    // Full row toggle (hidden for albums)
                    !urlIsAlbum ? el('button', {
                        className: 'fjb-image-card__btn fjb-image-card__btn--fullrow' + (image.fullRow ? ' fjb-image-card__btn--active' : ''),
                        onClick: function(e) {
                            e.stopPropagation();
                            onToggleFullRow(index);
                        },
                        title: image.fullRow ? __('Remove from own row', 'flickr-justified-block') : __('Put on own row', 'flickr-justified-block'),
                        type: 'button'
                    }, '\u2194') : null,
                    // Remove button
                    el('button', {
                        className: 'fjb-image-card__btn fjb-image-card__btn--remove',
                        onClick: function(e) {
                            e.stopPropagation();
                            onRemove(index);
                        },
                        title: __('Remove image', 'flickr-justified-block'),
                        type: 'button'
                    }, '\u2715')
                )
            );
        }

        // ================================================================
        // ADD IMAGES ZONE COMPONENT
        // ================================================================

        function AddImagesZone({ onAdd }) {
            const [inputValue, setInputValue] = useState('');
            const inputRef = useRef(null);

            function handleAdd() {
                var urls = parseUrlsFromText(inputValue);
                if (urls.length > 0) {
                    onAdd(urls);
                    setInputValue('');
                }
            }

            return el('div', { className: 'fjb-add-zone' },
                el('textarea', {
                    ref: inputRef,
                    className: 'fjb-add-zone__input',
                    placeholder: __('+ Paste Flickr or image URLs here (one per line)', 'flickr-justified-block'),
                    value: inputValue,
                    rows: 2,
                    onChange: function(e) {
                        setInputValue(e.target.value);
                    },
                    onKeyDown: function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            handleAdd();
                        }
                    },
                    onPaste: function() {
                        setTimeout(function() {
                            var val = inputRef.current ? inputRef.current.value : '';
                            var urls = parseUrlsFromText(val);
                            if (urls.length > 1) {
                                onAdd(urls);
                                setInputValue('');
                            }
                        }, 50);
                    }
                }),
                el(Button, {
                    variant: 'secondary',
                    className: 'fjb-add-zone__btn',
                    onClick: handleAdd,
                    disabled: !inputValue.trim()
                }, __('Add', 'flickr-justified-block'))
            );
        }

        // ================================================================
        // BLOCK REGISTRATION
        // ================================================================

        // Define the edit function
        function flickrJustifiedEdit(props) {
                const { attributes, setAttributes } = props;
                const {
                    urls,
                    images,
                    gap,
                    imageSize,
                    responsiveSettings,
                    rowHeightMode,
                    rowHeight,
                    maxViewportHeight,
                    singleImageAlignment,
                    maxPhotos,
                    sortOrder
                } = attributes;

                // --- Backward compat migration ---
                // If images array is empty but urls string has content, migrate once
                const migratedRef = useRef(false);
                useEffect(function() {
                    if (migratedRef.current) return;
                    if ((!images || images.length === 0) && urls && urls.trim()) {
                        var parsedUrls = parseUrlsFromText(urls);
                        if (parsedUrls.length > 0) {
                            setAttributes({ images: urlsToImages(parsedUrls), urls: '' });
                            migratedRef.current = true;
                        }
                    }
                }, []);

                // Ensure all images have IDs (handles blocks saved before IDs were added)
                var imagesList = (images && images.length > 0) ? images.map(function(img) {
                    if (img.id) return img;
                    return { id: generateId(), url: img.url, fullRow: !!img.fullRow };
                }) : [];

                const [selectedIndex, setSelectedIndex] = useState(null);
                const [dragIndex, setDragIndex] = useState(null);
                const [dragOverIndex, setDragOverIndex] = useState(null);
                const [externalDragOver, setExternalDragOver] = useState(false);
                const externalDragCountRef = useRef(0);
                const blockBodyRef = useRef(null);

                const blockProps = useBlockProps({
                    className: 'flickr-justified-block-editor',
                    ref: blockBodyRef
                });

                // --- Handlers (images is sole source of truth, no urls sync) ---

                function handleAddImages(newUrls) {
                    var existingUrls = {};
                    imagesList.forEach(function(img) { existingUrls[img.url] = true; });
                    var dedupedUrls = newUrls.filter(function(u) { return !existingUrls[u]; });
                    if (dedupedUrls.length === 0) return;
                    var newImages = imagesList.concat(urlsToImages(dedupedUrls));
                    setAttributes({ images: newImages });
                }

                function handleRemove(idx) {
                    var newImages = imagesList.filter(function(_, i) { return i !== idx; });
                    setAttributes({ images: newImages });
                    if (selectedIndex === idx) setSelectedIndex(null);
                    else if (selectedIndex !== null && selectedIndex > idx) setSelectedIndex(selectedIndex - 1);
                }

                function handleToggleFullRow(idx) {
                    var newImages = imagesList.map(function(img, i) {
                        if (i === idx) return { id: img.id, url: img.url, fullRow: !img.fullRow };
                        return img;
                    });
                    setAttributes({ images: newImages });
                }

                function handleSelect(idx) {
                    setSelectedIndex(selectedIndex === idx ? null : idx);
                }

                function handleMove(fromIdx, toIdx) {
                    var newImages = imagesList.slice();
                    var item = newImages.splice(fromIdx, 1)[0];
                    newImages.splice(toIdx, 0, item);
                    setAttributes({ images: newImages });
                    if (selectedIndex === fromIdx) setSelectedIndex(toIdx);
                    else if (selectedIndex !== null) {
                        var newSelected = selectedIndex;
                        if (fromIdx < selectedIndex && toIdx >= selectedIndex) newSelected--;
                        else if (fromIdx > selectedIndex && toIdx <= selectedIndex) newSelected++;
                        setSelectedIndex(newSelected);
                    }
                }

                function handleUpdateUrl(idx, newUrl) {
                    var newImages = imagesList.map(function(img, i) {
                        if (i === idx) return { id: img.id, url: newUrl, fullRow: img.fullRow };
                        return img;
                    });
                    setAttributes({ images: newImages });
                }

                // --- Pointer-based drag-to-reorder ---
                // Uses event delegation on the block container so we
                // never have stale per-card listeners after re-renders.
                // We stop propagation on pointerdown, mousedown,
                // touchstart, and dragstart to prevent Gutenberg from
                // entering its own block-drag mode.

                const DRAG_THRESHOLD = 5;

                const handleMoveRef = useRef(handleMove);
                handleMoveRef.current = handleMove;
                const handleSelectRef = useRef(handleSelect);
                handleSelectRef.current = handleSelect;

                function cardIndexFromPoint(x, y) {
                    var blockEl = blockBodyRef.current;
                    if (!blockEl) return null;
                    var cards = blockEl.querySelectorAll('.fjb-image-card');
                    for (var i = 0; i < cards.length; i++) {
                        var rect = cards[i].getBoundingClientRect();
                        if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                            var idx = parseInt(cards[i].getAttribute('data-index'), 10);
                            return isNaN(idx) ? null : idx;
                        }
                    }
                    return null;
                }

                function findCardFromEvent(e) {
                    var target = e.target;
                    if (!target || !target.closest) return null;
                    return target.closest('.fjb-image-card');
                }

                // Event delegation on the block container — handles all
                // current and future cards without per-element binding.
                useEffect(function() {
                    var blockEl = blockBodyRef.current;
                    if (!blockEl) return;

                    // Prevent Gutenberg's block-drag on mousedown/touchstart/dragstart
                    function stopGutenbergDrag(e) {
                        var card = findCardFromEvent(e);
                        if (!card) return;
                        if (e.target.closest('.fjb-image-card__overlay')) return;
                        e.stopPropagation();
                    }
                    function stopDragStart(e) {
                        var card = findCardFromEvent(e);
                        if (!card) return;
                        e.stopPropagation();
                        e.preventDefault();
                    }

                    blockEl.addEventListener('mousedown', stopGutenbergDrag, true);
                    blockEl.addEventListener('touchstart', stopGutenbergDrag, true);
                    blockEl.addEventListener('dragstart', stopDragStart, true);

                    // Pointer-based drag via event delegation
                    function onPointerDown(e) {
                        if (e.button !== 0) return;
                        var card = findCardFromEvent(e);
                        if (!card) return;
                        if (e.target.closest('.fjb-image-card__overlay')) return;
                        if (e.target.closest('.fjb-add-zone')) return;

                        var idx = parseInt(card.getAttribute('data-index'), 10);
                        if (isNaN(idx)) return;

                        e.stopPropagation();
                        e.preventDefault();

                        try {
                            card.setPointerCapture(e.pointerId);
                        } catch (err) {
                            return;
                        }

                        var startX = e.clientX;
                        var startY = e.clientY;
                        var didMove = false;

                        function onMove(ev) {
                            var dx = ev.clientX - startX;
                            var dy = ev.clientY - startY;
                            if (!didMove) {
                                if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
                                didMove = true;
                                setDragIndex(idx);
                            }
                            var overIdx = cardIndexFromPoint(ev.clientX, ev.clientY);
                            if (overIdx !== null && overIdx !== idx) {
                                setDragOverIndex(overIdx);
                            } else {
                                setDragOverIndex(null);
                            }
                        }

                        function cleanup() {
                            card.removeEventListener('pointermove', onMove);
                            card.removeEventListener('pointerup', onUp);
                            card.removeEventListener('lostpointercapture', onLost);
                            setDragIndex(null);
                            setDragOverIndex(null);
                        }

                        function onUp(ev) {
                            if (didMove) {
                                var dropIdx = cardIndexFromPoint(ev.clientX, ev.clientY);
                                if (dropIdx !== null && dropIdx !== idx) {
                                    handleMoveRef.current(idx, dropIdx);
                                }
                            } else {
                                handleSelectRef.current(idx);
                            }
                            cleanup();
                        }

                        function onLost() {
                            cleanup();
                        }

                        card.addEventListener('pointermove', onMove);
                        card.addEventListener('pointerup', onUp);
                        card.addEventListener('lostpointercapture', onLost);
                    }

                    blockEl.addEventListener('pointerdown', onPointerDown, true);

                    return function() {
                        blockEl.removeEventListener('mousedown', stopGutenbergDrag, true);
                        blockEl.removeEventListener('touchstart', stopGutenbergDrag, true);
                        blockEl.removeEventListener('dragstart', stopDragStart, true);
                        blockEl.removeEventListener('pointerdown', onPointerDown, true);
                    };
                }, []);

                // --- External drop handlers (for dragging from Flickr/browser) ---
                // These still use the HTML5 DnD API since external drops require it.
                const addImagesRef = useRef(handleAddImages);
                addImagesRef.current = handleAddImages;

                function handleExternalDragOver(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.dataTransfer.dropEffect = 'copy';
                }

                function handleExternalDragEnter(e) {
                    e.preventDefault();
                    externalDragCountRef.current++;
                    setExternalDragOver(true);
                }

                function handleExternalDragLeave() {
                    externalDragCountRef.current--;
                    if (externalDragCountRef.current <= 0) {
                        externalDragCountRef.current = 0;
                        setExternalDragOver(false);
                    }
                }

                function handleExternalDrop(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var urls = extractUrlsFromDropEvent(e);
                    if (urls.length > 0) {
                        handleAddImages(urls);
                    }
                    externalDragCountRef.current = 0;
                    setExternalDragOver(false);
                }

                // Capture-phase listeners for external drops only — prevents
                // WP from swallowing drops of URLs from other browser tabs.
                useEffect(function() {
                    var blockEl = blockBodyRef.current;
                    if (!blockEl) return;

                    function isInsideBlock(e) {
                        return blockEl.contains(e.target);
                    }

                    function onDropCapture(e) {
                        if (!isInsideBlock(e)) return;
                        var urls = extractUrlsFromDropEvent(e);
                        if (urls.length > 0) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            addImagesRef.current(urls);
                            externalDragCountRef.current = 0;
                            setExternalDragOver(false);
                        }
                    }

                    function onDragOverCapture(e) {
                        if (!isInsideBlock(e)) return;
                        var types = e.dataTransfer.types || [];
                        var hasExternal = types.indexOf('text/uri-list') !== -1 ||
                                          types.indexOf('text/plain') !== -1 ||
                                          types.indexOf('text/html') !== -1;
                        if (hasExternal) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            e.dataTransfer.dropEffect = 'copy';
                        }
                    }

                    document.addEventListener('drop', onDropCapture, true);
                    document.addEventListener('dragover', onDragOverCapture, true);

                    return function() {
                        document.removeEventListener('drop', onDropCapture, true);
                        document.removeEventListener('dragover', onDragOverCapture, true);
                    };
                }, []);

                // --- Size options ---
                var sizeOptions = [
                    { label: __('Medium', 'flickr-justified-block'), value: 'medium' },
                    { label: __('Large', 'flickr-justified-block'), value: 'large' },
                    { label: __('Large 1600px', 'flickr-justified-block'), value: 'large1600' },
                    { label: __('Large 2048px', 'flickr-justified-block'), value: 'large2048' },
                    { label: __('Original', 'flickr-justified-block'), value: 'original' }
                ];

                // --- Selected image for sidebar ---
                var selectedImage = (selectedIndex !== null && imagesList[selectedIndex]) ? imagesList[selectedIndex] : null;
                var selectedIsAlbum = selectedImage ? isAlbumUrl(selectedImage.url) : false;

                return el('div', {},
                    // Inspector Controls (Sidebar)
                    el(InspectorControls, {},

                        // Selected Image Panel (conditional)
                        selectedImage ? el(PanelBody, {
                            title: __('Selected Image', 'flickr-justified-block') + ' #' + (selectedIndex + 1),
                            initialOpen: true
                        },
                            el(TextControl, {
                                label: __('URL', 'flickr-justified-block'),
                                value: selectedImage.url,
                                onChange: function(value) {
                                    handleUpdateUrl(selectedIndex, value);
                                }
                            }),
                            // Only show full-row toggle for non-album URLs
                            !selectedIsAlbum ? el(ToggleControl, {
                                label: __('Full width row', 'flickr-justified-block'),
                                help: selectedImage.fullRow
                                    ? __('This image will display on its own row, filling the full width.', 'flickr-justified-block')
                                    : __('This image will share a row with other images.', 'flickr-justified-block'),
                                checked: selectedImage.fullRow,
                                onChange: function() {
                                    handleToggleFullRow(selectedIndex);
                                }
                            }) : el('p', {
                                style: { fontSize: '12px', color: '#666', fontStyle: 'italic' }
                            }, __('Full width row is not available for albums. Album photos are expanded into individual images on the frontend.', 'flickr-justified-block')),
                            el(Button, {
                                variant: 'secondary',
                                isDestructive: true,
                                onClick: function() { handleRemove(selectedIndex); }
                            }, __('Remove Image', 'flickr-justified-block'))
                        ) : null,

                        el(PanelBody, {
                            title: __('Gallery Settings', 'flickr-justified-block'),
                            initialOpen: !selectedImage
                        },
                            el(SelectControl, {
                                label: __('Gallery Image Size', 'flickr-justified-block'),
                                help: __('Choose the size for images displayed in the gallery grid. Larger sizes provide better quality but slower loading.', 'flickr-justified-block'),
                                value: imageSize,
                                options: sizeOptions,
                                onChange: function(value) {
                                    setAttributes({ imageSize: value });
                                }
                            }),
                            el(TextControl, {
                                label: __('Show how many images', 'flickr-justified-block'),
                                help: __('Enter 0 to show all images. Use a positive number to limit how many images display for this block.', 'flickr-justified-block'),
                                type: 'number',
                                min: 0,
                                value: typeof maxPhotos === 'number' ? maxPhotos : 0,
                                onChange: function(value) {
                                    var parsed = parseInt(value, 10);
                                    setAttributes({ maxPhotos: isNaN(parsed) || parsed < 0 ? 0 : parsed });
                                }
                            }),
                            el(SelectControl, {
                                label: __('Sort images', 'flickr-justified-block'),
                                help: __('Choose how to order the images that appear in this gallery.', 'flickr-justified-block'),
                                value: sortOrder || 'input',
                                options: [
                                    { label: __('As entered', 'flickr-justified-block'), value: 'input' },
                                    { label: __('Views (high to low)', 'flickr-justified-block'), value: 'views_desc' }
                                ],
                                onChange: function(value) {
                                    setAttributes({ sortOrder: value || 'input' });
                                }
                            }),
                            el('p', {
                                style: { fontSize: '12px', color: '#666', margin: '16px 0 12px' }
                            }, __('Images use built-in PhotoSwipe lightbox optimized for high-resolution displays. The plugin automatically selects the best available size from Flickr.', 'flickr-justified-block')),
                            el(RangeControl, {
                                label: __('Grid gap (px)', 'flickr-justified-block'),
                                help: __('Space between images in the justified gallery.', 'flickr-justified-block'),
                                min: 0,
                                max: 64,
                                step: 1,
                                value: gap ?? 12,
                                onChange: function(value) {
                                    setAttributes({ gap: value ?? 12 });
                                }
                            }),
                            el(SelectControl, {
                                label: __('Row height mode', 'flickr-justified-block'),
                                help: __('Auto adjusts row height to fill container width perfectly. Fixed uses a specific pixel height.', 'flickr-justified-block'),
                                value: rowHeightMode || 'auto',
                                options: [
                                    { label: __('Auto (fill width)', 'flickr-justified-block'), value: 'auto' },
                                    { label: __('Fixed height', 'flickr-justified-block'), value: 'fixed' }
                                ],
                                onChange: function(value) {
                                    setAttributes({ rowHeightMode: value || 'auto' });
                                }
                            }),
                            (rowHeightMode === 'fixed') && el(RangeControl, {
                                label: __('Row height (px)', 'flickr-justified-block'),
                                help: __('Fixed height for all gallery rows. Images will scale to fit this height.', 'flickr-justified-block'),
                                min: 120,
                                max: 500,
                                step: 10,
                                value: rowHeight ?? 280,
                                onChange: function(value) {
                                    setAttributes({ rowHeight: value ?? 280 });
                                }
                            }),
                            el(RangeControl, {
                                label: __('Max viewport height (%)', 'flickr-justified-block'),
                                help: __('Limit image height to a percentage of the browser window height. Prevents very large images from exceeding screen size.', 'flickr-justified-block'),
                                min: 30,
                                max: 100,
                                step: 5,
                                value: maxViewportHeight ?? 80,
                                onChange: function(value) {
                                    setAttributes({ maxViewportHeight: value ?? 80 });
                                }
                            }),
                            el(SelectControl, {
                                label: __('Single image alignment', 'flickr-justified-block'),
                                help: __('Horizontal alignment when there is only one image in the entire gallery.', 'flickr-justified-block'),
                                value: singleImageAlignment || 'center',
                                options: [
                                    { label: __('Left', 'flickr-justified-block'), value: 'left' },
                                    { label: __('Center', 'flickr-justified-block'), value: 'center' },
                                    { label: __('Right', 'flickr-justified-block'), value: 'right' }
                                ],
                                onChange: function(value) {
                                    setAttributes({ singleImageAlignment: value || 'center' });
                                }
                            })
                        ),
                        el(PanelBody, {
                            title: __('Responsive Settings', 'flickr-justified-block'),
                            initialOpen: false
                        },
                            el('p', {
                                style: { fontSize: '13px', color: '#666', marginBottom: '16px' }
                            }, __('Configure how many images per row to display at different screen sizes. Breakpoint sizes are configured in Settings - Flickr Justified.', 'flickr-justified-block')),

                            (() => {
                                var breakpointLabels = {
                                    mobile: __('Mobile Portrait', 'flickr-justified-block'),
                                    mobile_landscape: __('Mobile Landscape', 'flickr-justified-block'),
                                    tablet_portrait: __('Tablet Portrait', 'flickr-justified-block'),
                                    tablet_landscape: __('Tablet Landscape', 'flickr-justified-block'),
                                    desktop: __('Desktop/Laptop', 'flickr-justified-block'),
                                    large_desktop: __('Large Desktop', 'flickr-justified-block'),
                                    extra_large: __('Ultra-Wide Screens', 'flickr-justified-block')
                                };

                                return Object.keys(breakpointLabels).map(function(breakpointKey) {
                                    return el(RangeControl, {
                                        key: breakpointKey,
                                        label: breakpointLabels[breakpointKey],
                                        min: 1,
                                        max: 8,
                                        step: 1,
                                        value: (responsiveSettings && responsiveSettings[breakpointKey]) || 1,
                                        onChange: function(value) {
                                            var newResponsiveSettings = Object.assign({}, responsiveSettings);
                                            newResponsiveSettings[breakpointKey] = value || 1;
                                            setAttributes({ responsiveSettings: newResponsiveSettings });
                                        }
                                    });
                                });
                            })()
                        )
                    ),

                    // Block Body - Visual Card Grid
                    el('div', blockProps,
                        imagesList.length > 0 ?
                            el('div', {
                                className: 'fjb-card-grid' + (externalDragOver ? ' fjb-card-grid--drop-active' : ''),
                                onDragOver: handleExternalDragOver,
                                onDragEnter: handleExternalDragEnter,
                                onDragLeave: handleExternalDragLeave,
                                onDrop: handleExternalDrop
                            },
                                imagesList.map(function(image, index) {
                                    return el(ImageCard, {
                                        key: image.id,
                                        image: image,
                                        index: index,
                                        totalCount: imagesList.length,
                                        isSelected: selectedIndex === index,
                                        onSelect: handleSelect,
                                        onRemove: handleRemove,
                                        onToggleFullRow: handleToggleFullRow,
                                        onMove: handleMove,
                                        dragOverIndex: dragOverIndex,
                                        dragIndex: dragIndex
                                    });
                                }),
                                el(AddImagesZone, { onAdd: handleAddImages })
                            ) :
                            el('div', {
                                className: 'fjb-empty-state' + (externalDragOver ? ' fjb-empty-state--drop-active' : ''),
                                onDragOver: handleExternalDragOver,
                                onDragEnter: handleExternalDragEnter,
                                onDragLeave: handleExternalDragLeave,
                                onDrop: handleExternalDrop
                            },
                                el('p', { className: 'fjb-empty-state__title' }, __('Flickr Justified Block', 'flickr-justified-block')),
                                el('p', { className: 'fjb-empty-state__subtitle' }, __('Paste Flickr or image URLs below to create your gallery.', 'flickr-justified-block')),
                                el(AddImagesZone, { onAdd: handleAddImages })
                            )
                    )
                );
        }

        function flickrJustifiedSave() {
            return null;
        }

        // Build full block settings from PHP-provided block.json metadata.
        // wp_localize_script injects window.flickrJustifiedBlockMeta with
        // the parsed block.json (attributes, supports, keywords, etc.).
        var meta = window.flickrJustifiedBlockMeta || {};
        var blockSettings = {
            title: meta.title || 'Flickr Justified',
            category: meta.category || 'media',
            icon: meta.icon || 'format-gallery',
            description: meta.description || '',
            keywords: meta.keywords || [],
            supports: meta.supports || {},
            attributes: meta.attributes || {},
            edit: flickrJustifiedEdit,
            save: flickrJustifiedSave
        };

        // If the block was already registered (e.g. by another script),
        // unregister first to avoid a duplicate-type error.
        var existingBlock = getBlockType('flickr-justified/block');
        if (existingBlock) {
            unregisterBlockType('flickr-justified/block');
        }
        registerBlockType('flickr-justified/block', blockSettings);
    }

    init(0);
})(window);
