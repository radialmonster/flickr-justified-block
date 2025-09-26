(function(wp) {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const {
        PanelBody,
        TextareaControl,
        RangeControl,
        SelectControl,
        ToggleControl,
        TextControl
    } = wp.components;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { createElement: el, useState, useEffect } = wp.element;

    // Image preview component
    function ImagePreview({ url, index }) {
        const [imageData, setImageData] = useState(null);
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState(null);

        useEffect(() => {
            if (!url || !url.trim()) {
                setImageData(null);
                setError(null);
                return;
            }

            const trimmedUrl = url.trim();
            let isCancelled = false;

            // Check if it's a direct image URL first
            const isImageUrl = /\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test(trimmedUrl);
            if (isImageUrl) {
                if (!isCancelled) {
                    setImageData({
                        success: true,
                        image_url: trimmedUrl,
                        is_flickr: false
                    });
                    setError(null);
                }
                return;
            }

            // Check if it's a Flickr URL (photo or set/album)
            const isFlickrPhoto = /flickr\.com\/photos\/[^/]+\/\d+/i.test(trimmedUrl);
            const isFlickrSet = /flickr\.com\/photos\/[^/]+\/(sets|albums)\/\d+/i.test(trimmedUrl);
            const isFlickrUrl = isFlickrPhoto || isFlickrSet;

            if (!isFlickrUrl) {
                if (!isCancelled) {
                    setImageData(null);
                    setError('Not a supported image URL');
                }
                return;
            }

            // Fetch Flickr image data
            if (!isCancelled) {
                setLoading(true);
                setError(null);
            }

            wp.apiFetch({
                path: '/flickr-justified/v1/preview-image',
                method: 'POST',
                data: {
                    url: trimmedUrl
                }
            }).then((response) => {
                if (!isCancelled) {
                    if (response.success) {
                        setImageData(response);
                        setError(null);
                    } else {
                        setImageData(null);
                        setError('Failed to load image');
                    }
                }
            }).catch((err) => {
                if (!isCancelled) {
                    setImageData(null);
                    setError('Error loading image: ' + (err.message || 'Unknown error'));
                }
            }).finally(() => {
                if (!isCancelled) {
                    setLoading(false);
                }
            });

            // Cleanup function to prevent state updates if component unmounts
            return () => {
                isCancelled = true;
            };
        }, [url]);

        if (loading) {
            return el('div', {
                key: index,
                className: 'flickr-justified-item-preview',
                style: {
                    display: 'inline-block',
                    width: '100%',
                    marginBottom: 'var(--fm-gap)',
                    breakInside: 'avoid'
                }
            },
                el('div', {
                    style: {
                        padding: '20px',
                        border: '2px dashed #ccc',
                        borderRadius: '4px',
                        textAlign: 'center',
                        color: '#666',
                        backgroundColor: '#f9f9f9'
                    }
                }, __('Loading...', 'flickr-justified-block'))
            );
        }

        if (imageData && imageData.success) {
            // Check if this is a Flickr set/album
            if (imageData.is_set) {
                return el('div', {
                    key: index,
                    className: 'flickr-justified-item-preview',
                    style: {
                        display: 'inline-block',
                        width: '100%',
                        marginBottom: 'var(--fm-gap)',
                        breakInside: 'avoid'
                    }
                },
                    el('a', {
                        href: url.trim(),
                        onClick: function(e) {
                            e.preventDefault();
                        },
                        style: {
                            display: 'block',
                            textDecoration: 'none'
                        }
                    },
                        el('div', {
                            style: {
                                padding: '20px',
                                border: '2px solid #0073aa',
                                borderRadius: '4px',
                                backgroundColor: '#f0f6fc',
                                textAlign: 'center',
                                color: '#0073aa'
                            }
                        },
                            el('div', {
                                style: {
                                    fontSize: '24px',
                                    marginBottom: '8px'
                                }
                            }, '📁'),
                            el('div', {
                                style: {
                                    fontWeight: 'bold',
                                    marginBottom: '4px'
                                }
                            }, __('Flickr Album/Set', 'flickr-justified-block')),
                            el('div', {
                                style: {
                                    fontSize: '14px',
                                    opacity: '0.8'
                                }
                            }, imageData.photo_count + ' ' + __('photos', 'flickr-justified-block'))
                        )
                    )
                );
            }

            // Regular single photo
            return el('div', {
                key: index,
                className: 'flickr-justified-item-preview',
                style: {
                    display: 'inline-block',
                    width: '100%',
                    marginBottom: 'var(--fm-gap)',
                    breakInside: 'avoid'
                }
            },
                el('a', {
                    href: url.trim(),
                    onClick: function(e) {
                        e.preventDefault();
                    },
                    style: {
                        display: 'block',
                        textDecoration: 'none'
                    }
                },
                    el('img', {
                        src: imageData.image_url,
                        alt: '',
                        style: {
                            width: '100%',
                            height: 'auto',
                            display: 'block',
                            borderRadius: '4px'
                        }
                    })
                )
            );
        }

        // Show URL text for unsupported URLs or errors
        return el('div', {
            key: index,
            className: 'flickr-justified-item-preview',
            style: {
                display: 'inline-block',
                width: '100%',
                marginBottom: 'var(--fm-gap)',
                breakInside: 'avoid'
            }
        },
            el('a', {
                href: url.trim(),
                onClick: function(e) {
                    e.preventDefault();
                },
                style: {
                    display: 'block',
                    textDecoration: 'none'
                }
            },
                el('div', {
                    style: {
                        padding: '12px',
                        border: '2px dashed #ccc',
                        borderRadius: '4px',
                        fontSize: '12px',
                        wordBreak: 'break-all',
                        color: '#666',
                        backgroundColor: '#f9f9f9'
                    }
                },
                    (() => {
                        const urlTrimmed = url.trim();
                        if (/flickr\.com\/photos\/[^/]+\/(sets|albums)\/\d+/i.test(urlTrimmed)) {
                            return __('Flickr Set/Album: ', 'flickr-justified-block') + urlTrimmed;
                        } else if (/flickr\.com\/photos\/[^/]+\/\d+/i.test(urlTrimmed)) {
                            return __('Flickr Photo: ', 'flickr-justified-block') + urlTrimmed;
                        } else {
                            return __('URL: ', 'flickr-justified-block') + urlTrimmed;
                        }
                    })()
                )
            )
        );
    }

    registerBlockType('flickr-justified/block', {
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const {
                urls,
                gap,
                imageSize,
                responsiveSettings,
                rowHeightMode,
                rowHeight,
                maxViewportHeight,
                singleImageAlignment
            } = attributes;

            const blockProps = useBlockProps({
                className: 'flickr-justified-block-editor'
            });

            // Split URLs by lines, then handle multiple URLs on same line
            let urlArray = urls ? urls.split(/\r?\n/).filter(url => url.trim()) : [];

            // Further split any lines that contain multiple URLs (common when copy-pasting)
            const finalUrls = [];
            urlArray.forEach(line => {
                // Check if line contains multiple URLs by looking for http/https patterns
                const urlMatches = line.match(/https?:\/\/[^\s]+/gi);
                if (urlMatches && urlMatches.length > 0) {
                    urlMatches.forEach(url => {
                        finalUrls.push(url.trim());
                    });
                } else if (line.trim()) {
                    // Single URL or non-URL content
                    finalUrls.push(line.trim());
                }
            });
            urlArray = finalUrls;

            const sizeOptions = [
                { label: __('Medium', 'flickr-justified-block'), value: 'medium' },
                { label: __('Large', 'flickr-justified-block'), value: 'large' },
                { label: __('Large 1600px', 'flickr-justified-block'), value: 'large1600' },
                { label: __('Large 2048px', 'flickr-justified-block'), value: 'large2048' },
                { label: __('Original', 'flickr-justified-block'), value: 'original' }
            ];

            return el('div', {},
                // Inspector Controls (Sidebar)
                el(InspectorControls, {},
                    el(PanelBody, {
                        title: __('Gallery Settings', 'flickr-justified-block'),
                        initialOpen: true
                    },
                        el(TextareaControl, {
                            label: __('Image URLs (one per line)', 'flickr-justified-block'),
                            help: __('Paste Flickr photo page URLs or direct image URLs. One URL per line.', 'flickr-justified-block'),
                            value: urls,
                            onChange: function(value) {
                                setAttributes({ urls: value });
                            },
                            rows: 8
                        }),
                        el(SelectControl, {
                            label: __('Gallery Image Size', 'flickr-justified-block'),
                            help: __('Choose the size for images displayed in the gallery grid. Larger sizes provide better quality but slower loading.', 'flickr-justified-block'),
                            value: imageSize,
                            options: sizeOptions,
                            onChange: function(value) {
                                setAttributes({ imageSize: value });
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
                            onChange: function (value) {
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
                            onChange: function (value) {
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
                            onChange: function (value) {
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

                        // Get breakpoint labels
                        (() => {
                            const breakpointLabels = {
                                mobile: __('Mobile Portrait', 'flickr-justified-block'),
                                mobile_landscape: __('Mobile Landscape', 'flickr-justified-block'),
                                tablet_portrait: __('Tablet Portrait', 'flickr-justified-block'),
                                tablet_landscape: __('Tablet Landscape', 'flickr-justified-block'),
                                desktop: __('Desktop/Laptop', 'flickr-justified-block'),
                                large_desktop: __('Large Desktop', 'flickr-justified-block'),
                                extra_large: __('Ultra-Wide Screens', 'flickr-justified-block')
                            };

                            return Object.keys(breakpointLabels).map(breakpointKey =>
                                el(RangeControl, {
                                    key: breakpointKey,
                                    label: breakpointLabels[breakpointKey],
                                    min: 1,
                                    max: 8,
                                    step: 1,
                                    value: (responsiveSettings && responsiveSettings[breakpointKey]) || 1,
                                    onChange: function(value) {
                                        const newResponsiveSettings = { ...responsiveSettings };
                                        newResponsiveSettings[breakpointKey] = value || 1;
                                        setAttributes({ responsiveSettings: newResponsiveSettings });
                                    }
                                })
                            );
                        })()
                    )
                ),

                // Block Preview
                el('div', blockProps,
                    urlArray.length > 0 ?
                        urlArray.map(function(url, index) {
                            return el(ImagePreview, {
                                key: index,
                                url: url,
                                index: index
                            });
                        }) :
                        el('div', {
                            style: {
                                padding: '40px 20px',
                                border: '2px dashed #ccc',
                                borderRadius: '8px',
                                textAlign: 'center',
                                color: '#666',
                                backgroundColor: '#f9f9f9'
                            }
                        },
                            el('p', {
                                style: {
                                    margin: '0 0 8px',
                                    fontSize: '16px'
                                }
                            }, __('Flickr Justified Block', 'flickr-justified-block')),
                            el('p', {
                                style: {
                                    margin: 0,
                                    fontSize: '14px'
                                }
                            }, __('Add image URLs in the sidebar to create your justified gallery. One URL per line.', 'flickr-justified-block'))
                        )
                )
            );
        },

        save: function() {
            // Server-side rendering, return null
            return null;
        }
    });

})(window.wp);

