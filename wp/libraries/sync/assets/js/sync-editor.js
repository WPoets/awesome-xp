/**
 * AWX Sync: "Sync to Production" sidebar for the block editor.
 *
 * Drives the awesome/sync-* abilities: save, analyze, plan (dry run), confirm,
 * then one short request per step with a progress bar. No build step: plain
 * wp.element.createElement.
 */
(function (wp) {
	if (!wp || !wp.plugins || !window.awxSync) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Fragment = wp.element.Fragment;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = wp.editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var cfg = window.awxSync;

	function ability(name, input) {
		return apiFetch({ path: cfg.abilities + name + '/execute', method: 'POST', data: input || {} }).then(function (res) {
			if (!res || res.success === false) {
				var e = (res && res.error) || {};
				var err = new Error(e.message || __('Sync failed.', 'awesome-xp'));
				err.code = e.code || 'error';
				err.nextStep = e.next_step || '';
				err.details = e.details || {};
				throw err;
			}
			return res.data;
		});
	}

	function human(bytes) {
		bytes = Number(bytes) || 0;
		if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' MB'; }
		if (bytes >= 1024) { return Math.round(bytes / 1024) + ' KB'; }
		return bytes + ' B';
	}

	function statusLabel(s) {
		return { publish: __('Published', 'awesome-xp'), draft: __('Draft', 'awesome-xp'), pending: __('Pending', 'awesome-xp'), private: __('Private', 'awesome-xp'), future: __('Scheduled', 'awesome-xp') }[s] || s || __('new', 'awesome-xp');
	}

	function Notice(props) {
		return el(C.Notice, { status: props.status || 'info', isDismissible: false }, props.children);
	}

	function PlanView(props) {
		var view = props.view;
		var plan = view.plan || {};
		var s = plan.summary || {};
		var blockers = plan.blockers || [];
		var warnings = plan.warnings || [];
		var lines = [];
		if (s.nothing_to_do) {
			lines.push(__('Nothing to do: the target already matches this page.', 'awesome-xp'));
		} else {
			if (s.media_count) {
				lines.push(s.media_count + ' ' + __('media file(s)', 'awesome-xp') + ' (' + human(s.media_bytes) + (s.media_pull ? ', ' + s.media_pull + ' ' + __('pulled by the target', 'awesome-xp') : '') + ')');
			}
			if (s.blocks_count) {
				lines.push(s.blocks_count + ' ' + __('reusable block(s)', 'awesome-xp') + (s.affected_pages && s.affected_pages.length ? ', ' + __('also used on', 'awesome-xp') + ' ' + s.affected_pages.length + ' ' + __('other page(s)', 'awesome-xp') : ''));
			}
			lines.push((s.page_action === 'create' ? __('Create the page', 'awesome-xp') : __('Overwrite the page content', 'awesome-xp')) + ' · ' + statusLabel(s.from_status) + ' → ' + statusLabel(s.to_status));
		}
		return el(Fragment, null,
			el('ul', { style: { paddingLeft: '18px' } }, lines.map(function (l, i) { return el('li', { key: i }, l); })),
			s.publishes ? el(Notice, { status: 'warning' }, __('This sync PUBLISHES the page on the target.', 'awesome-xp')) : null,
			s.unpublishes ? el(Notice, { status: 'warning' }, __('This sync takes a live page out of publish on the target.', 'awesome-xp')) : null,
			s.edited_on_prod ? el(Notice, { status: 'error' }, __('The target page was edited since the last sync. Its edits will be replaced (a revision is kept).', 'awesome-xp')) : null,
			s.affected_pages && s.affected_pages.length ? el('details', null, el('summary', null, __('Other pages that will change', 'awesome-xp')), el('ul', { style: { paddingLeft: '18px' } }, s.affected_pages.map(function (p) { return el('li', { key: p.id }, el('a', { href: p.permalink, target: '_blank', rel: 'noreferrer' }, p.title || ('#' + p.id))); }))) : null,
			blockers.map(function (b, i) { return el(Notice, { key: 'b' + i, status: 'error' }, b.message); }),
			warnings.length ? el('details', { open: warnings.length <= 3 }, el('summary', null, warnings.length + ' ' + __('warning(s)', 'awesome-xp')), el('ul', { style: { paddingLeft: '18px', fontSize: '12px' } }, warnings.map(function (w, i) { return el('li', { key: i }, el('code', null, w.code), ' ', w.message); }))) : null
		);
	}

	function Sidebar() {
		var st = useState({ phase: 'idle', view: null, error: null, status: null, overwrite: false, busy: false });
		var state = st[0];
		var setState = st[1];
		var cancelled = useRef(false);
		var editor = wp.data.useSelect ? wp.data.useSelect(function (select) {
			var e = select('core/editor');
			return { dirty: e.isEditedPostDirty(), saving: e.isSavingPost(), isNew: e.isEditedPostNew() };
		}, []) : { dirty: false, saving: false, isNew: false };

		function patch(p) { setState(function (s) { return Object.assign({}, s, p); }); }
		function fail(err) { patch({ busy: false, error: err }); }

		function loadStatus() {
			ability('awesome/sync-status', { post_id: cfg.postId }).then(function (d) { patch({ status: d }); }).catch(function () {});
		}
		useEffect(function () { loadStatus(); }, []);

		function start() {
			cancelled.current = false;
			patch({ busy: true, error: null, phase: 'saving', view: null, overwrite: false });
			var save = editor.dirty || editor.isNew ? wp.data.dispatch('core/editor').savePost() : Promise.resolve();
			Promise.resolve(save).then(function () {
				patch({ phase: 'analyzing' });
				return ability('awesome/sync-analyze', { post_id: cfg.postId });
			}).then(function (a) {
				patch({ phase: 'planning' });
				return ability('awesome/sync-plan', { run_id: a.run_id });
			}).then(function (view) {
				patch({ busy: false, phase: view.status === 'blocked' ? 'blocked' : (view.status === 'done' ? 'done' : 'plan'), view: view });
				loadStatus();
			}).catch(fail);
		}

		function resume(runId) {
			cancelled.current = false;
			patch({ busy: true, error: null, phase: 'planning', view: null, overwrite: false });
			ability('awesome/sync-resume', { run_id: runId }).then(function (view) {
				patch({ busy: false, phase: view.status === 'blocked' ? 'blocked' : (view.status === 'done' ? 'done' : 'plan'), view: view });
			}).catch(fail);
		}

		function confirm() {
			var runId = state.view.run_id;
			patch({ busy: true, error: null, phase: 'running' });
			ability('awesome/sync-confirm', { run_id: runId, overwrite: state.overwrite }).then(function (view) {
				patch({ view: view });
				return runSteps(runId);
			}).catch(fail);
		}

		function runSteps(runId) {
			if (cancelled.current) {
				return ability('awesome/sync-cancel', { run_id: runId }).then(function () { patch({ busy: false, phase: 'cancelled' }); loadStatus(); });
			}
			return ability('awesome/sync-step', { run_id: runId }).then(function (view) {
				patch({ view: view });
				if (view.error && !view.error.retryable) {
					patch({ busy: false, phase: 'failed' });
					loadStatus();
					return;
				}
				if (view.status === 'done') {
					patch({ busy: false, phase: 'done' });
					loadStatus();
					wp.data.dispatch('core/notices').createSuccessNotice(__('Synced to production.', 'awesome-xp'), { type: 'snackbar' });
					return;
				}
				if (view.status !== 'running') {
					patch({ busy: false, phase: 'failed' });
					return;
				}
				return runSteps(runId);
			});
		}

		function cancel() {
			cancelled.current = true;
			if (state.phase === 'plan' && state.view) {
				ability('awesome/sync-cancel', { run_id: state.view.run_id }).then(function () { patch({ phase: 'idle', view: null }); loadStatus(); });
			}
		}

		var view = state.view;
		var progress = view && view.progress ? view.progress : null;
		var openRun = state.status && state.status.open_run;
		var last = (view && view.last_sync) || (state.status && state.status.last_sync);
		var current = view && view.steps ? view.steps.filter(function (s) { return s.status === 'done'; }).slice(-1)[0] : null;

		var body;
		if (!cfg.configured) {
			body = el(Notice, { status: 'warning' }, __('Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN to wp-config.php on this site to enable sync.', 'awesome-xp'));
		} else if (state.phase === 'idle' || state.phase === 'cancelled') {
			body = el(Fragment, null,
				openRun && openRun.status !== 'done' ? el(Notice, { status: 'warning' },
					__('An earlier sync was interrupted', 'awesome-xp') + ' (' + openRun.progress.done + ' ' + __('of', 'awesome-xp') + ' ' + openRun.progress.total + ' ' + __('steps done', 'awesome-xp') + '). ',
					el(C.Button, { variant: 'link', onClick: function () { resume(openRun.run_id); } }, __('Resume sync', 'awesome-xp'))
				) : null,
				el(C.Button, { variant: 'primary', isBusy: state.busy, disabled: state.busy || editor.saving, onClick: start }, __('Sync to Production', 'awesome-xp')),
				el('p', { className: 'description' }, __('Saves the page, then shows what would change on', 'awesome-xp'), ' ', el('code', null, cfg.targetUrl), ' ', __('before anything is written.', 'awesome-xp'))
			);
		} else if (state.phase === 'saving' || state.phase === 'analyzing' || state.phase === 'planning') {
			body = el('p', null, el(C.Spinner), ' ', { saving: __('Saving…', 'awesome-xp'), analyzing: __('Reading dependencies and contacting the target…', 'awesome-xp'), planning: __('Comparing with the target…', 'awesome-xp') }[state.phase]);
		} else if (state.phase === 'plan' || state.phase === 'blocked') {
			var sum = (view.plan && view.plan.summary) || {};
			body = el(Fragment, null,
				el(PlanView, { view: view }),
				state.phase === 'plan' && sum.edited_on_prod ? el(C.CheckboxControl, { label: __('Overwrite the edits made on the target', 'awesome-xp'), checked: state.overwrite, onChange: function (v) { patch({ overwrite: v }); } }) : null,
				el(C.Flex, { justify: 'flex-start' },
					state.phase === 'plan' ? el(C.Button, { variant: 'primary', isBusy: state.busy, disabled: state.busy || (sum.edited_on_prod && !state.overwrite), onClick: confirm }, __('Confirm and sync', 'awesome-xp')) : null,
					el(C.Button, { variant: 'secondary', onClick: cancel }, __('Cancel', 'awesome-xp'))
				)
			);
		} else if (state.phase === 'running') {
			body = el(Fragment, null,
				el('p', null, el(C.Spinner), ' ', progress ? progress.done + ' / ' + progress.total : ''),
				progress ? el('progress', { max: progress.total, value: progress.done, style: { width: '100%' } }) : null,
				current ? el('p', { className: 'description' }, current.type + ' ' + (current.input && current.input.file ? current.input.file : (current.object_uuid || '').slice(0, 8))) : null,
				view && view.error && view.error.retryable ? el(Notice, { status: 'warning' }, __('Retrying:', 'awesome-xp') + ' ' + view.error.message) : null,
				el(C.Button, { variant: 'secondary', onClick: cancel }, __('Stop after this step', 'awesome-xp'))
			);
		} else if (state.phase === 'done') {
			body = el(Fragment, null,
				el(Notice, { status: 'success' }, __('Synced.', 'awesome-xp'), ' ', last && last.permalink ? el('a', { href: last.permalink, target: '_blank', rel: 'noreferrer' }, __('Open on production', 'awesome-xp')) : null),
				last && last.revision_id ? el('p', { className: 'description' }, __('Previous content is kept as revision', 'awesome-xp') + ' #' + last.revision_id + ' ' + __('on the target (Revisions screen).', 'awesome-xp')) : null,
				el(C.Button, { variant: 'secondary', onClick: function () { patch({ phase: 'idle', view: null }); } }, __('Done', 'awesome-xp'))
			);
		} else if (state.phase === 'failed') {
			var err = view && view.error;
			body = el(Fragment, null,
				el(Notice, { status: 'error' }, (err ? err.code + ': ' + err.message : (view && view.error_text) || __('The sync stopped.', 'awesome-xp'))),
				err && err.details && err.details.missing ? el('ul', { style: { paddingLeft: '18px', fontSize: '12px' } }, err.details.missing.map(function (m, i) { return el('li', { key: i }, m.type + ' ' + m.key); })) : null,
				el(C.Flex, { justify: 'flex-start' },
					el(C.Button, { variant: 'primary', onClick: function () { resume(view.run_id); } }, __('Re-plan and resume', 'awesome-xp')),
					el(C.Button, { variant: 'secondary', onClick: function () { patch({ phase: 'idle', view: null }); } }, __('Close', 'awesome-xp'))
				)
			);
		}

		return el(Fragment, null,
			el(PluginSidebarMoreMenuItem, { target: 'awx-sync' }, __('Sync to Production', 'awesome-xp')),
			el(PluginSidebar, { name: 'awx-sync', title: __('Sync to Production', 'awesome-xp'), icon: 'migrate' },
				el(C.PanelBody, { initialOpen: true },
					state.error ? el(Notice, { status: 'error' }, state.error.code + ': ' + state.error.message + (state.error.nextStep ? ' ' + state.error.nextStep : '')) : null,
					body
				),
				last ? el(C.PanelBody, { title: __('Last sync', 'awesome-xp'), initialOpen: false },
					el('p', null, new Date((last.time || 0) * 1000).toLocaleString()),
					last.permalink ? el('p', null, el('a', { href: last.permalink, target: '_blank', rel: 'noreferrer' }, last.permalink)) : null
				) : null
			)
		);
	}

	registerPlugin('awx-sync', { render: Sidebar });
})(window.wp);
