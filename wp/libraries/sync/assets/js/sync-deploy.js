/**
 * AWX Sync: "Deploy Code" screen (source sites).
 *
 * Select (tree) → Plan (diff table with ticks) → Deploy (one request per step)
 * → Summary. Drives the awesome/sync-code-* abilities. No build step: plain
 * wp.element.createElement.
 */
(function (wp) {
	if (!wp || !wp.element || !window.awxSyncDeploy) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useMemo = wp.element.useMemo;
	var Fragment = wp.element.Fragment;
	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var cfg = window.awxSyncDeploy;

	function ability(name, input) {
		return apiFetch({ path: cfg.abilities + name + '/execute', method: 'POST', data: input || {} }).then(function (res) {
			if (!res || res.success === false) {
				var e = (res && res.error) || {};
				var err = new Error(e.message || __('Request failed.', 'awesome-xp'));
				err.code = e.code || 'error';
				err.nextStep = e.next_step || '';
				err.details = e.details || {};
				throw err;
			}
			return res.data;
		});
	}

	var KIND_LABEL = {
		app: __('App definition', 'awesome-xp'), core: __('Core', 'awesome-xp'), service: __('Service', 'awesome-xp'), config: __('App config', 'awesome-xp'),
		module: __('App module', 'awesome-xp'), dgb: __('Block definition', 'awesome-xp'), acf: __('ACF field group', 'awesome-xp'), pattern: __('Pattern', 'awesome-xp'),
		template: __('Template / part', 'awesome-xp'), page: __('App page / post', 'awesome-xp'), styles: __('Global styles', 'awesome-xp'), option: __('Token overrides', 'awesome-xp'),
		menu: __('Navigation menu', 'awesome-xp')
	};
	var STATUS_LABEL = {
		new: __('New', 'awesome-xp'), changed: __('Changed', 'awesome-xp'), unchanged: __('Unchanged', 'awesome-xp'), prod_edited: __('Edited on target', 'awesome-xp'),
		only_on_prod: __('Only on target', 'awesome-xp'), blocked: __('Blocked', 'awesome-xp')
	};

	/* ------------------------------------------------------------------ */
	/* Selection helpers                                                    */
	/* ------------------------------------------------------------------ */

	// UI selection: { core: {slug:true}, services: {pt: {slug:true}}, apps: {slug: {definition, config:{slug:true}, modules:{...}, pages:bool, posts:bool}}, dgb:{}, acf:{}, patterns:{}, templates:{}, styles:bool, tokens:bool, menus:{} }
	function emptySel() { return { core: {}, services: {}, apps: {}, dgb: {}, acf: {}, patterns: {}, templates: {}, styles: false, tokens: false, menus: {} }; }

	function toApiSelection(sel, tree) {
		var out = {};
		function list(map, all) {
			var keys = Object.keys(map || {}).filter(function (k) { return map[k]; });
			if (!keys.length) { return null; }
			return (all && keys.length >= all.length) ? '*' : keys;
		}
		var core = list(sel.core, tree.core.modules.map(function (m) { return m.slug; }));
		if (core) { out.core = core; }
		(tree.services || []).forEach(function (s) {
			var l = list(sel.services[s.post_type], s.modules.map(function (m) { return m.slug; }));
			if (l) { out.services = out.services || {}; out.services[s.post_type] = l; }
		});
		(tree.apps || []).forEach(function (a) {
			var p = sel.apps[a.slug];
			if (!p) { return; }
			var parts = { definition: !!p.definition };
			['config', 'modules'].forEach(function (c) {
				if (a.collections[c]) {
					var l = list(p[c], a.collections[c].modules.map(function (m) { return m.slug; }));
					if (l) { parts[c] = l; }
				}
			});
			['pages', 'posts'].forEach(function (c) { if (p[c] && a.collections[c]) { parts[c] = '*'; } });
			if (parts.definition || parts.config || parts.modules || parts.pages || parts.posts) {
				out.apps = out.apps || {};
				out.apps[a.slug] = parts;
			}
		});
		var dgb = list(sel.dgb, tree.blocks.dgb.modules.map(function (m) { return m.slug; }));
		if (dgb) { out.dgb = dgb; }
		var acf = list(sel.acf, (tree.blocks.acf.groups || []).map(function (g) { return g.slug; }));
		if (acf) { out.acf = acf; }
		var pat = list(sel.patterns, (tree.patterns || []).map(function (p) { return p.slug; }));
		if (pat) { out.patterns = pat; }
		var tmpl = list(sel.templates, (tree.templates || []).map(function (t) { return t.slug; }));
		if (tmpl) { out.templates = tmpl; }
		var menus = list(sel.menus, (tree.menus || []).map(function (m) { return m.slug; }));
		if (menus) { out.menus = menus; }
		if (sel.styles) { out.styles = true; }
		if (sel.tokens) { out.tokens = true; }
		return out;
	}

	function fromApiSelection(api, tree) {
		var sel = emptySel();
		function fill(target, v, all) {
			(v === '*' ? all : (v || [])).forEach(function (k) { target[k] = true; });
		}
		if (api.core) { fill(sel.core, api.core, tree.core.modules.map(function (m) { return m.slug; })); }
		Object.keys(api.services || {}).forEach(function (pt) {
			var s = (tree.services || []).filter(function (x) { return x.post_type === pt; })[0];
			if (!s) { return; }
			sel.services[pt] = {};
			fill(sel.services[pt], api.services[pt], s.modules.map(function (m) { return m.slug; }));
		});
		Object.keys(api.apps || {}).forEach(function (slug) {
			var a = (tree.apps || []).filter(function (x) { return x.slug === slug; })[0];
			if (!a) { return; }
			var p = api.apps[slug] || {};
			var u = { definition: !!p.definition, config: {}, modules: {}, pages: !!p.pages, posts: !!p.posts };
			['config', 'modules'].forEach(function (c) {
				if (p[c] && a.collections[c]) { fill(u[c], p[c], a.collections[c].modules.map(function (m) { return m.slug; })); }
			});
			sel.apps[slug] = u;
		});
		if (api.dgb) { fill(sel.dgb, api.dgb, tree.blocks.dgb.modules.map(function (m) { return m.slug; })); }
		if (api.acf) { fill(sel.acf, api.acf, (tree.blocks.acf.groups || []).map(function (g) { return g.slug; })); }
		if (api.patterns) { fill(sel.patterns, api.patterns, (tree.patterns || []).map(function (p) { return p.slug; })); }
		if (api.templates) { fill(sel.templates, api.templates, (tree.templates || []).map(function (t) { return t.slug; })); }
		if (api.menus) { fill(sel.menus, api.menus, (tree.menus || []).map(function (m) { return m.slug; })); }
		if (api.styles) { sel.styles = true; }
		if (api.tokens || api.options) { sel.tokens = true; }
		return sel;
	}

	function countSel(sel) {
		var n = 0;
		function c(map) { Object.keys(map || {}).forEach(function (k) { if (map[k]) { n++; } }); }
		c(sel.core); c(sel.dgb); c(sel.acf); c(sel.patterns); c(sel.templates); c(sel.menus);
		if (sel.styles) { n++; }
		if (sel.tokens) { n++; }
		Object.keys(sel.services).forEach(function (pt) { c(sel.services[pt]); });
		Object.keys(sel.apps).forEach(function (s) { var p = sel.apps[s]; if (p.definition) { n++; } c(p.config); c(p.modules); if (p.pages) { n++; } if (p.posts) { n++; } });
		return n;
	}

	/* ------------------------------------------------------------------ */
	/* Select panel                                                         */
	/* ------------------------------------------------------------------ */

	function Group(props) {
		// props: title, items [{id,label,meta}], checked {id:true}, onChange(map), filter
		var items = props.items || [];
		var checked = props.checked || {};
		var shown = items.filter(function (i) { return !props.filter || (i.label + ' ' + i.id).toLowerCase().indexOf(props.filter) !== -1; });
		var nChecked = items.filter(function (i) { return checked[i.id]; }).length;
		var open = useState(false);
		var isOpen = open[0], setOpen = open[1];
		useEffect(function () { if (props.filter) { setOpen(true); } }, [props.filter]);
		function setAll(v) {
			var m = {};
			items.forEach(function (i) { m[i.id] = v; });
			props.onChange(m);
		}
		return el('div', { className: 'awx-group' },
			el('div', { className: 'awx-group-head' },
				el(C.CheckboxControl, {
					__nextHasNoMarginBottom: true,
					label: props.title + ' (' + nChecked + '/' + items.length + ')',
					checked: items.length > 0 && nChecked === items.length,
					indeterminate: nChecked > 0 && nChecked < items.length,
					disabled: !items.length,
					onChange: function (v) { setAll(v); }
				}),
				items.length ? el(C.Button, { variant: 'link', size: 'small', onClick: function () { setOpen(!isOpen); } }, isOpen ? __('hide', 'awesome-xp') : __('show modules', 'awesome-xp')) : el('span', { className: 'awx-muted' }, __('empty', 'awesome-xp')),
				props.extra || null
			),
			isOpen ? el('ul', { className: 'awx-group-items' }, shown.map(function (i) {
				return el('li', { key: i.id }, el(C.CheckboxControl, {
					__nextHasNoMarginBottom: true,
					label: el('span', null, i.label, i.meta ? el('span', { className: 'awx-muted' }, ' · ' + i.meta) : null),
					checked: !!checked[i.id],
					onChange: function (v) { var m = Object.assign({}, checked); m[i.id] = v; props.onChange(m); }
				}));
			}), shown.length === 0 ? el('li', { className: 'awx-muted' }, __('no match', 'awesome-xp')) : null) : null
		);
	}

	function modItems(mods) {
		return (mods || []).map(function (m) {
			var meta = m.last_sync ? __('deployed', 'awesome-xp') + ' ' + new Date(m.last_sync.time * 1000).toLocaleDateString() : __('never deployed', 'awesome-xp');
			if (m.version) { meta = 'v' + m.version + ' · ' + meta; }
			return { id: m.slug, label: m.title || m.slug, meta: meta };
		});
	}

	function SelectPanel(props) {
		var tree = props.tree;
		var sel = props.sel;
		var setSel = props.setSel;
		var f = useState('');
		var filter = f[0], setFilter = f[1];
		var fl = filter.toLowerCase();
		var setName = useState('');
		function upd(fn) { var n = JSON.parse(JSON.stringify(sel)); fn(n); setSel(n); }

		return el('div', { className: 'awx-panel' },
			el('div', { className: 'awx-toolbar' },
				el(C.TextControl, { __nextHasNoMarginBottom: true, placeholder: __('Filter modules…', 'awesome-xp'), value: filter, onChange: setFilter }),
				props.sets.length ? el(C.SelectControl, {
					__nextHasNoMarginBottom: true, label: __('Load set', 'awesome-xp'), hideLabelFromVision: true, value: '',
					options: [{ label: __('Load a saved set…', 'awesome-xp'), value: '' }].concat(props.sets.map(function (s) { return { label: s.name, value: s.name }; })),
					onChange: function (name) { var s = props.sets.filter(function (x) { return x.name === name; })[0]; if (s) { setSel(fromApiSelection(s.selection, tree)); props.setLabel(name); } }
				}) : null,
				el(C.Button, { variant: 'tertiary', onClick: function () { setSel(emptySel()); } }, __('Clear', 'awesome-xp'))
			),
			el('h2', null, __('Code', 'awesome-xp')),
			el(Group, { title: __('Core', 'awesome-xp'), items: modItems(tree.core.modules), checked: sel.core, filter: fl, onChange: function (m) { upd(function (n) { n.core = m; }); } }),
			el('h2', null, __('Services', 'awesome-xp')),
			tree.services.length ? tree.services.map(function (s) {
				return el(Group, { key: s.post_type, title: s.label + ' (' + s.post_type + ')', items: modItems(s.modules), checked: sel.services[s.post_type] || {}, filter: fl, onChange: function (m) { upd(function (n) { n.services[s.post_type] = m; }); } });
			}) : el('p', { className: 'awx-muted' }, __('No service post types registered.', 'awesome-xp')),
			el('h2', null, __('Apps', 'awesome-xp')),
			tree.apps.length ? tree.apps.map(function (a) {
				var p = sel.apps[a.slug] || { definition: false, config: {}, modules: {}, pages: false, posts: false };
				function setP(fn) { upd(function (n) { var q = n.apps[a.slug] || { definition: false, config: {}, modules: {}, pages: false, posts: false }; fn(q); n.apps[a.slug] = q; }); }
				var anyMod = Object.keys(p.config).some(function (k) { return p.config[k]; }) || Object.keys(p.modules).some(function (k) { return p.modules[k]; });
				return el('div', { className: 'awx-app', key: a.slug },
					el('div', { className: 'awx-app-head' },
						el('strong', null, a.name), el('code', null, a.slug),
						el(C.Button, { variant: 'link', size: 'small', onClick: function () {
							setP(function (q) { q.definition = true; (a.collections.config ? a.collections.config.modules : []).forEach(function (m) { q.config[m.slug] = true; }); (a.collections.modules ? a.collections.modules.modules : []).forEach(function (m) { q.modules[m.slug] = true; }); });
						} }, __('select all code', 'awesome-xp')),
						el(C.Button, { variant: 'link', size: 'small', onClick: function () { upd(function (n) { delete n.apps[a.slug]; }); } }, __('none', 'awesome-xp'))
					),
					el(C.CheckboxControl, { __nextHasNoMarginBottom: true, label: __('App definition', 'awesome-xp') + (a.definition && a.definition.last_sync ? '' : ' · ' + __('never deployed', 'awesome-xp')), checked: !!p.definition, onChange: function (v) { setP(function (q) { q.definition = v; }); } }),
					!p.definition && anyMod ? el('p', { className: 'awx-hint' }, __('Tip: include the app definition when the app does not exist on the target yet; its module post types come from it.', 'awesome-xp')) : null,
					a.collections.config ? el(Group, { title: __('Config', 'awesome-xp') + ' (' + a.collections.config.post_type + ')', items: modItems(a.collections.config.modules), checked: p.config, filter: fl, onChange: function (m) { setP(function (q) { q.config = m; }); } }) : null,
					a.collections.modules ? el(Group, { title: __('Modules', 'awesome-xp') + ' (' + a.collections.modules.post_type + ')', items: modItems(a.collections.modules.modules), checked: p.modules, filter: fl, onChange: function (m) { setP(function (q) { q.modules = m; }); } }) : null,
					el('div', { className: 'awx-content-toggles' },
						a.collections.pages ? el(C.CheckboxControl, { __nextHasNoMarginBottom: true, label: __('Pages', 'awesome-xp') + ' (' + a.collections.pages.count + ') — ' + __('content; you pick the pages in the plan', 'awesome-xp'), checked: !!p.pages, onChange: function (v) { setP(function (q) { q.pages = v; }); } }) : null,
						a.collections.posts ? el(C.CheckboxControl, { __nextHasNoMarginBottom: true, label: __('Posts', 'awesome-xp') + ' (' + a.collections.posts.count + ') — ' + __('content; you pick the posts in the plan', 'awesome-xp'), checked: !!p.posts, onChange: function (v) { setP(function (q) { q.posts = v; }); } }) : null
					)
				);
			}) : el('p', { className: 'awx-muted' }, __('No apps registered.', 'awesome-xp')),
			el('h2', null, __('Blocks & patterns', 'awesome-xp')),
			el(Group, { title: __('Gutenberg block definitions', 'awesome-xp'), items: modItems(tree.blocks.dgb.modules), checked: sel.dgb, filter: fl, onChange: function (m) { upd(function (n) { n.dgb = m; }); } }),
			el(Group, {
				title: __('ACF blocks', 'awesome-xp') + (tree.blocks.acf.active ? '' : ' — ' + __('ACF not active here', 'awesome-xp')),
				items: (tree.blocks.acf.groups || []).map(function (g) { return { id: g.slug, label: g.title, meta: g.slug }; }),
				checked: sel.acf, filter: fl, onChange: function (m) { upd(function (n) { n.acf = m; }); },
				extra: tree.blocks.acf.module ? el('span', { className: 'awx-muted' }, __('includes core/gutenberg-blocks', 'awesome-xp')) : null
			}),
			el(Group, {
				title: __('Block patterns', 'awesome-xp'),
				items: (tree.patterns || []).map(function (p) {
					var meta = p.managed ? __('managed', 'awesome-xp') : (p.sync_status === 'unsynced' ? __('pattern', 'awesome-xp') : __('synced', 'awesome-xp'));
					if (!p.uuid) { meta += ' • ' + __('no sync UUID yet', 'awesome-xp'); }
					return { id: p.slug, label: p.title || p.slug, meta: meta };
				}),
				checked: sel.patterns,
				filter: fl,
				onChange: function (m) { upd(function (n) { n.patterns = m; }); }
			}),
			(tree.templates && tree.templates.length) ? el(Group, {
				title: __('Site templates & parts', 'awesome-xp'),
				items: tree.templates.map(function (t) {
					var meta = t.post_type === 'wp_template_part' ? ('part' + (t.area ? ': ' + t.area : '')) : 'template';
					if (t.theme) { meta += ' • ' + t.theme; }
					if (!t.uuid) { meta += ' • ' + __('no sync UUID yet', 'awesome-xp'); }
					return { id: t.slug, label: t.title || t.slug, meta: meta };
				}),
				checked: sel.templates,
				filter: fl,
				onChange: function (m) { upd(function (n) { n.templates = m; }); }
			}) : null,
			(tree.menus && tree.menus.length) ? el(Group, {
				title: __('Navigation menus', 'awesome-xp'),
				items: tree.menus.map(function (m) {
					var meta = (m.items_count || 0) + ' ' + __('items', 'awesome-xp');
					if (m.locations && m.locations.length) { meta += ' • ' + m.locations.join(', '); }
					if (m.last_sync) { meta += ' · ' + __('deployed', 'awesome-xp') + ' ' + new Date(m.last_sync.time * 1000).toLocaleDateString(); }
					return { id: m.slug, label: m.title || m.slug, meta: meta };
				}),
				checked: sel.menus,
				filter: fl,
				onChange: function (m) { upd(function (n) { n.menus = m; }); }
			}) : null,
			(tree.styles || tree.tokens) ? el(Fragment, null,
				el('h2', null, __('Styles & design tokens', 'awesome-xp')),
				tree.styles ? el('div', { className: 'awx-group' },
					el('div', { className: 'awx-group-head' },
						el(C.CheckboxControl, {
							__nextHasNoMarginBottom: true,
							label: el('span', null, __('Global styles', 'awesome-xp') + ' (' + tree.styles.theme + ')', tree.styles.last_sync ? el('span', { className: 'awx-muted' }, ' · ' + __('deployed', 'awesome-xp') + ' ' + new Date(tree.styles.last_sync.time * 1000).toLocaleDateString()) : el('span', { className: 'awx-muted' }, ' · ' + __('never deployed', 'awesome-xp'))),
							checked: !!sel.styles,
							onChange: function (v) { upd(function (n) { n.styles = v; }); }
						})
					)
				) : null,
				tree.tokens ? el('div', { className: 'awx-group' },
					el('div', { className: 'awx-group-head' },
						el(C.CheckboxControl, {
							__nextHasNoMarginBottom: true,
							label: el('span', null, __('Token overrides (awx_token_overrides)', 'awesome-xp'), tree.tokens.last_sync ? el('span', { className: 'awx-muted' }, ' · ' + __('deployed', 'awesome-xp') + ' ' + new Date(tree.tokens.last_sync.time * 1000).toLocaleDateString()) : el('span', { className: 'awx-muted' }, ' · ' + __('never deployed', 'awesome-xp'))),
							checked: !!sel.tokens,
							onChange: function (v) { upd(function (n) { n.tokens = v; }); }
						})
					)
				) : null
			) : null,
			el('div', { className: 'awx-actions' },
				el(C.Button, { variant: 'primary', disabled: !props.configured || countSel(sel) === 0 || props.busy, isBusy: props.busy, onClick: props.onPlan }, __('Plan deployment', 'awesome-xp') + (countSel(sel) ? ' (' + countSel(sel) + ')' : '')),
				el('span', { className: 'awx-save-set' },
					el(C.TextControl, { __nextHasNoMarginBottom: true, placeholder: __('Set name', 'awesome-xp'), value: setName[0], onChange: setName[1] }),
					el(C.Button, { variant: 'secondary', disabled: !setName[0] || countSel(sel) === 0, onClick: function () { props.onSaveSet(setName[0]); setName[1](''); } }, __('Save as set', 'awesome-xp'))
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Plan panel                                                           */
	/* ------------------------------------------------------------------ */

	function Badge(props) {
		return el('span', { className: 'awx-badge awx-badge-' + props.status }, STATUS_LABEL[props.status] || props.status);
	}

	function PlanPanel(props) {
		var view = props.view;
		var plan = view.plan || { rows: [], blockers: [], warnings: [], summary: {} };
		var ticked = props.ticked;
		var setTicked = props.setTicked;
		var groups = useMemo(function () {
			var order = ['app', 'core', 'service', 'config', 'module', 'dgb', 'acf', 'styles', 'option', 'menu', 'pattern', 'template', 'page'];
			var g = {};
			plan.rows.forEach(function (r) { (g[r.kind] = g[r.kind] || []).push(r); });
			return order.filter(function (k) { return g[k]; }).map(function (k) { return { kind: k, rows: g[k] }; });
		}, [plan.rows]);
		var nTicked = Object.keys(ticked).filter(function (k) { return ticked[k]; }).length;
		var editedTicked = plan.rows.filter(function (r) { return ticked[r.key] && r.status === 'prod_edited'; }).length;
		var by = plan.summary.by_status || {};
		var canDeploy = !plan.blockers.length && nTicked > 0 && !props.busy;

		return el('div', { className: 'awx-panel' },
			el('div', { className: 'awx-summary' },
				['new', 'changed', 'prod_edited', 'unchanged', 'only_on_prod', 'blocked'].map(function (s) {
					return el('span', { key: s, className: 'awx-stat' }, el(Badge, { status: s }), ' ', by[s] || 0);
				}),
				el('span', { className: 'awx-muted' }, __('Target:', 'awesome-xp') + ' ' + (plan.target_home || view.target_url))
			),
			plan.blockers.map(function (b, i) { return el(C.Notice, { key: 'b' + i, status: 'error', isDismissible: false }, el('code', null, b.code), ' ', b.message); }),
			plan.warnings.length ? el('details', { open: plan.warnings.length <= 3 }, el('summary', null, plan.warnings.length + ' ' + __('note(s)', 'awesome-xp')), el('ul', { className: 'awx-warnings' }, plan.warnings.map(function (w, i) { return el('li', { key: i }, el('code', null, w.code), ' ', w.message); }))) : null,
			plan.summary.nothing_to_do ? el(C.Notice, { status: 'success', isDismissible: false }, __('Nothing to deploy: the target already matches the selection.', 'awesome-xp')) : null,
			groups.map(function (g) {
				var selectable = g.rows.filter(function (r) { return r.selectable && r.status !== 'unchanged'; });
				var allOn = selectable.length > 0 && selectable.every(function (r) { return ticked[r.key]; });
				return el('div', { className: 'awx-plan-group', key: g.kind },
					el('div', { className: 'awx-plan-group-head' },
						el(C.CheckboxControl, { __nextHasNoMarginBottom: true, label: (KIND_LABEL[g.kind] || g.kind) + ' (' + g.rows.length + ')', checked: allOn, disabled: !selectable.length, onChange: function (v) { var t = Object.assign({}, ticked); selectable.forEach(function (r) { t[r.key] = v; }); setTicked(t); } })
					),
					el('table', { className: 'widefat striped awx-plan-table' },
						el('thead', null, el('tr', null, el('th', { className: 'awx-col-tick' }), el('th', null, __('Item', 'awesome-xp')), el('th', null, __('Status', 'awesome-xp')), el('th', null, __('Notes', 'awesome-xp')))),
						el('tbody', null, g.rows.map(function (r) {
							var dis = !r.selectable || r.status === 'unchanged';
							return el('tr', { key: r.key, className: dis ? 'awx-row-dim' : '' },
								el('td', null, el(C.CheckboxControl, { __nextHasNoMarginBottom: true, checked: !!ticked[r.key], disabled: dis, onChange: function (v) { var t = Object.assign({}, ticked); t[r.key] = v; setTicked(t); } })),
								el('td', null, el('strong', null, r.title || r.slug), el('br'), el('code', null, r.key), r.app ? el('span', { className: 'awx-muted' }, ' · ' + r.app + '/' + r.collection) : null,
									r.kind === 'page' && r.from_status !== undefined ? el('span', { className: 'awx-muted' }, ' · ' + (r.from_status || __('new', 'awesome-xp')) + ' → ' + r.to_status) : null,
									r.counts && (r.counts.media || r.counts.blocks) ? el('span', { className: 'awx-muted' }, ' · ' + r.counts.media + ' ' + __('media', 'awesome-xp') + ', ' + r.counts.blocks + ' ' + __('blocks', 'awesome-xp')) : null),
								el('td', null, el(Badge, { status: r.status }), r.never_synced ? el('span', { className: 'awx-muted' }, ' ' + __('never deployed from here', 'awesome-xp')) : null),
								el('td', null, (r.warnings || []).length ? el('ul', { className: 'awx-row-warnings' }, r.warnings.map(function (w, i) { return el('li', { key: i, className: w.code === 'edited_on_prod' || w.code === 'no_revision' ? 'awx-warn-strong' : '' }, w.message); })) : null)
							);
						}))
					)
				);
			}),
			el('div', { className: 'awx-actions' },
				el(C.Button, { variant: 'secondary', onClick: props.onBack, disabled: props.busy }, __('Back to selection', 'awesome-xp')),
				el(C.Button, { variant: 'secondary', onClick: props.onReplan, disabled: props.busy }, __('Re-plan', 'awesome-xp')),
				el(C.Button, { variant: 'primary', disabled: !canDeploy, isBusy: props.busy, onClick: function () { props.onDeploy(editedTicked > 0); } }, __('Deploy', 'awesome-xp') + ' (' + nTicked + ')'),
				editedTicked ? el('span', { className: 'awx-warn-strong' }, editedTicked + ' ' + __('ticked item(s) were edited on the target and will be overwritten; you will be asked to confirm.', 'awesome-xp')) : null
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Progress / summary                                                   */
	/* ------------------------------------------------------------------ */

	function StepList(props) {
		var steps = props.steps || [];
		return el('ol', { className: 'awx-steps' }, steps.map(function (s) {
			var label = s.type === 'finalize' ? __('Flush caches and recompile blocks on the target', 'awesome-xp') : (s.type + ' ' + (s.object_uuid || '') + (s.input && s.input.title ? ' — ' + s.input.title : ''));
			var res = s.result || {};
			return el('li', { key: s.step_id, className: 'awx-step awx-step-' + s.status },
				el('span', { className: 'awx-step-status' }, s.status === 'done' ? '✓' : (s.status === 'failed' ? '✗' : (s.status === 'pending' ? '·' : '…'))), ' ', label,
				res.action ? el('span', { className: 'awx-muted' }, ' · ' + res.action + (res.revision_id ? ' (rev ' + res.revision_id + ')' : '')) : null,
				s.error ? el('div', { className: 'awx-step-error' }, s.error) : null
			);
		}));
	}

	function ProgressPanel(props) {
		var view = props.view;
		var p = view.progress || { done: 0, total: 0 };
		return el('div', { className: 'awx-panel' },
			el('h2', null, view.status === 'done' ? __('Deployed', 'awesome-xp') : (view.status === 'failed' ? __('Deployment stopped', 'awesome-xp') : __('Deploying…', 'awesome-xp'))),
			el('progress', { className: 'awx-progress', max: p.total || 1, value: p.done }), ' ', p.done + ' / ' + p.total,
			props.error ? el(C.Notice, { status: 'error', isDismissible: false }, el('code', null, props.error.code), ' ', props.error.message, props.error.nextStep ? el('div', null, props.error.nextStep) : null) : null,
			view.status === 'done' ? el(C.Notice, { status: 'success', isDismissible: false }, __('All steps completed on', 'awesome-xp') + ' ' + view.target_url + '. ' + __('Caches were flushed and block assets recompiled.', 'awesome-xp')) : null,
			el(StepList, { steps: view.steps }),
			el('div', { className: 'awx-actions' },
				view.status === 'failed' || view.status === 'blocked' ? el(C.Button, { variant: 'primary', onClick: props.onResume, disabled: props.busy }, __('Re-plan and resume', 'awesome-xp')) : null,
				view.status === 'running' && !props.busy ? el(C.Button, { variant: 'primary', onClick: props.onContinue }, __('Continue', 'awesome-xp')) : null,
				view.status === 'running' || view.status === 'failed' ? el(C.Button, { variant: 'secondary', isDestructive: true, onClick: props.onCancel, disabled: props.busy }, __('Cancel run', 'awesome-xp')) : null,
				view.status === 'done' || view.status === 'cancelled' ? el(C.Button, { variant: 'secondary', onClick: props.onNew }, __('New deployment', 'awesome-xp')) : null
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* App                                                                  */
	/* ------------------------------------------------------------------ */

	function App() {
		var st = useState({ phase: 'loading', catalog: null, sel: emptySel(), label: '', view: null, ticked: {}, busy: false, error: null, notice: null });
		var s = st[0], setS = st[1];
		var alive = useRef(true);
		useEffect(function () { return function () { alive.current = false; }; }, []);
		function patch(p) { if (alive.current) { setS(function (prev) { return Object.assign({}, prev, p); }); } }

		function loadCatalog(keepPhase) {
			return ability('awesome/sync-code-catalog', {}).then(function (data) {
				patch({ catalog: data, phase: keepPhase || 'select', error: null });
				return data;
			}).catch(function (e) { patch({ phase: 'select', error: e }); });
		}
		useEffect(function () {
			loadCatalog().then(function (data) {
				if (data && data.open_run && (data.open_run.status === 'running' || data.open_run.status === 'failed')) {
					ability('awesome/sync-code-status', { run_id: data.open_run.run_id }).then(function (v) { patch({ view: v, phase: 'progress', notice: __('An unfinished deployment was found; resume or cancel it.', 'awesome-xp') }); }).catch(function () {});
				}
			});
		}, []);

		function fail(e) { patch({ busy: false, error: e }); }

		function plan() {
			var tree = s.catalog.tree;
			patch({ busy: true, error: null });
			ability('awesome/sync-code-analyze', { selection: toApiSelection(s.sel, tree), label: s.label }).then(function (v) {
				var t = {};
				(v.plan && v.plan.selected_keys || []).forEach(function (k) { t[k] = true; });
				patch({ view: v, ticked: t, phase: 'plan', busy: false });
			}).catch(fail);
		}
		function replan() {
			patch({ busy: true, error: null });
			ability('awesome/sync-code-plan', { run_id: s.view.run_id }).then(function (v) {
				var t = {};
				(v.plan && v.plan.rows || []).forEach(function (r) { if (r.selectable && r.status !== 'unchanged' && s.ticked[r.key]) { t[r.key] = true; } });
				if (!Object.keys(t).length) { (v.plan && v.plan.selected_keys || []).forEach(function (k) { t[k] = true; }); }
				patch({ view: v, ticked: t, phase: 'plan', busy: false });
			}).catch(fail);
		}
		function runSteps(runId) {
			ability('awesome/sync-code-step', { run_id: runId }).then(function (v) {
				if (v.error) {
					patch({ view: v, busy: v.error.retryable, error: v.error.retryable ? null : v.error });
					if (v.error.retryable) { setTimeout(function () { runSteps(runId); }, 1500); }
					return;
				}
				patch({ view: v, error: null });
				if (v.status === 'running') {
					runSteps(runId);
				} else {
					patch({ busy: false });
					loadCatalog('progress');
				}
			}).catch(fail);
		}
		function deploy(needsOverwrite) {
			if (needsOverwrite && !window.confirm(__('Some ticked items were edited on the target since the last deploy. Overwrite them? A revision is kept for posts; ACF field groups cannot be restored.', 'awesome-xp'))) {
				return;
			}
			var keys = Object.keys(s.ticked).filter(function (k) { return s.ticked[k]; });
			patch({ busy: true, error: null, notice: null });
			ability('awesome/sync-code-confirm', { run_id: s.view.run_id, selected_keys: keys, overwrite: !!needsOverwrite }).then(function (v) {
				patch({ view: v, phase: 'progress' });
				runSteps(v.run_id);
			}).catch(fail);
		}
		function resume() {
			patch({ busy: true, error: null });
			ability('awesome/sync-code-resume', { run_id: s.view.run_id }).then(function (v) {
				var t = {};
				(v.plan && v.plan.selected_keys || []).forEach(function (k) { t[k] = true; });
				patch({ view: v, ticked: t, phase: 'plan', busy: false, notice: __('Items already written show as unchanged. Confirm again to continue.', 'awesome-xp') });
			}).catch(fail);
		}
		function cancel() {
			if (!window.confirm(__('Cancel this run? Items already written on the target stay in place.', 'awesome-xp'))) { return; }
			patch({ busy: true });
			ability('awesome/sync-code-cancel', { run_id: s.view.run_id }).then(function () { patch({ busy: false, view: null, phase: 'select', notice: null }); loadCatalog(); }).catch(fail);
		}
		function saveSet(name) {
			ability('awesome/sync-set-save', { name: name, selection: toApiSelection(s.sel, s.catalog.tree) }).then(function () { patch({ notice: __('Set saved.', 'awesome-xp') }); loadCatalog(s.phase); }).catch(fail);
		}

		if (s.phase === 'loading' || !s.catalog) {
			return el('div', null, el(C.Spinner), ' ', __('Loading catalog…', 'awesome-xp'), s.error ? el(C.Notice, { status: 'error', isDismissible: false }, s.error.message) : null);
		}
		var head = el(Fragment, null,
			!s.catalog.configured ? el(C.Notice, { status: 'warning', isDismissible: false }, __('No target configured. Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN (with the code scope) to wp-config.php.', 'awesome-xp')) : el('p', { className: 'awx-muted' }, __('Target:', 'awesome-xp') + ' ' + s.catalog.target_url),
			s.notice ? el(C.Notice, { status: 'info', onRemove: function () { patch({ notice: null }); } }, s.notice) : null,
			s.error && s.phase !== 'progress' ? el(C.Notice, { status: 'error', onRemove: function () { patch({ error: null }); } }, el('code', null, s.error.code), ' ', s.error.message, s.error.nextStep ? el('div', null, s.error.nextStep) : null) : null,
			el('div', { className: 'awx-phases' }, ['select', 'plan', 'progress'].map(function (p, i) {
				return el('span', { key: p, className: 'awx-phase' + (s.phase === p ? ' is-active' : '') }, (i + 1) + '. ' + ({ select: __('Select', 'awesome-xp'), plan: __('Plan', 'awesome-xp'), progress: __('Deploy', 'awesome-xp') })[p]);
			}))
		);
		var body;
		if (s.phase === 'select') {
			body = el(SelectPanel, { tree: s.catalog.tree, sets: s.catalog.sets || [], sel: s.sel, setSel: function (v) { patch({ sel: v }); }, setLabel: function (l) { patch({ label: l }); }, configured: s.catalog.configured, busy: s.busy, onPlan: plan, onSaveSet: saveSet });
		} else if (s.phase === 'plan') {
			body = el(PlanPanel, { view: s.view, ticked: s.ticked, setTicked: function (t) { patch({ ticked: t }); }, busy: s.busy, onBack: function () { patch({ phase: 'select', error: null }); }, onReplan: replan, onDeploy: deploy });
		} else {
			body = el(ProgressPanel, { view: s.view, error: s.error, busy: s.busy, onResume: resume, onCancel: cancel, onContinue: function () { patch({ busy: true, error: null }); runSteps(s.view.run_id); }, onNew: function () { patch({ phase: 'select', view: null, error: null, notice: null }); loadCatalog(); } });
		}
		var recent = (s.catalog.recent || []).slice(0, 5);
		return el('div', null, head, body,
			recent.length && s.phase === 'select' ? el('details', { className: 'awx-recent' }, el('summary', null, __('Recent deployments', 'awesome-xp')), el('ul', null, recent.map(function (r) {
				var by = r.summary && r.summary.by_status || {};
				return el('li', { key: r.run_id }, el('code', null, r.created_at), ' ', r.label ? el('strong', null, r.label + ' ') : null, r.status, r.summary ? el('span', { className: 'awx-muted' }, ' · ' + (by.new || 0) + ' new, ' + (by.changed || 0) + ' changed, ' + (by.prod_edited || 0) + ' overwritten') : null, r.error ? el('span', { className: 'awx-warn-strong' }, ' · ' + r.error) : null,
					r.status === 'failed' || r.status === 'running' ? el(C.Button, { variant: 'link', size: 'small', onClick: function () { ability('awesome/sync-code-status', { run_id: r.run_id }).then(function (v) { patch({ view: v, phase: 'progress' }); }).catch(fail); } }, __('open', 'awesome-xp')) : null);
			}))) : null
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('awx-sync-deploy-app');
		if (!root) { return; }
		if (wp.element.createRoot) {
			wp.element.createRoot(root).render(el(App));
		} else {
			wp.element.render(el(App), root);
		}
	});
})(window.wp);
