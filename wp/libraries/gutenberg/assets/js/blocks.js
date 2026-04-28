(function(blocks, element, blockEditor, components, data) {
    const el = element.createElement;
    const { useBlockProps } = blockEditor;
    const { TextControl, TextareaControl, SelectControl, ToggleControl, Button } = components;
    const { InnerBlocks, MediaUpload } = blockEditor;
    const { useState } = element;
    
    // Field type components
    const FIELD_TYPES = {
        'text': {
            component: TextControl,
            getDefault: () => ''
        },
        'textarea': {
            component: function TextareaField({ label, value, onChange }) {
                return el(TextareaControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    rows: 4
                });
            },
            getDefault: () => ''
        },
        'number': {
            component: function NumberField({ label, value, onChange }) {
                return el(TextControl, {
                    label: label,
                    value: value,
                    onChange: val => onChange(val !== '' ? Number(val) : ''),
                    type: 'number'
                });
            },
            getDefault: () => 0
        },
        'small-number': {
            component: function SmallNumberField({ label, value, onChange, min, max }) {
                return el(TextControl, {
                    label: label,
                    value: value,
                    onChange: val => onChange(val !== '' ? Number(val) : ''),
                    type: 'number',
                    min: min,
                    max: max,
                    className: 'dgb-small-number'
                });
            },
            getDefault: (field) => field.default || 0
        },
        'select': {
            component: SelectControl,
            getDefault: (field) => field.default || ''
        },
        'toggle': {
            component: ToggleControl,
            getDefault: (field) => field.default || false
        },
        'image': {
            component: function ImageField({ label, value, onChange }) {
                return el('div', { className: 'dgb-image-field' },
                    el('label', { className: 'components-base-control__label' }, label),
                    el(MediaUpload, {
                        onSelect: (media) => {
                            onChange({
                                id: media.id,
                                url: media.url,
                                alt: media.alt || '',
                                title: media.title || ''
                            });
                        },
                        allowedTypes: ['image'],
                        value: value ? value.id : '',
                        render: ({ open }) => el('div', { className: 'dgb-image-controls' },
                            value && value.url ? [
                                el('img', {
                                    key: 'preview',
                                    src: value.url,
                                    alt: value.alt,
                                    className: 'dgb-image-preview'
                                }),
                                el(TextControl, {
                                    key: 'alt',
                                    label: 'Alt Text',
                                    value: value.alt,
                                    onChange: (newAlt) => onChange({ ...value, alt: newAlt })
                                }),
                                el('div', {
                                    key: 'actions',
                                    className: 'dgb-image-actions'
                                },
                                    el(Button, {
                                        onClick: open,
                                        variant: 'secondary'
                                    }, 'Replace Image'),
                                    el(Button, {
                                        onClick: () => onChange(null),
                                        isDestructive: true
                                    }, 'Remove')
                                )
                            ] : el(Button, {
                                onClick: open,
                                variant: 'secondary'
                            }, 'Select Image')
                        )
                    })
                );
            },
            getDefault: () => null
        },
        'attributes-repeater': {
            component: function AttributesRepeater({ label, value = [], onChange }) {
                const attributes = Array.isArray(value) ? value : [];
                
                const addAttribute = () => {
                    onChange([...attributes, { name: '', type: 'str', value: '' }]);
                };
    
                return el('div', { className: 'dgb-attributes-container' },
                    el('h4', {}, label),
                    attributes.map((attr, index) => 
                        el('div', { 
                            key: index,
                            className: 'dgb-attribute-row'
                        },
                            el('div', { className: 'dgb-attribute-inputs' },
                                el(TextControl, {
                                    label: 'Name',
                                    value: attr.name || '',
                                    onChange: (val) => {
                                        const newAttributes = [...attributes];
                                        newAttributes[index] = { ...attr, name: val };
                                        onChange(newAttributes);
                                    }
                                }),
                                el(SelectControl, {
                                    label: 'Type',
                                    value: attr.type || 'str',
                                    options: [
                                        { label: 'String', value: 'str' },
                                        { label: 'Integer', value: 'int' },
                                        { label: 'Number', value: 'num' },
                                        { label: 'Boolean', value: 'bool' },
                                        { label: 'Path', value: 'path' }
                                    ],
                                    onChange: (val) => {
                                        const newAttributes = [...attributes];
                                        newAttributes[index] = { ...attr, type: val };
                                        onChange(newAttributes);
                                    }
                                }),
                                el(TextControl, {
                                    label: 'Value',
                                    value: attr.value || '',
                                    onChange: (val) => {
                                        const newAttributes = [...attributes];
                                        newAttributes[index] = { ...attr, value: val };
                                        onChange(newAttributes);
                                    }
                                }),
                                el(Button, {
                                    isDestructive: true,
                                    onClick: () => {
                                        onChange(attributes.filter((_, i) => i !== index));
                                    }
                                }, '×')
                            )
                        )
                    ),
                    el(Button, {
                        variant: 'secondary',
                        onClick: addAttribute
                    }, '+ Add Attribute')
                );
            },
            getDefault: () => []
        },
        'row_repeater': {
            component: function RowRepeater({ label, value = [], onChange, repeater_fields = [] }) {
                const rows = Array.isArray(value) ? value : [];
                const fields = Array.isArray(repeater_fields) ? repeater_fields : [];
                
                const addRow = () => {
                    const newRow = {};
                    fields.forEach(field => {
                        const fieldConfig = FIELD_TYPES[field.type];
                        newRow[field.name] = fieldConfig ? fieldConfig.getDefault(field) : '';
                    });
                    onChange([...rows, newRow]);
                };
    
                return el('div', { className: 'dgb-row-repeater' },
                    el('h4', {}, label),
                    el('div', { className: 'dgb-row-headers' },
                        fields.map(field =>
                            el('div', { 
                                key: field.name,
                                className: 'dgb-row-header'
                            }, field.label)
                        ),
                        el('div', { className: 'dgb-row-header' })
                    ),
                    rows.map((row, rowIndex) =>
                        el('div', {
                            key: rowIndex,
                            className: 'dgb-row'
                        },
                            fields.map((field) => {
                                const fieldType = FIELD_TYPES[field.type];
                                if (!fieldType) return null;
    
                                const FieldComponent = fieldType.component;
                                const fieldProps = {
                                    label: '',
                                    value: row[field.name],
                                    onChange: (newValue) => {
                                        const newRows = [...rows];
                                        newRows[rowIndex] = { ...row, [field.name]: newValue };
                                        onChange(newRows);
                                    },
                                    ...field,
                                    ...(field.type === 'select' ? { options: field.options } : {})
                                };
    
                                return el('div', {
                                    key: `${rowIndex}-${field.name}`,
                                    className: 'dgb-row-cell'
                                },
                                    el(FieldComponent, fieldProps)
                                );
                            }),
                            el('div', { className: 'dgb-row-cell' },
                                el(Button, {
                                    isDestructive: true,
                                    onClick: () => {
                                        onChange(rows.filter((_, i) => i !== rowIndex));
                                    }
                                }, '×')
                            )
                        )
                    ),
                    el(Button, {
                        variant: 'secondary',
                        onClick: addRow
                    }, '+ Add Row')
                );
            },
            getDefault: () => []
        },
        'innerblocks': {
            component: function InnerBlocksField({ label }) {
                return el('div', { className: 'dgb-innerblocks-field' },
                    el('label', { className: 'components-base-control__label' }, label),
                    el(InnerBlocks, {
                        renderAppender: InnerBlocks.ButtonBlockAppender
                    })
                );
            },
            getDefault: () => ''
        },
        'title': {
            component: function TitleField({ label, value, onChange }) {
                return el(TextControl, {
                    label: label || 'Title',
                    value: value || '',
                    onChange: onChange,
                    className: 'dgb-title-field'
                });
            },
            getDefault: () => ''
        },
        'purpose': {
            component: function PurposeField({ label, value, onChange }) {
                return el(TextareaControl, {
                    label: label || 'Purpose',
                    value: value || '',
                    onChange: onChange,
                    rows: 4
                });
            },
            getDefault: () => ''
        },
        'query': {
            component: function QueryField({ label, value, onChange }) {
                return el(TextareaControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    rows: 6,
                    placeholder: 'Enter query'
                });
            },
            getDefault: () => ''
        },
        'date': {
            component: function DateField({ label, value, onChange }) {
                return el(TextControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    type: 'date'
                });
            },
            getDefault: () => ''
        },
        'service': {
            component: function ServiceField({ label, value, onChange }) {
                return el(TextControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    placeholder: 'e.g., str.create'
                });
            },
            getDefault: () => ''
        },
        'awesome_code': {
            component: function CodeField({ label, value, onChange }) {
                return el(TextareaControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    rows: 8,
                    className: 'dgb-code-field'
                });
            },
            getDefault: () => ''
        },
        'env_path': {
            component: function EnvPathField({ label, value, onChange }) {
                return el(TextControl, {
                    label: label,
                    value: value || '',
                    onChange: onChange,
                    placeholder: 'e.g., module.settings.name'
                });
            },
            getDefault: () => ''
        },
        'radio': {
            component: function RadioField({ label, value, onChange, options = [] }) {
                return el('div', { className: 'dgb-radio-field' },
                    el('label', { className: 'components-base-control__label' }, label),
                    el('div', { className: 'dgb-radio-options' },
                        options.map((option, index) =>
                            el('div', {
                                key: index,
                                className: 'dgb-radio-option'
                            },
                                el('label', {},
                                    el('input', {
                                        type: 'radio',
                                        name: label,
                                        value: option.value,
                                        checked: value === option.value,
                                        onChange: (e) => onChange(e.target.value)
                                    }),
                                    el('span', { className: 'dgb-radio-label' }, option.label)
                                )
                            )
                        )
                    )
                );
            },
            getDefault: (field) => field.default || (field.options && field.options[0] ? field.options[0].value : '')
        },
        'checkbox': {
            component: function CheckboxField({ label, value, onChange, options = [] }) {
                // Ensure value is an array
                const selectedValues = Array.isArray(value) ? value : [];
                
                const handleChange = (optionValue, checked) => {
                    let newValues;
                    if (checked) {
                        newValues = [...selectedValues, optionValue];
                    } else {
                        newValues = selectedValues.filter(v => v !== optionValue);
                    }
                    onChange(newValues);
                };
                
                return el('div', { className: 'dgb-checkbox-field' },
                    el('label', { className: 'components-base-control__label' }, label),
                    el('div', { className: 'dgb-checkbox-options' },
                        options.map((option, index) =>
                            el('div', {
                                key: index,
                                className: 'dgb-checkbox-option'
                            },
                                el('label', {},
                                    el('input', {
                                        type: 'checkbox',
                                        value: option.value,
                                        checked: selectedValues.includes(option.value),
                                        onChange: (e) => handleChange(option.value, e.target.checked)
                                    }),
                                    el('span', { className: 'dgb-checkbox-label' }, option.label)
                                )
                            )
                        )
                    )
                );
            },
            getDefault: (field) => field.default || []
        },
        'single-checkbox': {
            component: function SingleCheckboxField({ label, value, onChange, checkboxLabel }) {
                return el('div', { className: 'dgb-single-checkbox' },
                    el('label', {},
                        el('input', {
                            type: 'checkbox',
                            checked: Boolean(value),
                            onChange: (e) => onChange(e.target.checked)
                        }),
                        el('span', { className: 'dgb-checkbox-label' }, checkboxLabel || label)
                    )
                );
            },
            getDefault: (field) => field.default || false
        }
    };

    // Tab Buttons Component
    function TabButtons({ tabs, activeTab, onTabChange }) {
        return el('div', { className: 'dgb-tab-buttons' },
            tabs.map(tab => 
                el('button', {
                    key: tab.name,
                    type: 'button',
                    className: `dgb-tab-button ${activeTab === tab.name ? 'active' : ''}`,
                    onClick: () => onTabChange(tab.name)
                },
                    el('span', { className: 'dgb-tab-button-content' },
                        el('span', { className: `dashicons dashicons-${tab.icon}` }),
                        ' ',
                        tab.title
                    )
                )
            )
        );
    }

    // Register Field Block
    blocks.registerBlockType('dgb/field', {
        title: 'DGB Field',
        parent: Object.keys(window.dgbBlockConfigs.blocks).map(name => 'dgb/' + name),
        attributes: {
            name: { type: 'string', default: '' },
            type: { type: 'string' },
            label: { type: 'string', default: '' },
            value: { 
                type: ['string', 'array', 'boolean', 'number', 'object'],
                default: ''
            },
            tab: { type: 'string', default: '' },
            attr_name: { type: 'string', default: '' },
            options: { type: 'array', default: [] },
            validation: { type: 'object', default: {} },
            repeater_fields: { type: 'array', default: [] },
            activeTab: { type: 'string', default: '' }
        },
        
        edit: function({ attributes, setAttributes }) {
            const { type, label, value, tab, options, validation, repeater_fields, activeTab } = attributes;
            const fieldConfig = FIELD_TYPES[type];
            
            if (!fieldConfig) return null;

            const className = `dgb-field dgb-field-${tab}${tab === activeTab ? ' dgb-field-active' : ''}`;

            if (type === 'innerblocks') {
                return el('div', { className },
                    el(fieldConfig.component, { label: label })
                );
            }

            let fieldValue = value;
            if (type === 'small-number' || type === 'number') {
                fieldValue = value !== '' ? Number(value) : '';
            } else if (type === 'toggle') {
                fieldValue = Boolean(value);
            }

            const props = {
                label: label,
                value: fieldValue,
                onChange: (newValue) => setAttributes({ value: newValue }),
                ...(validation || {}),
                ...(type === 'select' ? { options: options } : {}),
                ...(type === 'row_repeater' ? { repeater_fields: repeater_fields } : {})
            };

            return el('div', { className },
                el(fieldConfig.component, props)
            );
        },
        
        save: function() {
            return el(InnerBlocks.Content);
        }
    });

    // Register dynamic blocks from configuration
    if (window.dgbBlockConfigs && window.dgbBlockConfigs.blocks) {
        Object.entries(window.dgbBlockConfigs.blocks).forEach(([name, config]) => {
            blocks.registerBlockType('dgb/' + name, {
                title: config.title,
                description: config.description || '',
                icon: config.icon || 'admin-generic',
                category: config.category || 'widgets',
                keywords: config.keywords || [],
                
                edit: function(props) {
                    const blockProps = useBlockProps();
                    const [activeTab, setActiveTab] = useState(
                        config.tabs && config.tabs.length > 0 ? config.tabs[0].name : ''
                    );
                    
                    const setFieldsActiveTab = (tab) => {
                        setActiveTab(tab);
                        const innerBlocks = data.select('core/block-editor').getBlocks(props.clientId);
                        innerBlocks.forEach(block => {
                            if (block.name === 'dgb/field') {
                                data.dispatch('core/block-editor').updateBlockAttributes(
                                    block.clientId,
                                    { activeTab: tab }
                                );
                            }
                        });
                    };

                    // Create template from configuration
                    const template = [];
                    
                    // Add fields from tabs
                    if (config.tabs && config.tabs.length > 0) {
                        config.tabs.forEach(tab => {
                            if (tab.fields && tab.fields.length > 0) {
                                tab.fields.forEach(field => {
                                    let defaultValue = field.default;
                                    
                                    if (field.type === 'attributes-repeater' || field.type === 'row_repeater') {
                                        defaultValue = [];
                                    } else if (field.type === 'toggle') {
                                        defaultValue = Boolean(field.default);
                                    } else if (field.type === 'small-number' || field.type === 'number') {
                                        defaultValue = field.default !== undefined ? Number(field.default) : 0;
                                    } else if (field.type === 'image') {
                                        defaultValue = null;
                                    }

                                    template.push([
                                        'dgb/field',
                                        {
                                            name: field.name,
                                            type: field.type,
                                            label: field.label,
                                            attr_name: field.attr_name,
                                            tab: tab.name,
                                            activeTab: activeTab,
                                            options: field.options || [],
                                            validation: field.validation || {},
                                            repeater_fields: field.repeater_fields || [],
                                            value: defaultValue
                                        }
                                    ]);
                                });
                            }
                        });
                    }
                    
                    // Add fields from top-level (if no tabs)
                    if (config.fields && config.fields.length > 0) {
                        config.fields.forEach(field => {
                            let defaultValue = field.default;
                            
                            if (field.type === 'attributes-repeater' || field.type === 'row_repeater') {
                                defaultValue = [];
                            } else if (field.type === 'toggle') {
                                defaultValue = Boolean(field.default);
                            } else if (field.type === 'small-number' || field.type === 'number') {
                                defaultValue = field.default !== undefined ? Number(field.default) : 0;
                            }

                            template.push([
                                'dgb/field',
                                {
                                    name: field.name,
                                    type: field.type,
                                    label: field.label,
                                    attr_name: field.attr_name,
                                    tab: '',
                                    activeTab: '',
                                    options: field.options || [],
                                    validation: field.validation || {},
                                    repeater_fields: field.repeater_fields || [],
                                    value: defaultValue
                                }
                            ]);
                        });
                    }

                    const content = [];
                    
                    // Add tabs if they exist
                    if (config.tabs && config.tabs.length > 0) {
                        content.push(
                            el(TabButtons, {
                                tabs: config.tabs,
                                activeTab: activeTab,
                                onTabChange: setFieldsActiveTab
                            })
                        );
                    }
                    
                    // Add inner blocks
                    content.push(
                        el('div', { className: 'dgb-tab-content' },
                            el(InnerBlocks, {
                                template: template,
                                templateLock: 'all',
                                allowedBlocks: ['dgb/field'],
                                renderAppender: false
                            })
                        )
                    );

                    return el('div', { 
                        ...blockProps,
                        'data-active-tab': activeTab
                    }, content);
                },

                save: function() {
                    return el(InnerBlocks.Content);
                }
            });
        });
    }

}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.data
));