/**
 * Oráculo Search Block
 * 
 * @package Oraculo_Tainacan
 */

(function(blocks, element, components, editor, i18n) {
    const el = element.createElement;
    const { TextControl, CheckboxControl, ToggleControl, Panel, PanelBody } = components;
    const { InspectorControls } = editor;
    const { __ } = i18n;

    // Register block
    blocks.registerBlockType('oraculo-tainacan/search', {
        title: __('Oráculo Search', 'oraculo-tainacan'),
        description: __('Add a search interface for the Oráculo Tainacan plugin', 'oraculo-tainacan'),
        icon: 'search',
        category: 'widgets',
        keywords: [__('search'), __('oraculo'), __('tainacan')],
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            placeholder: {
                type: 'string',
                default: __('Ask a question about the collection...', 'oraculo-tainacan')
            },
            defaultCollections: {
                type: 'array',
                default: []
            },
            showSummary: {
                type: 'boolean',
                default: true
            },
            showFilters: {
                type: 'boolean',
                default: true
            },
            maxResults: {
                type: 'number',
                default: 10
            }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { placeholder, defaultCollections, showSummary, showFilters, maxResults } = attributes;

            // Get available collections (passed from PHP)
            const collections = window.oraculoBlockData ? window.oraculoBlockData.collections : [];

            return el('div', { className: props.className },
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Search Settings', 'oraculo-tainacan') },
                        el(TextControl, {
                            label: __('Placeholder Text', 'oraculo-tainacan'),
                            value: placeholder,
                            onChange: (value) => setAttributes({ placeholder: value })
                        }),
                        el('div', { className: 'oraculo-block-collections' },
                            el('label', {}, __('Default Collections', 'oraculo-tainacan')),
                            collections.map(collection => 
                                el(CheckboxControl, {
                                    key: collection.id,
                                    label: collection.name,
                                    checked: defaultCollections.includes(collection.id),
                                    onChange: (checked) => {
                                        const newCollections = checked 
                                            ? [...defaultCollections, collection.id]
                                            : defaultCollections.filter(id => id !== collection.id);
                                        setAttributes({ defaultCollections: newCollections });
                                    }
                                })
                            )
                        ),
                        el(ToggleControl, {
                            label: __('Show AI Summary Tab', 'oraculo-tainacan'),
                            checked: showSummary,
                            onChange: (value) => setAttributes({ showSummary: value })
                        }),
                        el(ToggleControl, {
                            label: __('Show Collection Filters', 'oraculo-tainacan'),
                            checked: showFilters,
                            onChange: (value) => setAttributes({ showFilters: value })
                        }),
                        el(TextControl, {
                            label: __('Maximum Results', 'oraculo-tainacan'),
                            type: 'number',
                            value: maxResults,
                            onChange: (value) => setAttributes({ maxResults: parseInt(value) || 10 })
                        })
                    )
                ),
                // Preview in editor
                el('div', { className: 'oraculo-search-block-preview' },
                    el('div', { className: 'oraculo-search-form' },
                        el('input', {
                            type: 'text',
                            className: 'oraculo-search-input',
                            placeholder: placeholder,
                            disabled: true
                        }),
                        el('button', { className: 'oraculo-search-button', disabled: true },
                            el('span', { className: 'dashicons dashicons-search' }),
                            __('Search', 'oraculo-tainacan')
                        )
                    ),
                    showFilters && el('div', { className: 'oraculo-filters-preview' },
                        __('Collection filters will appear here', 'oraculo-tainacan')
                    ),
                    showSummary && el('div', { className: 'oraculo-tabs-preview' },
                        el('span', { className: 'oraculo-tab active' }, __('Results', 'oraculo-tainacan')),
                        el('span', { className: 'oraculo-tab' }, __('AI Summary', 'oraculo-tainacan'))
                    )
                )
            );
        },

        save: function(props) {
            // Render happens server-side
            return null;
        }
    });

})(
    window.wp.blocks,
    window.wp.element,
    window.wp.components,
    window.wp.blockEditor || window.wp.editor,
    window.wp.i18n
);