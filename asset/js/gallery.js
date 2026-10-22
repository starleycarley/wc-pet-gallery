/*!
 * WC Petkey Gallery — frontend JS.
 * Vanilla. Uses petkeyobj if present (provided by wc-petkey-sync).
 * Renders cards matching templates/card.php and emits hidden helper forms
 * compatible with the petland theme's #petInquiry modal.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
	}

	function $$ (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function $ (sel, ctx) { return (ctx || document).querySelector(sel); }

	function getConfig() {
		var cfg = window.wcpkGallery || {};
		if (!cfg.ajax_url && typeof window.petkeyobj !== 'undefined' && window.petkeyobj.ajax_url) {
			cfg.ajax_url = window.petkeyobj.ajax_url;
		}
		if (!cfg.no_image && typeof window.petkeyobj !== 'undefined' && window.petkeyobj.no_image) {
			cfg.no_image = window.petkeyobj.no_image;
		}
		if (!cfg.no_image) {
			cfg.no_image = '';
		}
		if (!cfg.sticker_eyebrow) { cfg.sticker_eyebrow = 'Forever'; }
		if (!cfg.sticker_label) { cfg.sticker_label = 'Adopted'; }
		if (!cfg.card_cta_label) { cfg.card_cta_label = 'Get one like this'; }
		if (!cfg.form_mode) { cfg.form_mode = 'none'; }
		if (!cfg.dialog_selector) { cfg.dialog_selector = '#wcpk-inquire-dialog'; }
		if (!cfg.adopted_label_format) { cfg.adopted_label_format = ''; }
		return cfg;
	}

	var CFG = getConfig();

	/*
	 * State is per grid, not per page. Two [pet-gallery] shortcodes on one page
	 * used to share a single POSTING/END_REACHED pair and a single set of
	 * document-wide `#pet-gallery-*` filter lookups, so one grid's filter change
	 * drove the other and whichever finished first ended both.
	 */
	var STATE = new WeakMap();

	function st(gridEl) {
		var s = STATE.get(gridEl);
		if (!s) {
			// `page` is the page we last ASKED for; `loaded` is the last page we
			// actually rendered. They diverge whenever a request fails, and
			// conflating them made the retry button re-fetch the previous page
			// (duplicating its cards) and made Load more skip page 1 of a new
			// filter after a failed filter change.
			s = { posting: false, end: false, page: 1, loaded: 0, perPage: 0, failures: 0 };
			STATE.set(gridEl, s);
		}
		return s;
	}

	function rootOf(gridEl) {
		if (gridEl.closest) {
			var found = gridEl.closest('[data-wcpk-gallery]');
			if (found) { return found; }
		}
		return gridEl.parentNode || document;
	}

	function esc(value) {
		if (value === null || value === undefined) { return ''; }
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function buildHelperForm(pet) {
		if (!pet.reference_id) { return ''; }
		var fields = {
			type: pet.animal_type || '',
			breed: pet.breed_name || '',
			petid: pet.petid || '',
			reference: pet.reference_id || '',
			gender: pet.gender || '',
			color: pet.coloring || '',
			loc: pet.location || '',
			image: pet.post_image || '',
			peturl: pet.post_link || ''
		};
		var html = '<form id="petInquiry' + esc(pet.reference_id) + '" class="wcpk-helper-form" aria-hidden="true" hidden>';
		Object.keys(fields).forEach(function (k) {
			html += '<input type="hidden" name="' + esc(k) + '" value="' + esc(fields[k]) + '">';
		});
		html += '</form>';
		return html;
	}

	function buildTriggerHtml(pet) {
		if (CFG.form_mode === 'none') { return ''; }
		var icon = '<svg class="wcpk-cta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 8.5V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m3 8 9 6 9-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 8V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

		if (CFG.form_mode === 'theme_modal') {
			return '<button type="button" class="wcpk-card__cta wcpk-cta" data-bs-toggle="modal" data-bs-target="#petInquiry" data-ref-id="' + esc(pet.reference_id) + '" data-wcpk-mode="theme_modal">' + icon + '<span>' + esc(CFG.card_cta_label) + '</span></button>';
		}
		if (CFG.form_mode === 'link') {
			var url = CFG.link_url || '#';
			if (pet.reference_id) {
				url += (url.indexOf('?') === -1 ? '?' : '&') + 'pet=' + encodeURIComponent(pet.reference_id);
			}
			if (pet.breed_name) {
				url += '&breed=' + encodeURIComponent(pet.breed_name);
			}
			return '<a class="wcpk-card__cta wcpk-cta" href="' + esc(url) + '" data-ref-id="' + esc(pet.reference_id) + '" data-wcpk-mode="link">' + icon + '<span>' + esc(CFG.card_cta_label) + '</span></a>';
		}
		// plugin dialog
		return '<button type="button" class="wcpk-card__cta wcpk-cta" data-wcpk-open="wcpk-inquire-dialog" data-ref-id="' + esc(pet.reference_id) + '" data-wcpk-mode="' + esc(CFG.form_mode) + '">' + icon + '<span>' + esc(CFG.card_cta_label) + '</span></button>';
	}

	function buildCardHtml(pet) {
		var image = pet.post_image || CFG.no_image;
		// reference_id, not petid — card.php builds the alt text from the
		// reference number, and the two keys hold different values.
		var alt   = [pet.breed_name, pet.animal_type, pet.gender, pet.coloring, pet.reference_id].filter(Boolean).join(' ');
		var gender = pet.gender ? String(pet.gender) : '';
		var genderClass = gender ? 'wcpk-chip--' + gender.toLowerCase().replace(/[^a-z]/g, '') : '';
		var genderInitial = gender ? gender.charAt(0).toUpperCase() : '';

		var sticker = '<span class="wcpk-sticker" aria-hidden="true">'
			+ '<small>' + esc(CFG.sticker_eyebrow) + '</small>'
			+ '<strong>' + esc(CFG.sticker_label) + '</strong>'
			+ (pet.adopted_on ? '<time>' + esc(pet.adopted_on) + '</time>' : '')
			+ '</span>';

		var titlePieces = '<a href="' + esc(pet.post_link) + '">'
			+ esc(pet.breed_name) + (gender ? ' <span class="wcpk-card__gender">(' + esc(genderInitial) + ')</span>' : '')
			+ (pet.reference_id ? ' <span class="wcpk-card__ref">#' + esc(pet.reference_id) + '</span>' : '')
			+ '</a>';

		var chips = '';
		if (gender) {
			chips += '<span class="wcpk-chip ' + esc(genderClass) + '">'
				+ (gender.toLowerCase() === 'female' ? '♀' : '♂') + ' '
				+ esc(gender) + '</span>';
		}
		if (pet.reference_id) {
			chips += '<span class="wcpk-chip wcpk-chip--ref"># ' + esc(pet.reference_id) + '</span>';
		}

		var helper  = buildHelperForm(pet);
		var trigger = buildTriggerHtml(pet);

		return ''
			+ '<article class="wcpk-card pet pet-info" data-post-id="' + esc(pet.post_id) + '" data-ref-id="' + esc(pet.reference_id) + '">'
				+ '<a href="' + esc(pet.post_link) + '" class="wcpk-card__photo img-holder" data-post="' + esc(pet.post_id) + '" aria-label="' + esc(alt) + '">'
					+ (image ? '<img class="pet-info-img" src="' + esc(image) + '" alt="' + esc(alt) + '" loading="lazy" decoding="async">' : '')
					+ sticker
				+ '</a>'
				+ '<div class="wcpk-card__body">'
					+ '<h4 class="wcpk-card__title">' + titlePieces + '</h4>'
					+ (pet.animal_type ? '<p class="wcpk-card__meta">' + esc(pet.breed_name || pet.animal_type) + ' · ' + esc(String(pet.animal_type).toUpperCase()) + '</p>' : '')
					+ '<p class="wcpk-card__chips">' + chips + '</p>'
				+ '</div>'
				+ helper
				+ trigger
			+ '</article>';
	}

	function formData(params) {
		var body = '';
		Object.keys(params).forEach(function (k) {
			if (body) { body += '&'; }
			body += encodeURIComponent(k) + '=' + encodeURIComponent(params[k] == null ? '' : params[k]);
		});
		return body;
	}

	function showLoading(gridEl) {
		var loader = $('[data-wcpk-loading]', gridEl);
		if (loader) { loader.hidden = false; }
	}
	function hideLoading(gridEl) {
		var loader = $('[data-wcpk-loading]', gridEl);
		if (loader) { loader.hidden = true; }
	}

	function setLoadMoreLoading(gridEl, isLoading) {
		var btn = gridEl.parentNode ? $('[data-wcpk-loadmore]', gridEl.parentNode) : null;
		if (!btn) { return; }
		if (isLoading) {
			btn.classList.add('is-loading');
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
			if (!btn.dataset.idleLabel) {
				btn.dataset.idleLabel = btn.textContent.trim();
			}
		} else {
			btn.classList.remove('is-loading');
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
		}
	}

	function fetchPage(gridEl, page, replace) {
		var state = st(gridEl);
		if (state.posting) { return; }
		if (!CFG.ajax_url) { renderError(gridEl, replace, page); return; }
		state.posting = true;
		state.page = page;
		gridEl.setAttribute('aria-busy', 'true');

		// Full overlay only on a filter change / initial load. For append
		// (load-more, infinite scroll) we just put the spinner on the button
		// so the existing cards above don't flash.
		if (replace) {
			showLoading(gridEl);
		} else {
			setLoadMoreLoading(gridEl, true);
		}

		var root = rootOf(gridEl);
		var typeEl = $('#pet-gallery-type', root);
		var breedEl = $('#pet-gallery-breed', root);
		var genderEl = $('#pet-gallery-gender', root);

		var params = {
			action: 'petkey_gallery_data',
			pet_gallery_type: typeEl ? typeEl.value : (gridEl.getAttribute('data-wcpk-type') || 'ALL'),
			pet_breed: breedEl ? breedEl.value : (gridEl.getAttribute('data-wcpk-breed') || 'ALL'),
			pet_gender: genderEl ? genderEl.value : (gridEl.getAttribute('data-wcpk-gender') || 'ALL'),
			pet_page: page
		};

		// Send the page size the server rendered with. Without it a shortcode
		// carrying count="12" would ask for the global setting from page two on,
		// showing rows 0-11 and then jumping straight to row 36.
		var perPage = state.perPage || parseInt(gridEl.getAttribute('data-initial'), 10);
		if (perPage > 0) { params.pet_per_page = perPage; }

		if (CFG.nonce) {
			params.nonce = CFG.nonce;
		}

		var settle = function () {
			state.posting = false;
			gridEl.setAttribute('aria-busy', 'false');
			if (replace) {
				hideLoading(gridEl);
			} else {
				setLoadMoreLoading(gridEl, false);
			}
		};

		var xhr = new XMLHttpRequest();
		xhr.open('POST', CFG.ajax_url, true);
		xhr.timeout = 20000;
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

		xhr.onload = function () {
			settle();

			// The old handler called handleResponse regardless of status, so a
			// 500 or a 403 HTML body hit the JSON.parse catch, which returned
			// without rendering anything — a blank grid and no message.
			if (xhr.status >= 400) {
				renderError(gridEl, replace, page);
				return;
			}
			if (xhr.status === 204) {
				renderEmpty(gridEl, replace);
				return;
			}

			handleResponse(gridEl, xhr.responseText, page, replace);
		};

		xhr.onerror = function () {
			settle();
			renderError(gridEl, replace, page);
		};

		xhr.ontimeout = function () {
			settle();
			renderError(gridEl, replace, page);
		};

		xhr.send(formData(params));
	}

	function clearError(gridEl) {
		var box = $('[data-wcpk-error]', gridEl);
		if (box && box.parentNode) { box.parentNode.removeChild(box); }
	}

	function resetGrid(gridEl) {
		var loaderEl = $('[data-wcpk-loading]', gridEl);
		gridEl.innerHTML = '';
		if (loaderEl) { gridEl.appendChild(loaderEl); }
	}

	function renderEmpty(gridEl, replace) {
		var root = rootOf(gridEl);
		clearError(gridEl);
		var endHint = $('[data-wcpk-end]', root);
		var loadMore = $('[data-wcpk-loadmore]', root);

		if (replace) {
			resetGrid(gridEl);
			var empty = document.createElement('div');
			empty.className = 'wcpk-empty';
			empty.setAttribute('data-wcpk-empty', '');
			empty.innerHTML = '<p>' + esc(CFG.empty_text || 'No pets found for your search.') + '</p>';
			gridEl.appendChild(empty);
		}

		if (loadMore) { loadMore.hidden = true; }

		st(gridEl).end = true;
		if (endHint && !replace) { endHint.hidden = false; }
	}

	/*
	 * An error is not an empty gallery, and the visitor needs to be able to tell
	 * the difference. Leaves any cards already on screen in place when this was
	 * an append, and offers a retry rather than dead-ending.
	 */
	function renderError(gridEl, replace, page) {
		var state = st(gridEl);
		var root = rootOf(gridEl);

		state.failures += 1;

		if (replace) { resetGrid(gridEl); }

		var existing = $('[data-wcpk-error]', gridEl);
		if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }

		var box = document.createElement('div');
		box.className = 'wcpk-notice wcpk-notice--error';
		box.setAttribute('data-wcpk-error', '');
		box.setAttribute('role', 'alert');
		box.innerHTML = '<p>' + esc(CFG.error_text || 'We couldn’t load the gallery just now.') + '</p>'
			+ '<button type="button" class="wcpk-cta wcpk-cta--ghost" data-wcpk-retry>'
			+ esc(CFG.retry_text || 'Try again') + '</button>';
		gridEl.appendChild(box);

		var retryPage = page || state.page || 1;

		var retry = $('[data-wcpk-retry]', box);
		if (retry) {
			retry.addEventListener('click', function () {
				if (box.parentNode) { box.parentNode.removeChild(box); }
				// The page that FAILED, not the last one that succeeded —
				// otherwise retrying re-renders a page already on screen.
				fetchPage(gridEl, retryPage, replace);
			});
		}

		/*
		 * Stop after three consecutive failures. The intersection observer
		 * auto-clicks Load more from 300px away and renderError deliberately
		 * leaves the button alive, so an endpoint returning 500 would otherwise
		 * become a request loop.
		 */
		if (state.failures >= 3) {
			state.end = true;
			var loadMore = $('[data-wcpk-loadmore]', root);
			if (loadMore) {
				loadMore.hidden = true;
				if (loadMore._wcpkObserver) { loadMore._wcpkObserver.unobserve(loadMore); }
			}
		}
	}

	function handleResponse(gridEl, responseText, page, replace) {
		var state = st(gridEl);
		var root = rootOf(gridEl);

		if (!responseText) {
			renderError(gridEl, replace, page);
			return;
		}

		var parsed;
		try {
			parsed = JSON.parse(responseText);
		} catch (e) {
			// A pre-1.7.0 server emitting the raw "nothing" sentinel is the one
			// non-JSON body that means "no results". Anything else that fails to
			// parse is a broken response, not an empty one.
			if (responseText.trim() === 'nothing') {
				renderEmpty(gridEl, replace);
			} else {
				renderError(gridEl, replace, page);
			}
			return;
		}

		var payload = parsed;

		if (parsed && typeof parsed === 'object' && 'success' in parsed) {
			if (!parsed.success) {
				renderError(gridEl, replace, page);
				return;
			}
			payload = parsed.data;
		}

		// Legacy shape: data was the pets array itself.
		if (Array.isArray(payload)) {
			payload = { pets: payload, has_more: payload.length > 0 };
		}

		if (!payload || typeof payload !== 'object') {
			renderError(gridEl, replace, page);
			return;
		}

		state.failures = 0;
		clearError(gridEl);

		var pets = Array.isArray(payload.pets) ? payload.pets
			: (Array.isArray(payload.data) ? payload.data : []);
		var html = typeof payload.html === 'string' ? payload.html : '';

		if (!html && pets.length) {
			// Server too old to send markup — build it client-side, as before.
			pets.forEach(function (pet) { html += buildCardHtml(pet); });
		}

		if (!html) {
			renderEmpty(gridEl, replace);
			return;
		}

		if (replace) { resetGrid(gridEl); }

		var temp = document.createElement('div');
		temp.innerHTML = html;
		while (temp.firstChild) { gridEl.appendChild(temp.firstChild); }

		state.page = page;
		state.loaded = page;
		if (payload.per_page > 0) { state.perPage = payload.per_page; }

		var hasMore = ('has_more' in payload) ? !!payload.has_more : (pets.length > 0);
		state.end = !hasMore;

		var loadMore = $('[data-wcpk-loadmore]', root);
		var endHint = $('[data-wcpk-end]', root);
		if (loadMore) { loadMore.hidden = !hasMore; }
		if (endHint) { endHint.hidden = hasMore; }

		var pageInput = $('#pet_gallery_page_number', root);
		if (pageInput) { pageInput.value = page + 1; }
	}

	function bindFilters(gridEl) {
		var root = rootOf(gridEl);

		// Scoped to this gallery. Unscoped, a filter change in one [pet-gallery]
		// re-queried every other grid on the page.
		$$('.pet-gallery-search', root).forEach(function (el) {
			el.addEventListener('change', function () {
				var state = st(gridEl);
				state.end = false;
				state.failures = 0;
				state.page = 1;
				state.loaded = 0;

				var loadMore = $('[data-wcpk-loadmore]', root);
				if (loadMore) { loadMore.hidden = false; }
				var endHint = $('[data-wcpk-end]', root);
				if (endHint) { endHint.hidden = true; }
				var pageInput = $('#pet_gallery_page_number', root);
				if (pageInput) { pageInput.value = '1'; }

				fetchPage(gridEl, 1, true);
			});
		});
	}

	function bindLoadMore(gridEl) {
		var root = rootOf(gridEl);
		var btn = $('[data-wcpk-loadmore]', root);
		if (!btn) { return; }

		btn.addEventListener('click', function () {
			var state = st(gridEl);
			if (state.end) { return; }

			// Nothing has rendered yet — a filter change whose first request
			// failed. Ask for page 1 and replace, rather than appending page 2
			// on top of an empty grid.
			if (!state.loaded) {
				fetchPage(gridEl, 1, true);
				return;
			}

			fetchPage(gridEl, state.loaded + 1, false);
		});
	}

	function bindDialog() {
		var dialog = document.getElementById('wcpk-inquire-dialog');
		if (!dialog) { return; }

		// Native <dialog> guard
		var canShow = typeof dialog.showModal === 'function';

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest && e.target.closest('[data-wcpk-open="wcpk-inquire-dialog"]');
			if (!trigger) { return; }
			e.preventDefault();
			var ref = trigger.getAttribute('data-ref-id') || '';
			populateDialog(dialog, ref);
			if (canShow) { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
		});

		var closer = $('[data-wcpk-dialog-close]', dialog);
		if (closer) {
			closer.addEventListener('click', function () {
				if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
			});
		}

		dialog.addEventListener('click', function (e) {
			if (e.target === dialog) {
				if (typeof dialog.close === 'function') { dialog.close(); }
			}
		});
	}

	// PHP names the helper form with sanitize_html_class(), which strips
	// everything outside [A-Za-z0-9_-]. The lookup key has to be sanitized the
	// same way or a reference number containing a space or a slash finds
	// nothing and the inquiry dialog opens blank.
	function refKey(ref) {
		return String(ref).replace(/[^A-Za-z0-9_-]/g, '');
	}

	function populateDialog(dialog, ref) {
		if (!ref) { return; }
		var helper = document.getElementById('petInquiry' + refKey(ref))
			|| document.getElementById('petInquiry' + ref);
		if (!helper) { return; }
		var fields = {};
		$$('input', helper).forEach(function (input) { fields[input.name] = input.value; });

		var img = $('[data-wcpk-pet-image]', dialog);
		var title = $('[data-wcpk-pet-title]', dialog);
		var sub = $('[data-wcpk-pet-sub]', dialog);

		if (img && fields.image) { img.src = fields.image; img.alt = fields.breed || ''; }
		if (title) { title.textContent = fields.breed || ''; }
		if (sub) {
			var bits = [];
			if (fields.gender) { bits.push(fields.gender); }
			if (fields.reference) { bits.push('#' + fields.reference); }
			sub.textContent = bits.join(' · ');
		}

		// Best-effort: populate any matching hidden gfields inside the dialog
		var sel = function (key) { return dialog.querySelector('.gfield.' + key + ' input, input[name="' + key + '"]'); };
		['type', 'breed', 'petid', 'reference', 'gender', 'color', 'dob', 'loc', 'peturl'].forEach(function (key) {
			if (fields[key]) {
				var input = sel(key);
				if (input) { input.value = fields[key]; }
			}
		});
	}

	function attachIntersectionAutoload(gridEl) {
		if (typeof IntersectionObserver !== 'function') { return; }
		var btn = $('[data-wcpk-loadmore]', rootOf(gridEl));
		if (!btn) { return; }

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var state = st(gridEl);
				if (entry.isIntersecting && !state.end && !state.posting) {
					btn.click();
				}
			});
		}, { rootMargin: '300px' });

		// Kept on the node so renderError can stop auto-loading after repeated
		// failures instead of spinning on a dead endpoint.
		btn._wcpkObserver = io;
		io.observe(btn);
	}

	function lockGender(gridEl) {
		var genderEl = $('#pet-gallery-gender', rootOf(gridEl));
		if (genderEl && genderEl.getAttribute('data-locked')) {
			var locked = genderEl.getAttribute('data-locked');
			Array.prototype.forEach.call(genderEl.options, function (opt) {
				if (opt.value !== 'ALL' && opt.value !== locked) {
					opt.disabled = true;
				}
			});
			genderEl.value = locked;
		}
	}

	function init() {
		$$('[data-wcpk-gallery]').forEach(function (root) {
			var grid = $('[data-wcpk-grid]', root);
			if (!grid) { return; }

			var state = st(grid);
			state.page = parseInt(grid.getAttribute('data-wcpk-page'), 10) || 1;
			state.perPage = parseInt(grid.getAttribute('data-initial'), 10) || 0;

			// Server-rendered page 1 counts as loaded; without this the first
			// Load more would re-request the page already on screen.
			state.loaded = grid.getAttribute('data-wcpk-ssr') === '1' ? state.page : 0;

			lockGender(grid);
			bindFilters(grid);
			bindLoadMore(grid);
			attachIntersectionAutoload(grid);

			if (grid.getAttribute('data-wcpk-ssr') === '1') {
				// Page one is already in the HTML. Re-fetching it would throw
				// away server-rendered cards, flash the grid, and spend a
				// request on markup we already have.
				state.end = grid.getAttribute('data-wcpk-has-more') !== '1';

				var loadMore = $('[data-wcpk-loadmore]', root);
				if (loadMore) { loadMore.hidden = state.end; }
				return;
			}

			fetchPage(grid, 1, true);
		});

		bindDialog();
	}

	ready(init);
})();
