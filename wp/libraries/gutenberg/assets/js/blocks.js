(function(blocks, element, blockEditor, components, data, apiFetch) {

    var el              = element.createElement;
    var useBlockProps   = blockEditor.useBlockProps;
    var TextControl     = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var SelectControl   = components.SelectControl;
    var ToggleControl   = components.ToggleControl;
    var Button          = components.Button;
    var Spinner         = components.Spinner;
    var InnerBlocks     = blockEditor.InnerBlocks;
    var MediaUpload     = blockEditor.MediaUpload;
    var useState        = element.useState;
    var useEffect       = element.useEffect;
    var useRef          = element.useRef;
    var useMemo         = element.useMemo;
    var createPortal    = element.createPortal;

    /* ==================================================================
       DEFERRED VALUE HOOK
       Keeps a local copy that updates instantly on each keystroke.
       Commits to the block attribute only on debounce + blur.
    ================================================================== */
    function useDeferredValue(value, commit, delay) {
        delay = delay || 350;
        var ls = useState(value);
        var local = ls[0];
        var setLocal = ls[1];
        var timer = useRef(null);
        var latest = useRef(value);
        var commitRef = useRef(commit);

        useEffect(function() { commitRef.current = commit; });

        useEffect(function() {
            setLocal(value);
            latest.current = value;
        }, [value]);

        useEffect(function() {
            return function() {
                if (timer.current) {
                    clearTimeout(timer.current);
                    if (latest.current !== value) {
                        commitRef.current(latest.current);
                    }
                }
            };
        }, []);

        function onChange(next) {
            setLocal(next);
            latest.current = next;
            if (timer.current) { clearTimeout(timer.current); }
            timer.current = setTimeout(function() {
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

    /* ==================================================================
       POSITIONED DROPDOWN
       Portals onto document.body so it is never clipped by a stacking
       context inside the editor tree.
    ================================================================== */
    function PositionedDropdown(props) {
        var anchorRef = props.anchorRef;
        var onClose   = props.onClose;

        var ss = useState({});
        var style = ss[0];
        var setStyle = ss[1];
        var dropRef = useRef(null);

        useEffect(function() {
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
            return function() {
                window.removeEventListener('scroll', position, true);
                window.removeEventListener('resize', position);
            };
        }, [anchorRef]);

        useEffect(function() {
            function handleClick(e) {
                if (dropRef.current && !dropRef.current.contains(e.target) &&
                    anchorRef.current && !anchorRef.current.contains(e.target)) {
                    onClose();
                }
            }
            document.addEventListener('mousedown', handleClick);
            return function() { document.removeEventListener('mousedown', handleClick); };
        }, [onClose, anchorRef]);

        return createPortal(
            el('div', { ref: dropRef, style: style, className: 'dgb-post-select-dropdown' },
                props.children
            ),
            document.body
        );
    }

    /* ==================================================================
       FIELD TYPES
    ================================================================== */
    var FIELD_TYPES = {};

    FIELD_TYPES['text'] = {
        component: function TextField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: p.placeholder, help: p.help });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['textarea'] = {
        component: function TextareaField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: p.placeholder, rows: 4 });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['number'] = {
        component: function NumberField(p) {
            function commit(v) { p.onChange(v !== '' ? Number(v) : ''); }
            var d = useDeferredValue(p.value === '' || p.value === undefined ? '' : p.value, commit);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'number' });
        },
        getDefault: function() { return 0; }
    };

    FIELD_TYPES['small-number'] = {
        component: function SmallNumberField(p) {
            function commit(v) { p.onChange(v !== '' ? Number(v) : ''); }
            var d = useDeferredValue(p.value === '' || p.value === undefined ? '' : p.value, commit);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'number', min: p.min, max: p.max, className: 'dgb-small-number' });
        },
        getDefault: function(f) { return f.default || 0; }
    };

    FIELD_TYPES['select'] = {
        component: SelectControl,
        getDefault: function(f) { return f.default || ''; }
    };

    FIELD_TYPES['toggle'] = {
        component: ToggleControl,
        getDefault: function(f) { return f.default || false; }
    };

    FIELD_TYPES['image'] = {
        component: function ImageField(p) {
            var value = p.value;
            var onChange = p.onChange;
            var altD = useDeferredValue(value && value.alt ? value.alt : '', function(n) { onChange(Object.assign({}, value, { alt: n })); });
            var titleD = useDeferredValue(value && value.title ? value.title : '', function(n) { onChange(Object.assign({}, value, { title: n })); });

            return el('div', { className: 'dgb-image-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el(MediaUpload, {
                    onSelect: function(m) { onChange({ id: m.id, url: m.url, alt: m.alt || '', title: m.title || '' }); },
                    allowedTypes: ['image'],
                    value: value ? value.id : undefined,
                    render: function(rp) {
                        if (value && value.url) {
                            return el('div', { className: 'dgb-image-controls' },
                                el('img', { src: value.url, alt: value.alt, className: 'dgb-image-preview' }),
                                el(TextControl, { label: 'Alt Text', value: altD[0], onChange: altD[1], onBlur: altD[2] }),
                                el(TextControl, { label: 'Title', value: titleD[0], onChange: titleD[1], onBlur: titleD[2] }),
                                el('div', { className: 'dgb-image-actions' },
                                    el(Button, { onClick: rp.open, variant: 'secondary' }, 'Replace Image'),
                                    el(Button, { onClick: function() { onChange(null); }, isDestructive: true }, 'Remove')
                                )
                            );
                        }
                        return el(Button, { onClick: rp.open, variant: 'secondary' }, 'Select Image');
                    }
                })
            );
        },
        getDefault: function() { return null; }
    };

    FIELD_TYPES['attributes-repeater'] = {
        component: function AttributesRepeater(p) {
            var attrs = Array.isArray(p.value) ? p.value : [];

            function add() { p.onChange(attrs.concat([{ name: '', type: 'str', value: '' }])); }

            return el('div', { className: 'dgb-attributes-container' },
                el('h4', {}, p.label),
                attrs.map(function(attr, i) {
                    return el('div', { key: i, className: 'dgb-attribute-row' },
                        el('div', { className: 'dgb-attribute-inputs' },
                            el(FIELD_TYPES['text'].component, {
                                label: 'Name', value: attr.name || '',
                                onChange: function(v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { name: v }); p.onChange(a); }
                            }),
                            el(SelectControl, {
                                label: 'Type', value: attr.type || 'str',
                                options: [
                                    { label: 'String', value: 'str' }, { label: 'Integer', value: 'int' },
                                    { label: 'Number', value: 'num' }, { label: 'Boolean', value: 'bool' },
                                    { label: 'Path', value: 'path' }
                                ],
                                onChange: function(v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { type: v }); p.onChange(a); }
                            }),
                            el(FIELD_TYPES['text'].component, {
                                label: 'Value', value: attr.value || '',
                                onChange: function(v) { var a = attrs.slice(); a[i] = Object.assign({}, attr, { value: v }); p.onChange(a); }
                            }),
                            el(Button, { isDestructive: true, onClick: function() { p.onChange(attrs.filter(function(_, j) { return j !== i; })); } }, '×')
                        )
                    );
                }),
                el(Button, { variant: 'secondary', onClick: add }, '+ Add Attribute')
            );
        },
        getDefault: function() { return []; }
    };

    FIELD_TYPES['row_repeater'] = {
        component: function RowRepeater(p) {
            var rows   = Array.isArray(p.value) ? p.value : [];
            var fields = Array.isArray(p.repeater_fields) ? p.repeater_fields : [];

            function addRow() {
                var r = {};
                fields.forEach(function(f) { var fc = FIELD_TYPES[f.type]; r[f.name] = fc ? fc.getDefault(f) : ''; });
                p.onChange(rows.concat([r]));
            }

            return el('div', { className: 'dgb-row-repeater' },
                el('h4', {}, p.label),
                el('div', { className: 'dgb-row-headers' },
                    fields.map(function(f) { return el('div', { key: f.name, className: 'dgb-row-header' }, f.label); }),
                    el('div', { className: 'dgb-row-header' })
                ),
                rows.map(function(row, ri) {
                    return el('div', { key: ri, className: 'dgb-row' },
                        fields.map(function(f) {
                            var ft = FIELD_TYPES[f.type];
                            if (!ft) { return null; }
                            var fp = Object.assign({}, f, {
                                label: '',
                                value: row[f.name],
                                onChange: function(v) { var nr = rows.slice(); nr[ri] = Object.assign({}, row); nr[ri][f.name] = v; p.onChange(nr); }
                            });
                            if (f.type === 'select') { fp.options = f.options; }
                            return el('div', { key: ri + '-' + f.name, className: 'dgb-row-cell' }, el(ft.component, fp));
                        }),
                        el('div', { className: 'dgb-row-cell' },
                            el(Button, { isDestructive: true, onClick: function() { p.onChange(rows.filter(function(_, j) { return j !== ri; })); } }, '×')
                        )
                    );
                }),
                el(Button, { variant: 'secondary', onClick: addRow }, '+ Add Row')
            );
        },
        getDefault: function() { return []; }
    };

    FIELD_TYPES['innerblocks'] = {
        component: function InnerBlocksField(p) {
            return el('div', { className: 'dgb-innerblocks-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el(InnerBlocks, { templateLock: false, renderAppender: InnerBlocks.ButtonBlockAppender })
            );
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['title'] = {
        component: function TitleField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label || 'Title', value: d[0], onChange: d[1], onBlur: d[2], className: 'dgb-title-field' });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['purpose'] = {
        component: function PurposeField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label || 'Purpose', value: d[0], onChange: d[1], onBlur: d[2], rows: 4 });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['query'] = {
        component: function QueryField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], rows: 6, placeholder: 'Enter query' });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['date'] = {
        component: function DateField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], type: 'date' });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['service'] = {
        component: function ServiceField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: 'e.g., str.create' });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['awesome_code'] = {
        component: function CodeField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextareaControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], rows: 8, className: 'dgb-code-field' });
        },
        getDefault: function() { return ''; }
    };

    FIELD_TYPES['env_path'] = {
        component: function EnvPathField(p) {
            var d = useDeferredValue(p.value || '', p.onChange);
            return el(TextControl, { label: p.label, value: d[0], onChange: d[1], onBlur: d[2], placeholder: 'e.g., module.settings.name' });
        },
        getDefault: function() { return ''; }
    };

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
                    el(Button, { isDestructive: true, isSmall: true, onClick: onRemove }, '×')
                );
            }

            return el('div', { className: 'dgb-file-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                !multiple ? el(MediaUpload, {
                    onSelect: function(m) { onChange(mkFile(m)); },
                    allowedTypes: types,
                    value: file ? file.id : undefined,
                    render: function(rp) {
                        if (file) {
                            return el('div', { className: 'dgb-file-selected' },
                                preview(file, function() { onChange(null); }),
                                el(Button, { variant: 'secondary', isSmall: true, onClick: rp.open }, 'Replace')
                            );
                        }
                        return el(Button, { variant: 'secondary', onClick: rp.open }, 'Select File');
                    }
                }) : null,
                multiple ? el(MediaUpload, {
                    onSelect: function(items) {
                        var picked = Array.isArray(items) ? items : [items];
                        var exist  = Array.isArray(value) ? value : [];
                        var ids    = exist.map(function(f) { return f.id; });
                        onChange(exist.concat(picked.filter(function(m) { return ids.indexOf(m.id) === -1; }).map(mkFile)));
                    },
                    allowedTypes: types,
                    multiple: true,
                    value: files.map(function(f) { return f.id; }),
                    render: function(rp) {
                        return el('div', {},
                            files.length > 0 ? el('div', { className: 'dgb-file-list' },
                                files.map(function(f, i) {
                                    return preview(f, function() { onChange(files.filter(function(_, j) { return j !== i; })); });
                                })
                            ) : null,
                            el(Button, { variant: 'secondary', onClick: rp.open }, files.length > 0 ? '+ Add More Files' : 'Select Files')
                        );
                    }
                }) : null
            );
        },
        getDefault: function(f) { return f.multiple ? [] : null; }
    };

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

            // Fixed defs have a terms whitelist — silent, no UI.
            // Interactive defs have no terms — show a dropdown.
            var fixedDefs       = filterDefs.filter(function(fd) { return Array.isArray(fd.terms) && fd.terms.length > 0; });
            var interactiveDefs = filterDefs.filter(function(fd) { return !Array.isArray(fd.terms) || fd.terms.length === 0; });

            var ss = useState('');    var search = ss[0];   var setSearch = ss[1];
            var rs = useState([]);    var results = rs[0];  var setResults = rs[1];
            var ls = useState(false); var loading = ls[0];  var setLoading = ls[1];
            var os = useState(false); var open = os[0];     var setOpen = os[1];
            var ts = useState({});    var termOpts = ts[0]; var setTermOpts = ts[1];

            var searchTimer = useRef(null);
            var anchorRef   = useRef(null);

            var userFilters = (value && value.filters) ? value.filters : {};

            var selected = multiple
                ? (Array.isArray(value && value.posts) ? value.posts : [])
                : ((value && value.post && typeof value.post === 'object') ? value.post : null);

            function isSelected(id) {
                return multiple ? selected.some(function(p) { return p.id === id; }) : (selected && selected.id === id);
            }

            // Load term options for interactive defs
            useEffect(function() {
                interactiveDefs.forEach(function(fd) {
                    var ep = fd.rest_base ? fd.rest_base : (builtinTax[fd.taxonomy] || fd.taxonomy);
                    apiFetch({ path: '/wp/v2/' + ep + '?per_page=100&hide_empty=false&_fields=id,name,slug' })
                        .then(function(terms) {
                            setTermOpts(function(prev) {
                                var next = Object.assign({}, prev);
                                next[fd.taxonomy] = terms;
                                return next;
                            });
                        })
                        .catch(function() {});
                });
            }, []);

            useEffect(function() {
                if (!open) { return; }
                doSearch(search);
            }, [open, userFilters]);

            function resolveFixedIds(fd) {
                // Normalise a terms whitelist to numeric ids where possible
                return fd.terms.map(function(t) {
                    return t.id !== undefined ? t.id : t.slug;
                });
            }

            function doSearch(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 20, _fields: 'id,title,link' });
                if (term) { params.set('search', term); }

                fixedDefs.forEach(function(fd) {
                    var key = fd.taxonomy === 'category' ? 'categories' : fd.taxonomy === 'post_tag' ? 'tags' : fd.taxonomy;
                    var vals = resolveFixedIds(fd);
                    var nums = vals.filter(function(v) { return typeof v === 'number'; });
                    if (nums.length > 0) { params.set(key, nums.join(',')); }
                });

                interactiveDefs.forEach(function(fd) {
                    var val = userFilters[fd.taxonomy];
                    if (val === undefined || val === null || val === '') { return; }
                    var key = fd.taxonomy === 'category' ? 'categories' : fd.taxonomy === 'post_tag' ? 'tags' : fd.taxonomy;
                    if (typeof val === 'number' || !isNaN(val)) {
                        params.set(key, String(val));
                    } else {
                        var opts  = termOpts[fd.taxonomy] || [];
                        var match = opts.filter(function(t) { return t.slug === val || String(t.id) === String(val); })[0];
                        if (match) { params.set(key, String(match.id)); }
                    }
                });

                apiFetch({ path: '/wp/v2/' + postEndpoint + '?' + params.toString() })
                    .then(function(posts) {
                        setResults(posts.map(function(post) {
                            return { id: post.id, title: post.title ? (post.title.rendered || post.title) : '#' + post.id, url: post.link || '' };
                        }));
                        setLoading(false);
                    })
                    .catch(function() { setLoading(false); });
            }

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function() { doSearch(term); }, 350);
            }

            function setInteractiveFilter(taxonomy, val) {
                var nf = Object.assign({}, userFilters);
                nf[taxonomy] = (userFilters[taxonomy] !== undefined && String(userFilters[taxonomy]) === String(val)) ? '' : val;
                if (multiple) { onChange({ posts: selected, filters: nf }); }
                else          { onChange({ post: selected,  filters: nf }); }
            }

            function selectPost(post) {
                if (multiple) {
                    var np = isSelected(post.id) ? selected.filter(function(q) { return q.id !== post.id; }) : selected.concat([post]);
                    onChange({ posts: np, filters: userFilters });
                } else {
                    onChange({ post: post, filters: userFilters });
                    setOpen(false);
                }
            }

            function removePost(id) {
                if (multiple) { onChange({ posts: selected.filter(function(q) { return q.id !== id; }), filters: userFilters }); }
                else          { onChange({ post: null, filters: userFilters }); }
            }

            function renderTag(post) {
                return el('span', { key: post.id, className: 'dgb-post-tag' },
                    post.title,
                    el('button', { className: 'dgb-post-tag-remove', onClick: function(e) { e.stopPropagation(); removePost(post.id); } }, '×')
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
                    results.map(function(post) {
                        return el('li', {
                            key: post.id,
                            className: 'dgb-post-select-item' + (isSelected(post.id) ? ' is-selected' : ''),
                            onClick: function(e) { e.stopPropagation(); selectPost(post); }
                        },
                            isSelected(post.id) ? el('span', { className: 'dashicons dashicons-yes' }) : null,
                            post.title
                        );
                    })
                );
            }

            var filterPanels = interactiveDefs.map(function(fd) {
                var opts  = termOpts[fd.taxonomy] || [];
                var curVal = userFilters[fd.taxonomy] !== undefined ? String(userFilters[fd.taxonomy]) : '';
                var sopts = [{ label: 'All ' + (fd.label || fd.taxonomy), value: '' }].concat(
                    opts.map(function(t) { return { label: t.name, value: typeof t.id === 'number' ? t.id : t.slug }; })
                );
                return el('div', { key: fd.taxonomy, className: 'dgb-fps-filter' },
                    el('div', { className: 'dgb-fps-filter-label' }, fd.label || fd.taxonomy),
                    opts.length === 0
                        ? el('div', { className: 'dgb-fps-filter-loading' }, el(Spinner))
                        : el(SelectControl, {
                            value: curVal,
                            options: sopts,
                            onChange: function(val) {
                                var parsed = val === '' ? '' : (isNaN(val) ? val : Number(val));
                                setInteractiveFilter(fd.taxonomy, parsed);
                            },
                            onClick: function(e) { e.stopPropagation(); }
                        })
                );
            });

            return el('div', { className: 'dgb-fps' },
                el('label', { className: 'components-base-control__label' }, label),
                el('div', {
                    ref: anchorRef,
                    className: 'dgb-post-select-control' + (open ? ' is-open' : ''),
                    onClick: function() { setOpen(!open); }
                },
                    el('div', { className: 'dgb-post-select-tags' }, tagsContent),
                    el('span', { className: 'dgb-post-select-arrow dashicons dashicons-arrow-down-alt2' })
                ),
                open ? el(PositionedDropdown, { anchorRef: anchorRef, onClose: function() { setOpen(false); } },
                    interactiveDefs.length > 0 ? el('div', { className: 'dgb-fps-filters' }, filterPanels) : null,
                    el(TextControl, {
                        placeholder: 'Search ' + post_type + '\u2026',
                        value: search, onChange: handleSearch,
                        onClick: function(e) { e.stopPropagation(); }
                    }),
                    postList
                ) : null
            );
        },
        getDefault: function() { return { post: null, posts: [], filters: {} }; }
    };

    FIELD_TYPES['post-select'] = {
        component: function PostSelectField(p) {
            var label     = p.label;
            var value     = p.value;
            var onChange  = p.onChange;
            var post_type = p.post_type || 'post';
            var multiple  = !!p.multiple;

            var builtinPost = { post: 'posts', page: 'pages', attachment: 'media' };
            var endpoint    = p.rest_base ? p.rest_base : (builtinPost[post_type] || (post_type + 's'));

            var ss = useState('');    var search = ss[0];   var setSearch = ss[1];
            var rs = useState([]);    var results = rs[0];  var setResults = rs[1];
            var ls = useState(false); var loading = ls[0];  var setLoading = ls[1];
            var os = useState(false); var open = os[0];     var setOpen = os[1];
            var searchTimer = useRef(null);
            var anchorRef   = useRef(null);

            var selected = multiple
                ? (Array.isArray(value) ? value : (value ? [value] : []))
                : (value && typeof value === 'object' ? value : null);

            function isSelected(id) {
                return multiple ? selected.some(function(q) { return q.id === id; }) : (selected && selected.id === id);
            }

            function doSearch(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 20, orderby: 'relevance', _fields: 'id,title,link' });
                if (term) { params.set('search', term); }
                apiFetch({ path: '/wp/v2/' + endpoint + '?' + params.toString() })
                    .then(function(posts) {
                        setResults(posts.map(function(post) {
                            return { id: post.id, title: post.title ? (post.title.rendered || post.title) : '#' + post.id, url: post.link || '' };
                        }));
                        setLoading(false);
                    })
                    .catch(function() { setLoading(false); });
            }

            useEffect(function() { if (open) { doSearch(search); } }, [open]);

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function() { doSearch(term); }, 350);
            }

            function selectPost(post) {
                if (multiple) {
                    if (isSelected(post.id)) { onChange(selected.filter(function(q) { return q.id !== post.id; })); }
                    else                     { onChange(selected.concat([post])); }
                } else {
                    onChange(post);
                    setOpen(false);
                }
            }

            function removePost(id) {
                if (multiple) { onChange(selected.filter(function(q) { return q.id !== id; })); }
                else          { onChange(null); }
            }

            function renderTag(post) {
                return el('span', { key: post.id, className: 'dgb-post-tag' },
                    post.title,
                    el('button', { className: 'dgb-post-tag-remove', onClick: function(e) { e.stopPropagation(); removePost(post.id); } }, '×')
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
                    results.map(function(post) {
                        return el('li', {
                            key: post.id,
                            className: 'dgb-post-select-item' + (isSelected(post.id) ? ' is-selected' : ''),
                            onClick: function(e) { e.stopPropagation(); selectPost(post); }
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
                    onClick: function() { setOpen(!open); }
                },
                    el('div', { className: 'dgb-post-select-tags' }, tagsContent),
                    el('span', { className: 'dgb-post-select-arrow dashicons dashicons-arrow-down-alt2' })
                ),
                open ? el(PositionedDropdown, { anchorRef: anchorRef, onClose: function() { setOpen(false); } },
                    el(TextControl, {
                        placeholder: 'Search\u2026', value: search, onChange: handleSearch,
                        onClick: function(e) { e.stopPropagation(); }
                    }),
                    dropContent
                ) : null
            );
        },
        getDefault: function(f) { return f.multiple ? [] : null; }
    };

    FIELD_TYPES['taxonomy-select'] = {
        component: function TaxonomySelectField(p) {
            var label    = p.label;
            var value    = p.value;
            var onChange = p.onChange;
            var taxonomy = p.taxonomy || 'category';
            var multiple = p.multiple !== false;

            var builtinTax = { category: 'categories', post_tag: 'tags' };
            var endpoint   = p.rest_base ? p.rest_base : (builtinTax[taxonomy] || taxonomy);

            var ss = useState('');   var search = ss[0];  var setSearch = ss[1];
            var ts = useState([]);   var terms = ts[0];   var setTerms = ts[1];
            var ls = useState(true); var loading = ls[0]; var setLoading = ls[1];
            var searchTimer = useRef(null);

            var selected = Array.isArray(value) ? value : (value ? [value] : []);

            function loadTerms(term) {
                setLoading(true);
                var params = new URLSearchParams({ per_page: 50, hide_empty: false, _fields: 'id,name,slug,count' });
                if (term) { params.set('search', term); }
                apiFetch({ path: '/wp/v2/' + endpoint + '?' + params.toString() })
                    .then(function(d) { setTerms(d); setLoading(false); })
                    .catch(function() { setLoading(false); });
            }

            useEffect(function() { loadTerms(''); }, []);

            function handleSearch(term) {
                setSearch(term);
                if (searchTimer.current) { clearTimeout(searchTimer.current); }
                searchTimer.current = setTimeout(function() { loadTerms(term); }, 350);
            }

            function isChecked(id) { return selected.some(function(t) { return t.id === id; }); }

            function toggle(term) {
                if (multiple) {
                    onChange(isChecked(term.id)
                        ? selected.filter(function(t) { return t.id !== term.id; })
                        : selected.concat([{ id: term.id, name: term.name, slug: term.slug }]));
                } else {
                    onChange(isChecked(term.id) ? [] : [{ id: term.id, name: term.name, slug: term.slug }]);
                }
            }

            var filtered = terms.filter(function(t) { return t.name.toLowerCase().indexOf(search.toLowerCase()) !== -1; });

            var listContent;
            if (loading) {
                listContent = el('div', { className: 'dgb-taxonomy-loading' }, el(Spinner));
            } else if (filtered.length === 0) {
                listContent = el('div', { className: 'dgb-taxonomy-empty' }, 'No terms found.');
            } else {
                listContent = filtered.map(function(term) {
                    return el('label', { key: term.id, className: 'dgb-taxonomy-item' + (isChecked(term.id) ? ' is-selected' : '') },
                        el('input', { type: multiple ? 'checkbox' : 'radio', checked: isChecked(term.id), onChange: function() { toggle(term); } }),
                        el('span', { className: 'dgb-taxonomy-name' }, term.name),
                        el('span', { className: 'dgb-taxonomy-count' }, '(' + term.count + ')')
                    );
                });
            }

            return el('div', { className: 'dgb-taxonomy-select' },
                el('label', { className: 'components-base-control__label' }, label),
                selected.length > 0 ? el('div', { className: 'dgb-taxonomy-selected' },
                    selected.map(function(t) {
                        return el('span', { key: t.id, className: 'dgb-taxonomy-badge' },
                            t.name,
                            el('button', { className: 'dgb-taxonomy-badge-remove', onClick: function() { onChange(selected.filter(function(s) { return s.id !== t.id; })); } }, '×')
                        );
                    })
                ) : null,
                el(TextControl, { placeholder: 'Search ' + taxonomy + '\u2026', value: search, onChange: handleSearch }),
                el('div', { className: 'dgb-taxonomy-list' }, listContent)
            );
        },
        getDefault: function() { return []; }
    };

    FIELD_TYPES['radio'] = {
        component: function RadioField(p) {
            var options = p.options || [];
            return el('div', { className: 'dgb-radio-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el('div', { className: 'dgb-radio-options' },
                    options.map(function(o, i) {
                        return el('div', { key: i, className: 'dgb-radio-option' },
                            el('label', {},
                                el('input', { type: 'radio', name: p.label, value: o.value, checked: p.value === o.value, onChange: function(e) { p.onChange(e.target.value); } }),
                                el('span', { className: 'dgb-radio-label' }, o.label)
                            )
                        );
                    })
                )
            );
        },
        getDefault: function(f) { return f.default || (f.options && f.options[0] ? f.options[0].value : ''); }
    };

    FIELD_TYPES['checkbox'] = {
        component: function CheckboxField(p) {
            var options = p.options || [];
            var sel     = Array.isArray(p.value) ? p.value : [];
            return el('div', { className: 'dgb-checkbox-field' },
                el('label', { className: 'components-base-control__label' }, p.label),
                el('div', { className: 'dgb-checkbox-options' },
                    options.map(function(o, i) {
                        return el('div', { key: i, className: 'dgb-checkbox-option' },
                            el('label', {},
                                el('input', {
                                    type: 'checkbox', value: o.value,
                                    checked: sel.indexOf(o.value) !== -1,
                                    onChange: function(e) {
                                        if (e.target.checked) { p.onChange(sel.concat([o.value])); }
                                        else                   { p.onChange(sel.filter(function(v) { return v !== o.value; })); }
                                    }
                                }),
                                el('span', { className: 'dgb-checkbox-label' }, o.label)
                            )
                        );
                    })
                )
            );
        },
        getDefault: function(f) { return f.default || []; }
    };

    FIELD_TYPES['single-checkbox'] = {
        component: function SingleCheckboxField(p) {
            return el('div', { className: 'dgb-single-checkbox' },
                el('label', {},
                    el('input', { type: 'checkbox', checked: Boolean(p.value), onChange: function(e) { p.onChange(e.target.checked); } }),
                    el('span', { className: 'dgb-checkbox-label' }, p.checkboxLabel || p.label)
                )
            );
        },
        getDefault: function(f) { return f.default || false; }
    };

    /* ==================================================================
       TAB BUTTONS
    ================================================================== */
    function TabButtons(p) {
        return el('div', { className: 'dgb-tab-buttons' },
            p.tabs.map(function(tab) {
                return el('button', {
                    key: tab.name, type: 'button',
                    className: 'dgb-tab-button' + (p.activeTab === tab.name ? ' active' : ''),
                    onClick: function() { p.onTabChange(tab.name); }
                },
                    el('span', { className: 'dgb-tab-button-content' },
                        el('span', { className: 'dashicons dashicons-' + tab.icon }),
                        ' ', tab.title
                    )
                );
            })
        );
    }

    /* ==================================================================
       FIELD BLOCK
    ================================================================== */
    var fieldParents = Object.keys(window.dgbBlockConfigs.blocks).map(function(name) { return 'dgb/' + name; });

    blocks.registerBlockType('dgb/field', {
        title: 'DGB Field',
        parent: fieldParents,
        attributes: {
            name:            { type: 'string',  default: '' },
            type:            { type: 'string'               },
            label:           { type: 'string',  default: '' },
            value:           { type: ['string','array','boolean','number','object'], default: '' },
            tab:             { type: 'string',  default: '' },
            attr_name:       { type: 'string',  default: '' },
            options:         { type: 'array',   default: [] },
            validation:      { type: 'object',  default: {} },
            repeater_fields: { type: 'array',   default: [] },
            checkboxLabel:   { type: 'string',  default: '' },
            allowed_types:   { type: 'array',   default: [] },
            post_type:       { type: 'string',  default: '' },
            taxonomy:        { type: 'string',  default: '' },
            rest_base:       { type: 'string',  default: '' },
            filters:         { type: 'array',   default: [] },
            multiple:        { type: 'boolean', default: false },
            activeTab:       { type: 'string',  default: '' }
        },

        edit: function(ep) {
            var a  = ep.attributes;
            var sa = ep.setAttributes;
            var fc = FIELD_TYPES[a.type];
            if (!fc) { return null; }

            var cls = 'dgb-field dgb-field-' + a.tab + (a.tab === a.activeTab ? ' dgb-field-active' : '');

            if (a.type === 'innerblocks') {
                return el('div', { className: cls }, el(fc.component, { label: a.label }));
            }

            var fv = a.value;
            if (a.type === 'small-number' || a.type === 'number') { fv = a.value !== '' ? Number(a.value) : ''; }
            else if (a.type === 'toggle')                         { fv = Boolean(a.value); }

            var fp = Object.assign({}, a.validation || {}, { label: a.label, value: fv, onChange: function(v) { sa({ value: v }); } });

            if (a.type === 'select' || a.type === 'radio' || a.type === 'checkbox') { fp.options = a.options; }
            if (a.type === 'single-checkbox')      { fp.checkboxLabel   = a.checkboxLabel; }
            if (a.type === 'row_repeater')          { fp.repeater_fields = a.repeater_fields; }
            if (a.type === 'file')                  { fp.allowed_types   = a.allowed_types; fp.multiple = a.multiple; }
            if (a.type === 'post-select')           { fp.post_type = a.post_type; fp.rest_base = a.rest_base; fp.multiple = a.multiple; }
            if (a.type === 'taxonomy-select')       { fp.taxonomy  = a.taxonomy;  fp.rest_base = a.rest_base; fp.multiple = a.multiple; }
            if (a.type === 'filtered-post-select')  { fp.post_type = a.post_type; fp.rest_base = a.rest_base; fp.multiple = a.multiple; fp.filters = a.filters; }

            return el('div', { className: cls }, el(fc.component, fp));
        },

        save: function() { return el(InnerBlocks.Content); }
    });

    /* ==================================================================
       BUILD TEMPLATE
    ================================================================== */
    function buildTemplate(config, activeTab) {
        var template = [];

        function pushField(field, tabName) {
            var dv = field.default;
            if (field.type === 'attributes-repeater' || field.type === 'row_repeater' || field.type === 'checkbox' || field.type === 'taxonomy-select') { dv = field.default || []; }
            else if (field.type === 'toggle' || field.type === 'single-checkbox')  { dv = Boolean(field.default); }
            else if (field.type === 'small-number' || field.type === 'number')     { dv = field.default !== undefined ? Number(field.default) : 0; }
            else if (field.type === 'image' || field.type === 'file')              { dv = field.multiple ? [] : null; }
            else if (field.type === 'post-select')                                 { dv = field.multiple ? [] : null; }
            else if (field.type === 'filtered-post-select')                        { dv = { post: null, posts: [], filters: {} }; }

            var entry = ['dgb/field', {
                name: field.name, type: field.type, label: field.label, attr_name: field.attr_name,
                tab: tabName, activeTab: activeTab,
                options: field.options || [], validation: field.validation || {},
                repeater_fields: field.repeater_fields || [], checkboxLabel: field.checkboxLabel || '',
                allowed_types: field.allowed_types || [], post_type: field.post_type || '',
                taxonomy: field.taxonomy || '', rest_base: field.rest_base || '',
                filters: field.filters || [], multiple: field.multiple || false,
                value: dv
            }];
            template.push(entry);
        }

        if (config.tabs && config.tabs.length > 0) {
            config.tabs.forEach(function(tab) { (tab.fields || []).forEach(function(f) { pushField(f, tab.name); }); });
        }
        if (config.fields && config.fields.length > 0) {
            config.fields.forEach(function(f) { pushField(f, ''); });
        }
        return template;
    }

    /* ==================================================================
       REGISTER DYNAMIC BLOCKS
    ================================================================== */
    if (window.dgbBlockConfigs && window.dgbBlockConfigs.blocks) {
        Object.keys(window.dgbBlockConfigs.blocks).forEach(function(name) {
            var config = window.dgbBlockConfigs.blocks[name];

            blocks.registerBlockType('dgb/' + name, {
                title:       config.title,
                description: config.description || '',
                icon:        config.icon        || 'admin-generic',
                category:    config.category    || 'widgets',
                keywords:    config.keywords    || [],

                edit: function(props) {
                    var blockProps = useBlockProps();

                    var tabS     = useState(config.tabs && config.tabs.length > 0 ? config.tabs[0].name : '');
                    var activeTab   = tabS[0];
                    var setActiveTab = tabS[1];

                    function setFieldsActiveTab(tab) {
                        setActiveTab(tab);
                        var inner = data.select('core/block-editor').getBlocks(props.clientId);
                        inner.forEach(function(block) {
                            if (block.name === 'dgb/field') {
                                data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, { activeTab: tab });
                            }
                        });
                    }

                    var template = useMemo(function() { return buildTemplate(config, activeTab); }, []);

                    var content = [];
                    if (config.tabs && config.tabs.length > 0) {
                        content.push(el(TabButtons, { key: 'tabs', tabs: config.tabs, activeTab: activeTab, onTabChange: setFieldsActiveTab }));
                    }
                    content.push(
                        el('div', { key: 'fields', className: 'dgb-tab-content' },
                            el(InnerBlocks, { template: template, templateLock: 'all', allowedBlocks: ['dgb/field'], renderAppender: false })
                        )
                    );

                    return el('div', Object.assign({}, blockProps, { 'data-active-tab': activeTab }), content);
                },

                save: function() { return el(InnerBlocks.Content); }
            });
        });
    }

}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.data,
    window.wp.apiFetch
));