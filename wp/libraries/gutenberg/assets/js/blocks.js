(function (blocks, element, blockEditor, components, data, apiFetch) {

    var el                = element.createElement;
    var useBlockProps     = blockEditor.useBlockProps;
    var BlockControls     = blockEditor.BlockControls;
    var InspectorControls = blockEditor.InspectorControls;
    var InnerBlocks       = blockEditor.InnerBlocks;
    var MediaUpload       = blockEditor.MediaUpload;

    var TextControl       = components.TextControl;
    var TextareaControl   = components.TextareaControl;
    var SelectControl     = components.SelectControl;
    var ToggleControl     = components.ToggleControl;
    var Button            = components.Button;
    var Spinner           = components.Spinner;
    var ToolbarGroup      = components.ToolbarGroup;
    var ToolbarButton     = components.ToolbarButton;
    var PanelBody         = components.PanelBody;
    var PanelRow          = components.PanelRow;
    var Modal             = components.Modal;

    var ServerSideRender  = (window.wp && window.wp.serverSideRender) ? window.wp.serverSideRender : (components && components.ServerSideRender);

    var useState          = element.useState;
    var useEffect         = element.useEffect;
    var useRef            = element.useRef;
    var useMemo           = element.useMemo;
    var createPortal      = element.createPortal;

    /* ================================================================
       DEFERRED VALUE HOOK
       Local copy updates instantly on keystroke; commits to the block
       attribute only on debounce (350ms) + blur.
    ================================================================ */
    function useDeferredValue(value, commit, delay) {
        delay = delay || 350;
        var ls         = useState(value);
        var local      = ls[0];
        var setLocal   = ls[1];
        var timer      = useRef(null);
        var latest     = useRef(value);
        var commitRef  = useRef(commit);

        // Always keep commitRef pointing at the freshest closure so that a
        // delayed commit building an image object / repeater array never uses
        // a stale surrounding value.
        useEffect(function () { commitRef.current = commit; });

        useEffect(function () {
            setLocal(value);
            latest.current = value;
        }, [value]);

        useEffect(function () {
            return function () {
                if (timer.current) {
                    clearTimeout(timer.current);
                    if (latest.current !== value) { commitRef.current(latest.current); }
                }
            };
        }, []);

        function onChange(next) {
            setLocal(next);
            latest.current = next;
            if (timer.current) { clearTimeout(timer.current); }
            timer.current = setTimeout(function () {
                timer.current = null;
                commitRef.current(next);
            }, delay);
        }

        function onBlur() {
            if (timer.current) { clearTimeout(timer.current); timer.current = null; }
            commitRef.current(latest.current);
        }

        return [local, onChange, onBlur];
    }

    /* ================================================================
       POSITIONED DROPDOWN
       Portals onto document.body so it is never clipped or buried by a
       stacking context inside the editor tree.
    ================================================================ */
    function PositionedDropdown(props) {
        var anchorRef = props.anchorRef;
        var onClose   = props.onClose;
        var ss        = useState({});
        var style     = ss[0];
        var setStyle  = ss[1];
        var dropRef   = useRef(null);

        useEffect(function () {
            function position() {
                if (!anchorRef.current) { return; }
                var rect = anchorRef.current.getBoundingClientRect();
                var minW = Math.max(rect.width, 320);
                var left = rect.left;
                if (left + minW > window.innerWidth - 12) {
                    left = Math.max(0, window.innerWidth - minW - 12);
                }
                setStyle({ position: 'fixed', top: rect.bottom + 4, left: left, minWidth: minW, zIndex: 999999 });
            }
            position();
            window.addEventListener('scroll', position, true);
            window.addEventListener('resize', position);
            return function () {
                window.removeEventListener('scroll', position, true);
                window.removeEventListener('resize', position);
            };
        }, [anchorRef]);

        useEffect(function () {
            function handleClick(e) {
                if (dropRef.current  && !dropRef.current.contains(e.target) &&
                    anchorRef.current && !anchorRef.current.contains(e.target)) {
                    onClose();
                }
            }
            document.addEventListener('mousedown', handleClick);
            return function () { document.removeEventListener('mousedown', handleClick); };
        }, [onClose, anchorRef]);

        return createPortal(
            el('div', { ref: dropRef, style: style, className: 'dgb-post-select-dropdown' },
                props.children
            ),
            document.body
        );
    }

    /* ================================================================
       ATTRIBUTE BINDING HELPERS
    ================================================================ */
    function getFieldValue(attrs, field) {
        var attrName = field.attr_name || field.name;
        if (!attrName) {
            return field.default !== undefined ? field.default : '';
        }

        // 1. Direct flat attribute (e.g. 'content.author')
        if (attrs && attrs[attrName] !== undefined && attrs[attrName] !== null) {
            return attrs[attrName];
        }

        // 2. Dot-nested attribute (e.g. attrs.content.author)
        if (attrName.indexOf('.') !== -1 && attrs) {
            var parts = attrName.split('.');
            var cur = attrs;
            var found = true;
            for (var i = 0; i < parts.length; i++) {
                if (cur && cur[parts[i]] !== undefined && cur[parts[i]] !== null) {
                    cur = cur[parts[i]];
                } else {
                    found = false;
                    break;
                }
            }
            if (found) {
                return cur;
            }
        }

        // 3. Fallback to legacy _data snapshot
        if (attrs && attrs._data && typeof attrs._data === 'object') {
            if (attrs._data[attrName] !== undefined && attrs._data[attrName] !== null) {
                return attrs._data[attrName];
            }
            if (field.name && attrs._data[field.name] !== undefined && attrs._data[field.name] !== null) {
                return attrs._data[field.name];
            }
        }

        // 4. Fallback to field default
        if (field.default !== undefined && field.default !== null) {
            return field.default;
        }

        var ft = FIELD_TYPES[field.type];
        return ft && ft.getDefault ? ft.getDefault(field) : '';
    }

    function setFieldValue(setAttributes, currentAttrs, field, value) {
        var attrName = field.attr_name || field.name;
        if (!attrName) {
            return;
        }

        var newAttrs = {};

        // 1. Set the flat synthesized key (e.g. 'content.author': value)
        newAttrs[attrName] = value;

        // 2. If attrName contains dot notation (e.g. 'content.author'), also mirror into nested object
        if (attrName.indexOf('.') !== -1) {
            var parts = attrName.split('.');
            var top = parts[0];
            var sub = parts.slice(1).join('.');

            var currentTop = (currentAttrs && currentAttrs[top]) ? Object.assign({}, currentAttrs[top]) : {};
            currentTop[sub] = value;
            newAttrs[top] = currentTop;
        }

        // 3. Maintain legacy _data snapshot for backward compatibility
        var dataSnapshot = (currentAttrs && currentAttrs._data) ? Object.assign({}, currentAttrs._data) : {};
        dataSnapshot[attrName] = value;
        if (field.name) {
            dataSnapshot[field.name] = value;
        }
        newAttrs._data = dataSnapshot;

        setAttributes(newAttrs);
    }

    /* ================================================================
       FIELD TYPES
    ================================================================ */
    var FIELD_TYPES = {};

    /* -- text -------------------------------------------------------- */
    FIELD_TYPES['text'] = {
        component: function TextField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: p.placeholder, help: p.help });
        },
        getDefault: function () { return ''; }
    };

    /* -- textarea ---------------------------------------------------- */
    FIELD_TYPES['textarea'] = {
        component: function TextareaField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: p.placeholder, rows: 4 });
        },
        getDefault: function () { return ''; }
    };

    /* -- number ------------------------------------------------------ */
    FIELD_TYPES['number'] = {
        component: function NumberField(p) {
            function commit(v) { p.onChange(v !== '' ? Number(v) : ''); }
            var d = useDeferredValue(p.value === '' || p.value === undefined ? '' : p.value, commit);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'number' });
        },
        getDefault: function () { return 0; }
    };

    /* -- small-number ------------------------------------------------ */
    FIELD_TYPES['small-number'] = {
        component: function SmallNumberField(p) {
            function commit(v) { p.onChange(v !== '' ? Number(v) : ''); }
            var d = useDeferredValue(p.value === '' || p.value === undefined ? '' : p.value, commit);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'number', min: p.min, max: p.max, className: 'dgb-small-number' });
        },
        getDefault: function (f) { return f.default || 0; }
    };

    /* -- select ------------------------------------------------------ */
    FIELD_TYPES['select'] = {
        component: SelectControl,
        getDefault: function (f) { return f.default || ''; }
    };

    /* -- toggle ------------------------------------------------------ */
    FIELD_TYPES['toggle'] = {
        component: function ToggleField(p) {
            return el(ToggleControl, {
                label:   p.label,
                checked: Boolean(p.value),
                onChange: p.onChange
            });
        },
        getDefault: function (f) { return f.default || false; }
    };

    /* -- image ------------------------------------------------------- */
    FIELD_TYPES['image'] = {
        component: function ImageField(p) {
            var value    = p.value;
            var onChange = p.onChange;
            var altD   = useDeferredValue(value && value.alt   ? value.alt   : '', function (n) { onChange(Object.assign({}, value, { alt:   n })); });
            var titleD = useDeferredValue(value && value.title ? value.title : '', function (n) { onChange(Object.assign({}, value, { title: n })); });

            return el('div', { className: 'dgb-image-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el(MediaUpload, {
                    onSelect: function (m) { onChange({ id: m.id, url: m.url, alt: m.alt || '', title: m.title || '' }); },
                    allowedTypes: ['image'],
                    value: value ? value.id : undefined,
                    render: function (rp) {
                        if (value && value.url) {
                            return el('div', { className: 'dgb-image-controls' },
                                el('img', { src: value.url, alt: value.alt, className: 'dgb-image-preview' }),
                                el(TextControl, { label: 'Alt Text', value: altD[0],   onChange: altD[1],   onBlur: altD[2]   }),
                                el(TextControl, { label: 'Title',    value: titleD[0], onChange: titleD[1], onBlur: titleD[2] }),
                                el('div', { className: 'dgb-image-actions' },
                                    el(Button, { onClick: rp.open, variant: 'secondary' }, 'Replace Image'),
                                    el(Button, { onClick: function () { onChange(null); }, isDestructive: true }, 'Remove')
                                )
                            );
                        }
                        return el(Button, { onClick: rp.open, variant: 'secondary' }, 'Select Image');
                    }
                })
            );
        },
        getDefault: function () { return null; }
    };

    /* -- attributes-repeater ---------------------------------------- */
    FIELD_TYPES['attributes-repeater'] = {
        component: function AttributesRepeater(p) {
            var attrs = Array.isArray(p.value) ? p.value : [];

            function add() { p.onChange(attrs.concat([{ name: '', type: 'str', value: '' }])); }

            return el('div', { className: 'dgb-attributes-container' },
                el('h4', {}, p.label),
                attrs.map(function (attr, i) {
                    return el('div', { key: i, className: 'dgb-attribute-row' },
                        el('div', { className: 'dgb-attribute-inputs' },
                            el(FIELD_TYPES['text'].component, {
                                label: 'Name', value: attr.name || '',
                                onChange: function (v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { name: v }); p.onChange(a); }
                            }),
                            el(SelectControl, {
                                label: 'Type', value: attr.type || 'str',
                                options: [
                                    { label: 'String',  value: 'str'  },
                                    { label: 'Integer', value: 'int'  },
                                    { label: 'Number',  value: 'num'  },
                                    { label: 'Boolean', value: 'bool' },
                                    { label: 'Path',    value: 'path' }
                                ],
                                onChange: function (v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { type: v }); p.onChange(a); }
                            }),
                            el(FIELD_TYPES['text'].component, {
                                label: 'Value', value: attr.value || '',
                                onChange: function (v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { value: v }); p.onChange(a); }
                            })
                        ),
                        el('div', { className: 'dgb-attribute-row-footer' },
                            el(Button, {
                                className: 'dgb-repeater-remove-btn',
                                isDestructive: true,
                                onClick: function () { p.onChange(attrs.filter(function (_, j) { return j !== i; })); }
                            },
                                el('span', { className: 'dashicons dashicons-trash' }),
                                ' Remove'
                            )
                        )
                    );
                }),
                el(Button, { variant: 'secondary', onClick: add }, '+ Add Attribute')
            );
        },
        getDefault: function () { return []; }
    };

    /* -- row_repeater ------------------------------------------------ */
    FIELD_TYPES['row_repeater'] = {
        component: function RowRepeater(p) {
            var rows         = Array.isArray(p.value)           ? p.value           : [];
            var fields       = Array.isArray(p.repeater_fields) ? p.repeater_fields : [];
            var layout       = p.layout || 'table';
            var summaryField = p.summary_field || (fields.length > 0 ? fields[0].name : '');

            var expandedState = useState({});
            var expanded      = expandedState[0];
            var setExpanded   = expandedState[1];

            var modalState = useState(false);
            var isModalOpen = modalState[0];
            var setModalOpen = modalState[1];

            function toggleExpanded(index) {
                var next = Object.assign({}, expanded);
                next[index] = !next[index];
                setExpanded(next);
            }

            function addRow() {
                var r = {};
                fields.forEach(function (f) { var fc = FIELD_TYPES[f.type]; r[f.name] = fc ? fc.getDefault(f) : ''; });
                p.onChange(rows.concat([r]));
            }

            function removeRow(index) {
                p.onChange(rows.filter(function (_, j) { return j !== index; }));
            }

            function renderField(f, row, rowIndex) {
                var ft = FIELD_TYPES[f.type];
                if (!ft) { return null; }
                var fp = Object.assign({}, f, {
                    label: layout === 'table' ? '' : f.label,
                    value: row[f.name],
                    onChange: function (v) {
                        var nr = rows.slice();
                        nr[rowIndex] = Object.assign({}, row);
                        nr[rowIndex][f.name] = v;
                        p.onChange(nr);
                    }
                });
                if (f.type === 'select') { fp.options = f.options; }
                return el(ft.component, fp);
            }

            function RemoveBtn(index) {
                return el(Button, {
                    className: 'dgb-repeater-remove-btn',
                    isDestructive: true,
                    onClick: function () { removeRow(index); }
                },
                    el('span', { className: 'dashicons dashicons-trash' }),
                    ' Remove'
                );
            }

            /* TABLE */
            function renderTable() {
                return el('div', { className: 'dgb-repeater-table' },
                    el('div', { className: 'dgb-repeater-table-header' },
                        fields.map(function (f) {
                            return el('div', { key: f.name, className: 'dgb-repeater-th', style: f.width ? { width: f.width } : {} }, f.label);
                        }),
                        el('div', { className: 'dgb-repeater-th dgb-repeater-th-remove' })
                    ),
                    rows.map(function (row, ri) {
                        return el('div', { key: ri, className: 'dgb-repeater-tr' },
                            fields.map(function (f) {
                                return el('div', { key: f.name, className: 'dgb-repeater-td', style: f.width ? { width: f.width } : {} },
                                    renderField(f, row, ri)
                                );
                            }),
                            el('div', { className: 'dgb-repeater-td dgb-repeater-td-remove' }, RemoveBtn(ri))
                        );
                    }),
                    el('div', { className: 'dgb-repeater-footer' },
                        el(Button, { variant: 'secondary', onClick: addRow }, '+ Add Row')
                    )
                );
            }

            /* CARD */
            function renderCard() {
                return el('div', { className: 'dgb-repeater-cards' },
                    rows.map(function (row, ri) {
                        return el('div', { key: ri, className: 'dgb-repeater-card' },
                            el('div', { className: 'dgb-repeater-card-header' },
                                el('span', { className: 'dgb-repeater-card-index' }, '#' + (ri + 1)),
                                RemoveBtn(ri)
                            ),
                            el('div', { className: 'dgb-repeater-card-body' },
                                fields.map(function (f) {
                                    return el('div', { key: f.name, className: 'dgb-repeater-card-field dgb-repeater-field-' + (f.width || 'half') },
                                        renderField(f, row, ri)
                                    );
                                })
                            )
                        );
                    }),
                    el('div', { className: 'dgb-repeater-footer' },
                        el(Button, { variant: 'secondary', onClick: addRow }, '+ Add Row')
                    )
                );
            }

            /* CARD-COLLAPSED */
            function renderCardCollapsed() {
                return el('div', { className: 'dgb-repeater-cards dgb-repeater-cards-collapsed' },
                    rows.map(function (row, ri) {
                        var isOpen  = !!expanded[ri];
                        var summary = row[summaryField] || ('Row ' + (ri + 1));
                        if (typeof summary === 'string' && summary.length > 40) { summary = summary.substring(0, 40) + '\u2026'; }

                        return el('div', { key: ri, className: 'dgb-repeater-card dgb-repeater-card-collapsible' + (isOpen ? ' is-open' : '') },
                            el('div', { className: 'dgb-repeater-card-header' },
                                el('button', {
                                    type: 'button',
                                    className: 'dgb-repeater-collapse-toggle',
                                    onClick: function () { toggleExpanded(ri); }
                                },
                                    el('span', { className: 'dashicons ' + (isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2') }),
                                    el('span', { className: 'dgb-repeater-card-summary' }, '#' + (ri + 1) + ' \u2014 ' + summary)
                                ),
                                RemoveBtn(ri)
                            ),
                            isOpen ? el('div', { className: 'dgb-repeater-card-body' },
                                fields.map(function (f) {
                                    return el('div', { key: f.name, className: 'dgb-repeater-card-field dgb-repeater-field-' + (f.width || 'half') },
                                        renderField(f, row, ri)
                                    );
                                })
                            ) : null
                        );
                    }),
                    el('div', { className: 'dgb-repeater-footer' },
                        el(Button, { variant: 'secondary', onClick: addRow }, '+ Add Row')
                    )
                );
            }

            var modalElement = (isModalOpen && Modal) ? el(Modal, {
                title: 'Table Editor \u2014 ' + (p.label || 'Repeater'),
                className: 'dgb-modal-repeater',
                onRequestClose: function () { setModalOpen(false); }
            },
                el('div', { className: 'dgb-modal-repeater-inner' },
                    renderTable(),
                    el('div', { className: 'dgb-modal-footer', style: { marginTop: '16px', display: 'flex', justifyContent: 'flex-end' } },
                        el(Button, { variant: 'primary', onClick: function () { setModalOpen(false); } }, 'Done')
                    )
                )
            ) : null;

            return el('div', { className: 'dgb-row-repeater dgb-row-repeater-' + layout },
                el('div', { className: 'dgb-repeater-header-bar', style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' } },
                    el('h4', { style: { margin: 0 } }, p.label),
                    el(Button, {
                        variant: 'secondary',
                        size: 'small',
                        onClick: function () { setModalOpen(true); }
                    },
                        el('span', { className: 'dashicons dashicons-editor-table', style: { marginRight: '4px' } }),
                        'Wide Table Editor'
                    )
                ),
                layout === 'table'          ? renderTable()         : null,
                layout === 'card'           ? renderCard()          : null,
                layout === 'card-collapsed' ? renderCardCollapsed() : null,
                modalElement
            );
        },
        getDefault: function () { return []; }
    };

    /* -- innerblocks ------------------------------------------------- */
    FIELD_TYPES['innerblocks'] = {
        component: function InnerBlocksField(p) {
            return el('div', { className: 'dgb-innerblocks-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el(InnerBlocks, { templateLock: false, renderAppender: InnerBlocks.ButtonBlockAppender })
            );
        },
        getDefault: function () { return ''; }
    };

    /* -- title ------------------------------------------------------- */
    FIELD_TYPES['title'] = {
        component: function TitleField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label || 'Title', value: d[0], onChange: d[1], onBlur: d[2], className: 'dgb-title-field' });
        },
        getDefault: function () { return ''; }
    };

    /* -- purpose ----------------------------------------------------- */
    FIELD_TYPES['purpose'] = {
        component: function PurposeField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label || 'Purpose', value: d[0], onChange: d[1], onBlur: d[2], rows: 4 });
        },
        getDefault: function () { return ''; }
    };

    /* -- query ------------------------------------------------------- */
    FIELD_TYPES['query'] = {
        component: function QueryField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], rows: 6, placeholder: 'Enter query' });
        },
        getDefault: function () { return ''; }
    };

    /* -- date -------------------------------------------------------- */
    FIELD_TYPES['date'] = {
        component: function DateField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'date' });
        },
        getDefault: function () { return ''; }
    };

    /* -- service ----------------------------------------------------- */
    FIELD_TYPES['service'] = {
        component: function ServiceField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: 'e.g., str.create' });
        },
        getDefault: function () { return ''; }
    };

    /* -- awesome_code ------------------------------------------------ */
    FIELD_TYPES['awesome_code'] = {
        component: function CodeField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            var modalState = useState(false);
            var isModalOpen = modalState[0];
            var setModalOpen = modalState[1];

            var modalElement = (isModalOpen && Modal) ? el(Modal, {
                title: 'Code Editor \u2014 ' + (p.label || 'Code'),
                className: 'dgb-modal-code',
                onRequestClose: function () { setModalOpen(false); }
            },
                el('div', { className: 'dgb-modal-code-inner' },
                    el(TextareaControl, {
                        value: d[0],
                        onChange: d[1],
                        onBlur: d[2],
                        rows: 22,
                        className: 'dgb-code-field-modal'
                    }),
                    el('div', { className: 'dgb-modal-footer', style: { marginTop: '16px', display: 'flex', justifyContent: 'flex-end' } },
                        el(Button, { variant: 'primary', onClick: function () { setModalOpen(false); } }, 'Done')
                    )
                )
            ) : null;

            return el('div', { className: 'dgb-code-field-wrapper' },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
                    el('label', { className: 'components-base-control__label', style: { marginBottom: 0 } }, p.label),
                    el(Button, {
                        variant: 'tertiary',
                        size: 'small',
                        onClick: function () { setModalOpen(true); }
                    },
                        el('span', { className: 'dashicons dashicons-fullscreen-alt', style: { marginRight: '4px' } }),
                        'Expand Editor'
                    )
                ),
                el(TextareaControl, { value: d[0], onChange: d[1], onBlur: d[2], rows: 8, className: 'dgb-code-field' }),
                modalElement
            );
        },
        getDefault: function () { return ''; }
    };

    /* -- env_path ---------------------------------------------------- */
    FIELD_TYPES['env_path'] = {
        component: function EnvPathField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: 'e.g., module.settings.name' });
        },
        getDefault: function () { return ''; }
    };

    /* -- file -------------------------------------------------------- */
    FIELD_TYPES['file'] = {
        component: function FileField(p) {
            var value    = p.value;
            var onChange = p.onChange;
            var multiple = p.multiple || false;
            var types    = Array.isArray(p.allowed_types) && p.allowed_types.length > 0 ? p.allowed_types : undefined;
            var files    = multiple ? (Array.isArray(value) ? value : (value ? [value] : [])) : null;
            var file     = multiple ? null : (value && typeof value === 'object' ? value : null);

            function mkFile(m) {
                return { id: m.id, url: m.url, filename: m.filename || m.title || '', title: m.title || '', type: m.mime || '', filesize: m.filesizeHumanReadable || '' };
            }

            function preview(f, onRemove) {
                if (!f || !f.url) { return null; }
                var isImg = f.type && f.type.indexOf('image/') === 0;
                return el('div', { className: 'dgb-file-preview' },
                    isImg ? el('img', { src: f.url, alt: f.filename || '', className: 'dgb-file-thumb' })
                          : el('span', { className: 'dgb-file-icon dashicons dashicons-media-default' }),
                    el('div', { className: 'dgb-file-info' },
                        el('span', { className: 'dgb-file-name' }, f.filename || f.title || f.url),
                        f.filesize ? el('span', { className: 'dgb-file-size' }, f.filesize) : null
                    ),
                    el(Button, { isDestructive: true, isSmall: true, onClick: onRemove }, '\u00d7')
                );
            }

            return el('div', { className: 'dgb-file-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                !multiple ? el(MediaUpload, {
                    onSelect: function (m) { onChange(mkFile(m)); },
                    allowedTypes: types,
                    value: file ? file.id : undefined,
                    render: function (rp) {
                        if (file) {
                            return el('div', { className: 'dgb-file-selected' },
                                preview(file, function () { onChange(null); }),
                                el(Button, { variant: 'secondary', isSmall: true, onClick: rp.open }, 'Replace')
                            );
                        }
                        return el(Button, { variant: 'secondary', onClick: rp.open }, 'Select File');
                    }
                }) : null,
                multiple ? el(MediaUpload, {
                    onSelect: function (items) {
                        var picked = Array.isArray(items) ? items : [items];
                        var exist  = Array.isArray(value) ? value : [];
                        var ids    = exist.map(function (f) { return f.id; });
                        onChange(exist.concat(picked.filter(function (m) { return ids.indexOf(m.id) === -1; }).map(mkFile)));
                    },
                    allowedTypes: types, multiple: true,
                    value: files.map(function (f) { return f.id; }),
                    render: function (rp) {
                        return el('div', {},
                            files.length > 0 ? el('div', { className: 'dgb-file-list' },
                                files.map(function (f, i) {
                                    return preview(f, function () { onChange(files.filter(function (_, j) { return j !== i; })); });
                                })
                            ) : null,
                            el(Button, { variant: 'secondary', onClick: rp.open }, files.length > 0 ? '+ Add More Files' : 'Select Files')
                        );
                    }
                }) : null
            );
        },
        getDefault: function (f) { return f.multiple ? [] : null; }
    };

    /* -- filtered-post-select --------------------------------------- */
    FIELD_TYPES['filtered-post-select'] = {
        component: function FilteredPostSelectField(p) {
            var label      = p.label;
            var value      = p.value;
            var onChange   = p.onChange;
            var post_type  = p.post_type || 'post';
            var multiple   = !!p.multiple;
            var filterDefs = Array.isArray(p.filters) ? p.filters : [];

            var builtinPost = { post: 'posts', page: 'pages', attachment: 'media' };
            var builtinTax  = { category: 'categories', post_tag: 'tags' };
            var postEndpoint = p.rest_base ? p.rest_base : (builtinPost[post_type] || (post_type + 's'));

            // Fixed defs: terms provided — applied silently, no UI shown.
            // Interactive defs: no terms — show dropdown for user selection.
            var fixedDefs       = filterDefs.filter(function (fd) { return Array.isArray(fd.terms) && fd.terms.length > 0; });
            var interactiveDefs = filterDefs.filter(function (fd) { return !Array.isArray(fd.terms) || fd.terms.length === 0; });

            var ss = useState('');    var search    = ss[0];    var setSearch    = ss[1];
            var rs = useState([]);    var results   = rs[0];    var setResults   = rs[1];
            var ls = useState(false); var loading   = ls[0];    var setLoading   = ls[1];
            var os = useState(false); var open      = os[0];    var setOpen      = os[1];
            var ts = useState({});    var termOpts  = ts[0];    var setTermOpts  = ts[1];
            var searchTimer = useRef(null);
            var anchorRef   = useRef(null);

            var userFilters = (value && value.filters) ? value.filters : {};
            var selected    = multiple
                ? (Array.isArray(value && value.posts) ? value.posts : [])
                : ((value && value.post && typeof value.post === 'object') ? value.post : null);

            function isSelected(id) {
                return multiple ? selected.some(function (q) { return q.id === id; }) : (selected && selected.id === id);
            }

            useEffect(function () {
                interactiveDefs.forEach(function (fd) {
                    var ep = fd.rest_base ? fd.rest_base : (builtinTax[fd.taxonomy] || fd.taxonomy);
                    apiFetch({ path: '/wp/v2/' + ep + '?per_page=100&hide_empty=false&_fields=id,name,slug' })
                        .then(function (terms) {
                            setTermOpts(function (prev) { var n = Object.assign({}, prev); n[fd.taxonomy] = terms; return n; });
                        })
                        .catch(function () {});
                });
            }, []);

            useEffect(function () {
                if (!open) { return; }
                doSearch(search);
            }, [open, userFilters]);

            function doSearch(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 20, _fields: 'id,title,link' });
                if (term) { params.set('search', term); }

                fixedDefs.forEach(function (fd) {
                    var key  = fd.taxonomy === 'category' ? 'categories' : fd.taxonomy === 'post_tag' ? 'tags' : fd.taxonomy;
                    var nums = fd.terms.map(function (t) { return t.id !== undefined ? t.id : t.slug; })
                                       .filter(function (v) { return typeof v === 'number'; });
                    if (nums.length > 0) { params.set(key, nums.join(',')); }
                });

                interactiveDefs.forEach(function (fd) {
                    var val = userFilters[fd.taxonomy];
                    if (val === undefined || val === null || val === '') { return; }
                    var key = fd.taxonomy === 'category' ? 'categories' : fd.taxonomy === 'post_tag' ? 'tags' : fd.taxonomy;
                    if (typeof val === 'number' || !isNaN(val)) {
                        params.set(key, String(val));
                    } else {
                        var opts  = termOpts[fd.taxonomy] || [];
                        var match = opts.filter(function (t) { return t.slug === val || String(t.id) === String(val); })[0];
                        if (match) { params.set(key, String(match.id)); }
                    }
                });

                apiFetch({ path: '/wp/v2/' + postEndpoint + '?' + params.toString() })
                    .then(function (posts) {
                        setResults(posts.map(function (post) {
                            return { id: post.id, title: post.title ? (post.title.rendered || post.title) : '#' + post.id, url: post.link || '' };
                        }));
                        setLoading(false);
                    })
                    .catch(function () { setLoading(false); });
            }

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function () { doSearch(term); }, 350);
            }

            function setInteractiveFilter(taxonomy, val) {
                var nf = Object.assign({}, userFilters);
                nf[taxonomy] = (userFilters[taxonomy] !== undefined && String(userFilters[taxonomy]) === String(val)) ? '' : val;
                if (multiple) { onChange({ posts: selected, filters: nf }); }
                else          { onChange({ post:  selected, filters: nf }); }
            }

            function selectPost(post) {
                if (multiple) {
                    var np = isSelected(post.id)
                        ? selected.filter(function (q) { return q.id !== post.id; })
                        : selected.concat([post]);
                    onChange({ posts: np, filters: userFilters });
                } else {
                    onChange({ post: post, filters: userFilters });
                    setOpen(false);
                }
            }

            function removePost(id) {
                if (multiple) { onChange({ posts: selected.filter(function (q) { return q.id !== id; }), filters: userFilters }); }
                else          { onChange({ post: null, filters: userFilters }); }
            }

            function renderTag(post) {
                return el('span', { key: post.id, className: 'dgb-post-tag' },
                    post.title,
                    el('button', { className: 'dgb-post-tag-remove', onClick: function (e) { e.stopPropagation(); removePost(post.id); } }, '\u00d7')
                );
            }

            var tagsContent = multiple
                ? (selected.length > 0 ? selected.map(renderTag) : el('span', { className: 'dgb-post-select-placeholder' }, 'Select ' + post_type + '\u2026'))
                : (selected ? renderTag(selected) : el('span', { className: 'dgb-post-select-placeholder' }, 'Select ' + post_type + '\u2026'));

            var postList;
            if (loading) {
                postList = el('div', { className: 'dgb-post-select-loading' }, el(Spinner));
            } else if (results.length === 0) {
                postList = el('div', { className: 'dgb-post-select-empty' }, 'No posts found.');
            } else {
                postList = el('ul', { className: 'dgb-post-select-results' },
                    results.map(function (post) {
                        return el('li', {
                            key: post.id,
                            className: 'dgb-post-select-item' + (isSelected(post.id) ? ' is-selected' : ''),
                            onClick: function (e) { e.stopPropagation(); selectPost(post); }
                        },
                            isSelected(post.id) ? el('span', { className: 'dashicons dashicons-yes' }) : null,
                            post.title
                        );
                    })
                );
            }

            var filterPanels = interactiveDefs.map(function (fd) {
                var opts   = termOpts[fd.taxonomy] || [];
                var curVal = userFilters[fd.taxonomy] !== undefined ? String(userFilters[fd.taxonomy]) : '';
                var sopts  = [{ label: 'All ' + (fd.label || fd.taxonomy), value: '' }].concat(
                    opts.map(function (t) { return { label: t.name, value: typeof t.id === 'number' ? t.id : t.slug }; })
                );
                return el('div', { key: fd.taxonomy, className: 'dgb-fps-filter' },
                    el('div', { className: 'dgb-fps-filter-label' }, fd.label || fd.taxonomy),
                    opts.length === 0
                        ? el('div', { className: 'dgb-fps-filter-loading' }, el(Spinner))
                        : el(SelectControl, {
                            value: curVal,
                            options: sopts,
                            onChange: function (val) {
                                var parsed = val === '' ? '' : (isNaN(val) ? val : Number(val));
                                setInteractiveFilter(fd.taxonomy, parsed);
                            },
                            onClick: function (e) { e.stopPropagation(); }
                        })
                );
            });

            return el('div', { className: 'dgb-fps' },
                el('label', { className: 'components-base-control__label' }, label),
                el('div', {
                    ref: anchorRef,
                    className: 'dgb-post-select-control' + (open ? ' is-open' : ''),
                    onClick: function () { setOpen(!open); }
                },
                    el('div', { className: 'dgb-post-select-tags' }, tagsContent),
                    el('span', { className: 'dgb-post-select-arrow dashicons dashicons-arrow-down-alt2' })
                ),
                open ? el(PositionedDropdown, { anchorRef: anchorRef, onClose: function () { setOpen(false); } },
                    interactiveDefs.length > 0 ? el('div', { className: 'dgb-fps-filters' }, filterPanels) : null,
                    el(TextControl, {
                        placeholder: 'Search ' + post_type + '\u2026',
                        value: search, onChange: handleSearch,
                        onClick: function (e) { e.stopPropagation(); }
                    }),
                    postList
                ) : null
            );
        },
        getDefault: function () { return { post: null, posts: [], filters: {} }; }
    };

    /* -- post-select ------------------------------------------------- */
    FIELD_TYPES['post-select'] = {
        component: function PostSelectField(p) {
            var label     = p.label;
            var value     = p.value;
            var onChange  = p.onChange;
            var post_type = p.post_type || 'post';
            var multiple  = !!p.multiple;

            var builtinPost = { post: 'posts', page: 'pages', attachment: 'media' };
            var endpoint    = p.rest_base ? p.rest_base : (builtinPost[post_type] || (post_type + 's'));

            var ss = useState('');    var search  = ss[0];   var setSearch  = ss[1];
            var rs = useState([]);    var results = rs[0];   var setResults = rs[1];
            var ls = useState(false); var loading = ls[0];   var setLoading = ls[1];
            var os = useState(false); var open    = os[0];   var setOpen    = os[1];
            var searchTimer = useRef(null);
            var anchorRef   = useRef(null);

            var selected = multiple
                ? (Array.isArray(value) ? value : (value ? [value] : []))
                : (value && typeof value === 'object' ? value : null);

            function isSelected(id) {
                return multiple ? selected.some(function (q) { return q.id === id; }) : (selected && selected.id === id);
            }

            function doSearch(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 20, orderby: 'relevance', _fields: 'id,title,link' });
                if (term) { params.set('search', term); }
                apiFetch({ path: '/wp/v2/' + endpoint + '?' + params.toString() })
                    .then(function (posts) {
                        setResults(posts.map(function (post) {
                            return { id: post.id, title: post.title ? (post.title.rendered || post.title) : '#' + post.id, url: post.link || '' };
                        }));
                        setLoading(false);
                    })
                    .catch(function () { setLoading(false); });
            }

            useEffect(function () { if (open) { doSearch(search); } }, [open]);

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function () { doSearch(term); }, 350);
            }

            function selectPost(post) {
                if (multiple) {
                    if (isSelected(post.id)) { onChange(selected.filter(function (q) { return q.id !== post.id; })); }
                    else                     { onChange(selected.concat([post])); }
                } else { onChange(post); setOpen(false); }
            }

            function removePost(id) {
                if (multiple) { onChange(selected.filter(function (q) { return q.id !== id; })); }
                else          { onChange(null); }
            }

            function renderTag(post) {
                return el('span', { key: post.id, className: 'dgb-post-tag' },
                    post.title,
                    el('button', { className: 'dgb-post-tag-remove', onClick: function (e) { e.stopPropagation(); removePost(post.id); } }, '\u00d7')
                );
            }

            var tagsContent = multiple
                ? (selected.length > 0 ? selected.map(renderTag) : el('span', { className: 'dgb-post-select-placeholder' }, 'Select ' + post_type + '\u2026'))
                : (selected ? renderTag(selected) : el('span', { className: 'dgb-post-select-placeholder' }, 'Select ' + post_type + '\u2026'));

            var dropContent;
            if (loading) {
                dropContent = el('div', { className: 'dgb-post-select-loading' }, el(Spinner));
            } else if (results.length === 0) {
                dropContent = el('div', { className: 'dgb-post-select-empty' }, 'No results found.');
            } else {
                dropContent = el('ul', { className: 'dgb-post-select-results' },
                    results.map(function (post) {
                        return el('li', {
                            key: post.id,
                            className: 'dgb-post-select-item' + (isSelected(post.id) ? ' is-selected' : ''),
                            onClick: function (e) { e.stopPropagation(); selectPost(post); }
                        },
                            isSelected(post.id) ? el('span', { className: 'dashicons dashicons-yes' }) : null,
                            post.title
                        );
                    })
                );
            }

            return el('div', { className: 'dgb-post-select' },
                el('label', { className: 'components-base-control__label' }, label),
                el('div', {
                    ref: anchorRef,
                    className: 'dgb-post-select-control' + (open ? ' is-open' : ''),
                    onClick: function () { setOpen(!open); }
                },
                    el('div', { className: 'dgb-post-select-tags' }, tagsContent),
                    el('span', { className: 'dgb-post-select-arrow dashicons dashicons-arrow-down-alt2' })
                ),
                open ? el(PositionedDropdown, { anchorRef: anchorRef, onClose: function () { setOpen(false); } },
                    el(TextControl, { placeholder: 'Search\u2026', value: search, onChange: handleSearch, onClick: function (e) { e.stopPropagation(); } }),
                    dropContent
                ) : null
            );
        },
        getDefault: function (f) { return f.multiple ? [] : null; }
    };

    /* -- taxonomy-select --------------------------------------------- */
    FIELD_TYPES['taxonomy-select'] = {
        component: function TaxonomySelectField(p) {
            var label    = p.label;
            var value    = p.value;
            var onChange = p.onChange;
            var taxonomy = p.taxonomy || 'category';
            var multiple = p.multiple !== false;

            var builtinTax = { category: 'categories', post_tag: 'tags' };
            var endpoint   = p.rest_base ? p.rest_base : (builtinTax[taxonomy] || taxonomy);

            var ss = useState('');   var search  = ss[0];  var setSearch  = ss[1];
            var ts = useState([]);   var terms   = ts[0];  var setTerms   = ts[1];
            var ls = useState(true); var loading = ls[0];  var setLoading = ls[1];
            var searchTimer = useRef(null);

            var selected = Array.isArray(value) ? value : (value ? [value] : []);

            function loadTerms(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 50, hide_empty: false, _fields: 'id,name,slug,count' });
                if (term) { params.set('search', term); }
                apiFetch({ path: '/wp/v2/' + endpoint + '?' + params.toString() })
                    .then(function (d) { setTerms(d); setLoading(false); })
                    .catch(function () { setLoading(false); });
            }

            useEffect(function () { loadTerms(''); }, []);

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function () { loadTerms(term); }, 350);
            }

            function isChecked(id) { return selected.some(function (t) { return t.id === id; }); }

            function toggle(term) {
                if (multiple) {
                    onChange(isChecked(term.id)
                        ? selected.filter(function (t) { return t.id !== term.id; })
                        : selected.concat([{ id: term.id, name: term.name, slug: term.slug }]));
                } else {
                    onChange(isChecked(term.id) ? [] : [{ id: term.id, name: term.name, slug: term.slug }]);
                }
            }

            var filtered = terms.filter(function (t) { return t.name.toLowerCase().indexOf(search.toLowerCase()) !== -1; });

            var listContent;
            if (loading) {
                listContent = el('div', { className: 'dgb-taxonomy-loading' }, el(Spinner));
            } else if (filtered.length === 0) {
                listContent = el('div', { className: 'dgb-taxonomy-empty' }, 'No terms found.');
            } else {
                listContent = filtered.map(function (term) {
                    return el('label', { key: term.id, className: 'dgb-taxonomy-item' + (isChecked(term.id) ? ' is-selected' : '') },
                        el('input', { type: multiple ? 'checkbox' : 'radio', checked: isChecked(term.id), onChange: function () { toggle(term); } }),
                        el('span', { className: 'dgb-taxonomy-name' }, term.name),
                        el('span', { className: 'dgb-taxonomy-count' }, '(' + term.count + ')')
                    );
                });
            }

            return el('div', { className: 'dgb-taxonomy-select' },
                el('label', { className: 'components-base-control__label' }, label),
                selected.length > 0 ? el('div', { className: 'dgb-taxonomy-selected' },
                    selected.map(function (t) {
                        return el('span', { key: t.id, className: 'dgb-taxonomy-badge' },
                            t.name,
                            el('button', { className: 'dgb-taxonomy-badge-remove', onClick: function () { onChange(selected.filter(function (s) { return s.id !== t.id; })); } }, '\u00d7')
                        );
                    })
                ) : null,
                el(TextControl, { placeholder: 'Search ' + taxonomy + '\u2026', value: search, onChange: handleSearch }),
                el('div', { className: 'dgb-taxonomy-list' }, listContent)
            );
        },
        getDefault: function () { return []; }
    };

    /* -- radio ------------------------------------------------------- */
    FIELD_TYPES['radio'] = {
        component: function RadioField(p) {
            var options = p.options || [];
            return el('div', { className: 'dgb-radio-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el('div', { className: 'dgb-radio-options' },
                    options.map(function (o, i) {
                        return el('div', { key: i, className: 'dgb-radio-option' },
                            el('label', {},
                                el('input', { type: 'radio', name: p.label, value: o.value, checked: p.value === o.value, onChange: function (e) { p.onChange(e.target.value); } }),
                                el('span', { className: 'dgb-radio-label' }, o.label)
                            )
                        );
                    })
                )
            );
        },
        getDefault: function (f) { return f.default || (f.options && f.options[0] ? f.options[0].value : ''); }
    };

    /* -- checkbox ---------------------------------------------------- */
    FIELD_TYPES['checkbox'] = {
        component: function CheckboxField(p) {
            var options = p.options || [];
            var sel     = Array.isArray(p.value) ? p.value : [];
            return el('div', { className: 'dgb-checkbox-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el('div', { className: 'dgb-checkbox-options' },
                    options.map(function (o, i) {
                        return el('div', { key: i, className: 'dgb-checkbox-option' },
                            el('label', {},
                                el('input', {
                                    type: 'checkbox', value: o.value,
                                    checked: sel.indexOf(o.value) !== -1,
                                    onChange: function (e) {
                                        if (e.target.checked) { p.onChange(sel.concat([o.value])); }
                                        else                   { p.onChange(sel.filter(function (v) { return v !== o.value; })); }
                                    }
                                }),
                                el('span', { className: 'dgb-checkbox-label' }, o.label)
                            )
                        );
                    })
                )
            );
        },
        getDefault: function (f) { return f.default || []; }
    };

    /* -- single-checkbox --------------------------------------------- */
    FIELD_TYPES['single-checkbox'] = {
        component: function SingleCheckboxField(p) {
            return el('div', { className: 'dgb-single-checkbox' },
                el('label', {},
                    el('input', { type: 'checkbox', checked: Boolean(p.value), onChange: function (e) { p.onChange(e.target.checked); } }),
                    el('span', { className: 'dgb-checkbox-label' }, p.checkboxLabel || p.label)
                )
            );
        },
        getDefault: function (f) { return f.default || false; }
    };

    /* ================================================================
       ICON RENDERER & SVG FALLBACKS
    ================================================================ */
    var SVG_ICONS = {
        'edit': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M19.7 5.3c-.4-.4-1-.4-1.4 0L16 7.6 18.4 10l2.3-2.3c.4-.4.4-1 0-1.4l-1-1zm-4.8 3.4L6 17.6V20h2.4L17.3 11.1 14.9 8.7z' })
        ),
        'admin-settings': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z' })
        ),
        'format-quote': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z' })
        ),
        'admin-generic': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z' })
        ),
        'format-image': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z' })
        ),
        'admin-appearance': el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'currentColor' },
            el('path', { d: 'M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L4.35 19.4c-.39.39-.39 1.02 0 1.41.39.39 1.02.39 1.41 0l1.9-1.9C9.28 19.57 10.59 20 12 20c4.97 0 9-4.03 9-9s-4.03-9-9-9zm0 15c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6z' })
        )
    };

    function renderTabIcon(iconName) {
        if (!iconName) {
            return null;
        }

        var clean = String(iconName).trim().replace(/^dashicons-/, '');

        // 1. Direct SVG map match (highest reliability across iframes and themes)
        if (SVG_ICONS[clean]) {
            return el('span', { className: 'dgb-tab-icon dgb-tab-svg-icon' }, SVG_ICONS[clean]);
        }

        // 2. Dashicon font icon (with dashicons class)
        return el('span', { className: 'dashicons dashicons-' + clean + ' dgb-tab-icon' });
    }

    /* ================================================================
       TAB BUTTONS
    ================================================================ */
    function TabButtons(p) {
        return el('div', { className: 'dgb-tab-buttons' },
            p.tabs.map(function (tab) {
                return el('button', {
                    key: tab.name, type: 'button',
                    className: 'dgb-tab-button' + (p.activeTab === tab.name ? ' active' : ''),
                    onClick: function () { p.onTabChange(tab.name); }
                },
                    el('span', { className: 'dgb-tab-button-content' },
                        renderTabIcon(tab.icon),
                        ' ', tab.title
                    )
                );
            })
        );
    }

    /* ================================================================
       REGISTER DYNAMIC BLOCKS (MODERNIZED HYBRID ARCHITECTURE)
    ================================================================ */
    if (window.dgbBlockConfigs && window.dgbBlockConfigs.blocks) {
        Object.keys(window.dgbBlockConfigs.blocks).forEach(function (name) {
            var config = window.dgbBlockConfigs.blocks[name];

            blocks.registerBlockType('dgb/' + name, {
                title:       config.title,
                description: config.description || '',
                icon:        config.icon        || 'admin-generic',
                category:    config.category    || 'widgets',
                keywords:    config.keywords    || [],
                attributes:  config.attributes  || {},
                supports:    config.supports    || { html: false, align: true, className: true },

                edit: function (props) {
                    var blockProps = useBlockProps({
                        className: 'dgb-block-wrapper dgb-block-' + name
                    });

                    // Initial view mode: 'edit' for new unconfigured blocks, 'preview' for existing blocks with data
                    var isNewBlock = useMemo(function () {
                        var attrs = props.attributes || {};
                        var keys = Object.keys(attrs);
                        for (var i = 0; i < keys.length; i++) {
                            var k = keys[i];
                            if (k !== '_data' && k !== 'className' && k !== 'align' && attrs[k] !== undefined && attrs[k] !== '' && attrs[k] !== null) {
                                return false;
                            }
                        }
                        return true;
                    }, []);

                    var modeState = useState(isNewBlock ? 'edit' : 'preview');
                    var mode      = modeState[0];
                    var setMode   = modeState[1];

                    var tabs         = config.tabs || [];
                    var tabState     = useState(tabs.length > 0 ? tabs[0].name : '');
                    var activeTab    = tabState[0];
                    var setActiveTab = tabState[1];

                    // Find fields for active tab
                    var currentTab = null;
                    if (tabs.length > 0) {
                        for (var t = 0; t < tabs.length; t++) {
                            if (tabs[t].name === activeTab) {
                                currentTab = tabs[t];
                                break;
                            }
                        }
                        if (!currentTab) {
                            currentTab = tabs[0];
                        }
                    }
                    var activeFields = currentTab ? (currentTab.fields || []) : (config.fields || []);

                    // Determine which sections and fields appear in the Sidebar Inspector
                    var sidebarPanels = useMemo(function () {
                        var panels = [];
                        var configSidebarTabs = config.sidebar_tabs || [];
                        if (typeof configSidebarTabs === 'string') {
                            configSidebarTabs = configSidebarTabs.split(',').map(function (s) { return s.trim(); });
                        }

                        // Helper: Check if tab explicitly requests sidebar placement
                        function tabWantsSidebar(tab) {
                            if (!tab) { return false; }
                            if (tab.sidebar === true || tab.sidebar === 'true' || tab.sidebar === 'yes' || tab.sidebar === 1) { return true; }
                            if (tab.show_in_sidebar === true || tab.show_in_sidebar === 'true' || tab.show_in_sidebar === 'yes' || tab.show_in_sidebar === 1) { return true; }
                            if (tab.placement === 'sidebar' || tab.placement === 'both') { return true; }
                            if (configSidebarTabs.indexOf(tab.name) !== -1) { return true; }
                            return false;
                        }

                        // Helper: Check if an individual field explicitly requests sidebar placement
                        function fieldWantsSidebar(field) {
                            if (!field) { return false; }
                            if (field.sidebar === true || field.sidebar === 'true' || field.sidebar === 'yes' || field.sidebar === 1) { return true; }
                            if (field.show_in_sidebar === true || field.show_in_sidebar === 'true' || field.show_in_sidebar === 'yes' || field.show_in_sidebar === 1) { return true; }
                            if (field.placement === 'sidebar' || field.placement === 'both') { return true; }
                            return false;
                        }

                        // 1. Check user-specified configuration
                        tabs.forEach(function (tab) {
                            var tabFields = tab.fields || [];
                            if (tabWantsSidebar(tab)) {
                                if (tabFields.length > 0) {
                                    panels.push({
                                        id:     tab.name,
                                        title:  tab.title || tab.name,
                                        fields: tabFields
                                    });
                                }
                            } else {
                                var specificFields = tabFields.filter(fieldWantsSidebar);
                                if (specificFields.length > 0) {
                                    panels.push({
                                        id:     tab.name,
                                        title:  tab.title || tab.name,
                                        fields: specificFields
                                    });
                                }
                            }
                        });

                        // 2. Backward compatibility fallback: only if user has NOT configured any sidebar tabs/fields
                        if (panels.length === 0) {
                            tabs.forEach(function (tab) {
                                var fallbackFields = (tab.fields || []).filter(function (f) {
                                    var an = f.attr_name || '';
                                    return an.indexOf('style.') === 0;
                                });
                                if (fallbackFields.length > 0) {
                                    panels.push({
                                        id:     tab.name,
                                        title:  tab.title || 'Style Settings',
                                        fields: fallbackFields
                                    });
                                }
                            });
                        }

                        return panels;
                    }, [tabs, config.sidebar_tabs]);

                    // Helper to render an individual field
                    function renderField(field, keyPrefix) {
                        var ft = FIELD_TYPES[field.type];
                        if (!ft) { return null; }

                        var val = getFieldValue(props.attributes, field);

                        var fp = Object.assign({}, field.validation || {}, {
                            label:       field.label,
                            placeholder: field.placeholder,
                            help:        field.help,
                            value:       val,
                            onChange:    function (newVal) {
                                setFieldValue(props.setAttributes, props.attributes, field, newVal);
                            }
                        });

                        if (field.type === 'select' || field.type === 'radio' || field.type === 'checkbox') {
                            fp.options = field.options;
                        }
                        if (field.type === 'single-checkbox') {
                            fp.checkboxLabel = field.checkboxLabel;
                        }
                        if (field.type === 'file') {
                            fp.allowed_types = field.allowed_types;
                            fp.multiple      = field.multiple;
                        }
                        if (field.type === 'post-select') {
                            fp.post_type = field.post_type;
                            fp.rest_base = field.rest_base;
                            fp.multiple  = field.multiple;
                        }
                        if (field.type === 'taxonomy-select') {
                            fp.taxonomy  = field.taxonomy;
                            fp.rest_base = field.rest_base;
                            fp.multiple  = field.multiple;
                        }
                        if (field.type === 'filtered-post-select') {
                            fp.post_type = field.post_type;
                            fp.rest_base = field.rest_base;
                            fp.multiple  = field.multiple;
                            fp.filters   = field.filters;
                        }
                        if (field.type === 'row_repeater') {
                            fp.repeater_fields = field.repeater_fields;
                            fp.layout          = field.layout;
                            fp.summary_field   = field.summary_field;
                        }

                        return el('div', {
                            key: (keyPrefix || '') + (field.attr_name || field.name),
                            className: 'dgb-field dgb-field-' + field.type
                        },
                            el(ft.component, fp)
                        );
                    }

                    // 1. Toolbar Controls (BlockControls)
                    var toolbar = el(BlockControls, { group: 'block' },
                        el(ToolbarGroup, {},
                            el(ToolbarButton, {
                                icon: mode === 'edit' ? 'visibility' : 'edit',
                                label: mode === 'edit' ? 'Switch to Preview' : 'Edit Block Form',
                                isPressed: mode === 'edit',
                                onClick: function () {
                                    setMode(mode === 'edit' ? 'preview' : 'edit');
                                }
                            })
                        )
                    );

                    // 2. Sidebar Inspector Controls (InspectorControls)
                    var sidebar = el(InspectorControls, {},
                        el(PanelBody, { title: 'Block Mode & Actions', initialOpen: true },
                            el('div', { style: { marginBottom: '12px' } },
                                el(Button, {
                                    variant: mode === 'edit' ? 'primary' : 'secondary',
                                    style: { width: '100%', justifyContent: 'center' },
                                    onClick: function () {
                                        setMode(mode === 'edit' ? 'preview' : 'edit');
                                    }
                                },
                                    mode === 'edit' ? '\ud83d\udc41\ufe0f View Live Preview' : '\u270f\ufe0f Open Canvas Form View'
                                )
                            )
                        ),
                        sidebarPanels.map(function (panel) {
                            return el(PanelBody, {
                                key: 'sidebar-panel-' + panel.id,
                                title: panel.title,
                                initialOpen: true
                            },
                                panel.fields.map(function (f) {
                                    return renderField(f, 'sidebar-' + panel.id + '-');
                                })
                            );
                        })
                    );

                    // 3. Canvas Content (Spacious Form View vs Live WYSIWYG Preview)
                    var canvas;
                    if (mode === 'edit') {
                        // Spacious Canvas Form View
                        canvas = el('div', { className: 'dgb-canvas-form' },
                            el('div', { className: 'dgb-form-header' },
                                el('div', { className: 'dgb-form-title-group' },
                                    renderTabIcon(config.icon || 'admin-generic'),
                                    el('span', { className: 'dgb-form-title' }, config.title || name),
                                    el('span', { className: 'dgb-form-badge' }, 'Edit Form')
                                ),
                                el(Button, {
                                    variant: 'primary',
                                    size: 'small',
                                    onClick: function () { setMode('preview'); }
                                }, 'Done (Preview \ud83d\udc41\ufe0f)')
                            ),
                            tabs.length > 1 ? el(TabButtons, {
                                tabs: tabs,
                                activeTab: activeTab,
                                onTabChange: setActiveTab
                            }) : null,
                            el('div', { className: 'dgb-tab-content' },
                                activeFields.length > 0
                                    ? activeFields.map(function (f) { return renderField(f, 'form-'); })
                                    : el('p', { className: 'dgb-empty-notice' }, 'No fields defined for this section.')
                            )
                        );
                    } else {
                        // Live WYSIWYG Preview View
                        var ssr = ServerSideRender ? el(ServerSideRender, {
                            block: 'dgb/' + name,
                            attributes: props.attributes,
                            httpMethod: 'POST'
                        }) : el('div', { className: 'dgb-no-ssr' }, 'ServerSideRender component is loading\u2026');

                        var innerBlocks = config.has_innerblocks ? el('div', { className: 'dgb-preview-innerblocks' },
                            el('div', { className: 'dgb-innerblocks-label' }, 'Nested Blocks (Drag / Insert blocks here):'),
                            el(InnerBlocks, {
                                templateLock: false,
                                renderAppender: InnerBlocks.ButtonBlockAppender
                            })
                        ) : null;

                        canvas = el('div', { className: 'dgb-canvas-preview' },
                            ssr,
                            innerBlocks
                        );
                    }

                    return el('div', blockProps,
                        toolbar,
                        sidebar,
                        canvas
                    );
                },

                save: function () {
                    return config.has_innerblocks ? el(InnerBlocks.Content) : null;
                }
            });
        });
    }

    /* ================================================================
       BACKWARD COMPATIBILITY: DGB FIELD BLOCK
    ================================================================ */
    blocks.registerBlockType('dgb/field', {
        title: 'DGB Field',
        supports: {
            inserter: false,
            reusable: false,
            html: false
        },
        attributes: {
            name:      { type: 'string',  default: '' },
            type:      { type: 'string',  default: '' },
            label:     { type: 'string',  default: '' },
            value:     { type: ['string', 'array', 'boolean', 'number', 'object'], default: '' },
            attr_name: { type: 'string',  default: '' }
        },
        edit: function () { return null; },
        save: function () { return null; }
    });

}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.data,
    window.wp.apiFetch
));