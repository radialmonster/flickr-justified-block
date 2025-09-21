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
    const { createElement: el } = wp.element;

    registerBlockType('flickr-justified/block', {
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const {
                urls,
                gap,
                imageSize,
                lightboxMaxWidth,
                lightboxMaxHeight,
                responsiveSettings,
                rowHeightMode,
                rowHeight,
                maxViewportHeight,
                singleImageAlignment
            } = attributes;

            const blockProps = useBlockProps({
                className: 'flickr-justified-block-editor'
            });

            const urlArray = urls ? urls.split(/\r?\n/).filter(url => url.trim()) : [];

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
                        el('h4', {
                            style: { margin: '16px 0 8px', fontSize: '14px', fontWeight: '600' }
                        }, __('Enlarged Image Size', 'flickr-justified-block')),
                        el('p', {
                            style: { fontSize: '12px', color: '#666', margin: '0 0 12px' }
                        }, __('Maximum dimensions when images are clicked. Plugin will auto-select best available size from Flickr.', 'flickr-justified-block')),
                        el(TextControl, {
                            label: __('Max width (px)', 'flickr-justified-block'),
                            type: 'number',
                            value: lightboxMaxWidth || 2048,
                            onChange: function (value) {
                                const n = parseInt(value || '2048', 10) || 2048;
                                setAttributes({ lightboxMaxWidth: n });
                            }
                        }),
                        el(TextControl, {
                            label: __('Max height (px)', 'flickr-justified-block'),
                            type: 'number',
                            value: lightboxMaxHeight || 2048,
                            onChange: function (value) {
                                const n = parseInt(value || '2048', 10) || 2048;
                                setAttributes({ lightboxMaxHeight: n });
                            }
                        }),
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
                            url = url.trim();
                            const isImageUrl = /\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test(url);

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
                                    href: url,
                                    onClick: function(e) {
                                        e.preventDefault();
                                    },
                                    style: {
                                        display: 'block',
                                        textDecoration: 'none'
                                    }
                                },
                                    isImageUrl ?
                                        el('img', {
                                            src: url,
                                            alt: '',
                                            style: {
                                                width: '100%',
                                                height: 'auto',
                                                display: 'block',
                                                borderRadius: '4px'
                                            }
                                        }) :
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
                                            url.indexOf('flickr.com') !== -1 ?
                                                __('Flickr Photo: ', 'flickr-justified-block') + url :
                                                __('URL: ', 'flickr-justified-block') + url
                                        )
                                )
                            );
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

