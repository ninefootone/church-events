/**
 * Church Events — Frontend JS
 *
 * Handles:
 *  - FullCalendar month grid
 *  - List/grid view with pagination (Load More)
 *  - Category, text search, and date range filtering
 *  - View toggle (calendar <-> list) with responsive auto-switch at 768px
 *  - Filter persistence across calendar month navigation
 *  - Event detail modal
 *  - Hover preview popover
 *
 * Config passed from PHP via ceConfig (wp_localize_script).
 */

( function () {
	'use strict';

	if ( typeof ceConfig === 'undefined' ) {
		console.warn( 'Church Events: ceConfig not found.' );
		return;
	}

	const cfg = ceConfig;

	// ---------------------------------------------------------------------------
	// Utilities
	// ---------------------------------------------------------------------------

	function decodeEntities( str ) {
		if ( ! str ) return '';
		const txt = document.createElement( 'textarea' );
		txt.innerHTML = str;
		return txt.value;
	}

	function escHtml( str ) {
		if ( ! str ) return '';
		const d = document.createElement( 'div' );
		d.textContent = String( str );
		return d.innerHTML;
	}

	function safeUrl( url ) {
		if ( ! url ) return '#';
		try {
			const u = new URL( url );
			return ( u.protocol === 'https:' || u.protocol === 'http:' ) ? url : '#';
		} catch ( e ) { return '#'; }
	}

	function formatDate( dateStr ) {
		if ( ! dateStr || dateStr.length < 8 ) return '';
		const y = parseInt( dateStr.substring( 0, 4 ), 10 );
		const m = parseInt( dateStr.substring( 4, 6 ), 10 ) - 1;
		const d = parseInt( dateStr.substring( 6, 8 ), 10 );
		return new Date( y, m, d ).toLocaleDateString(
			document.documentElement.lang || 'en-GB',
			{ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }
		);
	}

	function formatTime( timeStr ) {
		if ( ! timeStr || timeStr === '00:00:00' || timeStr === '00:00' ) return '';
		return timeStr.substring( 0, 5 );
	}

	function toISO( date, time ) {
		if ( ! date || date.length < 8 ) return '';
		const y = date.substring( 0, 4 );
		const m = date.substring( 4, 6 );
		const d = date.substring( 6, 8 );
		if ( ! time || time === '00:00:00' ) return y + '-' + m + '-' + d;
		return y + '-' + m + '-' + d + 'T' + time;
	}

	function isAllDay( meta ) {
		if ( meta.all_day ) return true;
		if ( ! meta.start_time ) return true;
		if ( meta.start_time === '00:00:00' || meta.start_time === '00:00' ) return true;
		return false;
	}

	function dateToYMD( date ) {
		const y = date.getFullYear();
		const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
		const d = String( date.getDate() ).padStart( 2, '0' );
		return y + m + d;
	}

	// ---------------------------------------------------------------------------
	// REST fetching
	// ---------------------------------------------------------------------------

	async function fetchAll( url ) {
		let page = 1, results = [], total = Infinity;
		while ( results.length < total ) {
			const sep      = url.includes( '?' ) ? '&' : '?';
			const response = await fetch( url + sep + 'per_page=100&page=' + page, {
				headers: { 'X-WP-Nonce': cfg.restNonce },
			} );
			if ( ! response.ok ) break;
			const th = response.headers.get( 'X-WP-Total' );
			if ( th ) total = parseInt( th, 10 );
			const data = await response.json();
			if ( ! data.length ) break;
			results = results.concat( data );
			page++;
			if ( data.length < 100 ) break;
		}
		return results;
	}

	/**
	 * Fetch a single page of events with filters.
	 * Returns { events, total, totalPages }.
	 */
	async function fetchEventPage( filters, page, perPage ) {
		perPage = perPage || cfg.perPage || 12;
		let url = cfg.restUrl
			+ '?cal_after='  + filters.after
			+ '&cal_before=' + filters.before
			+ '&per_page='   + perPage
			+ '&page='       + page;

		if ( filters.category ) url += '&event_category=' + encodeURIComponent( filters.category );
		if ( filters.site )     url += '&event_site='     + encodeURIComponent( filters.site );
		if ( filters.search )   url += '&event_search='   + encodeURIComponent( filters.search );

		const response = await fetch( url, { headers: { 'X-WP-Nonce': cfg.restNonce } } );
		if ( ! response.ok ) return { events: [], total: 0, totalPages: 0 };

		const total      = parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 );
		const totalPages = parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 );
		const events     = await response.json();

		return { events, total, totalPages };
	}

	/**
	 * Fetch events for a date range (calendar use — no pagination needed).
	 */
	async function fetchEvents( after, before, category, site, search ) {
		let url = cfg.restUrl + '?cal_after=' + after + '&cal_before=' + before + '&per_page=100';
		if ( category ) url += '&event_category=' + encodeURIComponent( category );
		if ( site )     url += '&event_site='     + encodeURIComponent( site );
		if ( search )   url += '&event_search='   + encodeURIComponent( search );
		const response = await fetch( url, { headers: { 'X-WP-Nonce': cfg.restNonce } } );
		if ( ! response.ok ) return [];
		return response.json();
	}

	// ---------------------------------------------------------------------------
	// Category filter population
	// ---------------------------------------------------------------------------

	async function populateMonthFilter( select ) {
		try {
			const base  = cfg.restUrl.replace( /\/[^/]+\/?$/, '' );
			const terms = await fetchAll( base + '/event-month?orderby=slug&order=asc&hide_empty=true' );
			while ( select.options.length > 1 ) select.remove( 1 );
			terms.forEach( t => {
				const opt       = document.createElement( 'option' );
				opt.value       = t.slug;
				opt.textContent = decodeEntities( t.name );
				select.appendChild( opt );
			} );
		} catch ( e ) {
			console.warn( 'Church Events: could not load months.', e );
		}
	}

	async function populateCategoryFilter( select ) {
		try {
			const base  = cfg.restUrl.replace( /\/[^/]+\/?$/, '' );
			const terms = await fetchAll( base + '/event-category?orderby=name&order=asc' );
			while ( select.options.length > 1 ) select.remove( 1 );
			terms.filter( t => t.count > 0 ).forEach( t => {
				const opt       = document.createElement( 'option' );
				opt.value       = t.slug;
				opt.textContent = decodeEntities( t.name );
				select.appendChild( opt );
			} );
		} catch ( e ) {
			console.warn( 'Church Events: could not load categories.', e );
		}
	}

	// ---------------------------------------------------------------------------
	// Field rendering
	// ---------------------------------------------------------------------------

	function getEnabledFields( fieldsConfig ) {
		if ( ! fieldsConfig ) return [];
		return Object.entries( fieldsConfig )
			.filter( function( entry ) { return entry[1].enabled; } )
			.sort(   function( a, b  ) { return a[1].order - b[1].order; } )
			.map(    function( entry ) { return entry[0]; } );
	}

	function renderField( key, event, context ) {
		const meta  = event.event_meta || {};
		const title = decodeEntities( ( event.title && event.title.rendered ) || '' );

		switch ( key ) {

			case 'featured_image':
				if ( ! event.featured_image_url ) return '';
				return '<div class="ce-card-image"><img src="' + event.featured_image_url + '" alt="' + title + '" loading="lazy" /></div>';

			case 'title':
				if ( context === 'detail' ) return '<h2 class="ce-modal-title">' + title + '</h2>';
				return '<h3 class="ce-card-title">' + title + '</h3>';

			case 'date': {
				const ds = formatDate( meta.start_date );
				if ( ! ds ) return '';
				const de = ( meta.end_date && meta.end_date !== meta.start_date )
					? ' \u2013 ' + formatDate( meta.end_date ) : '';
				return '<span class="ce-card-meta-item ce-meta-date">' + ds + de + '</span>';
			}

			case 'time': {
				if ( isAllDay( meta ) ) {
					return '<span class="ce-card-meta-item ce-meta-time">' + cfg.i18n.allDay + '</span>';
				}
				const st = formatTime( meta.start_time );
				const et = meta.end_time ? formatTime( meta.end_time ) : '';
				const ts = et ? st + ' \u2013 ' + et : st;
				if ( ! ts ) return '';
				return '<span class="ce-card-meta-item ce-meta-time"><span class="ce-meta-icon" aria-hidden="true"></span>' + ts + '</span>';
			}

			case 'location':
				if ( ! meta.location ) return '';
				return '<span class="ce-card-meta-item ce-meta-location"><span class="ce-meta-icon" aria-hidden="true"></span>' + escHtml( meta.location ) + '</span>';

			case 'address':
				if ( ! meta.address ) return '';
				return '<span class="ce-card-meta-item ce-meta-address">' + escHtml( meta.address ) + '</span>';

			case 'excerpt': {
				if ( context === 'detail' ) return '';
				const rawEx = event.excerpt && event.excerpt.rendered ? event.excerpt.rendered : '';
				if ( ! rawEx ) return '';
				const tmpEx = document.createElement( 'div' );
				tmpEx.innerHTML = rawEx;
				const ex = tmpEx.textContent.trim();
				if ( ! ex ) return '';
				return '<p class="ce-card-excerpt">' + escHtml( ex ) + '</p>';
			}

			case 'description': {
				if ( context !== 'detail' ) return '';
				const cn = ( event.content && event.content.rendered ) || '';
				if ( ! cn ) return '';
				return '<div class="ce-modal-description">' + cn + '</div>';
			}

			case 'categories': {
				const cats = event.event_categories || [];
				if ( ! cats.length ) return '';
				return '<div class="ce-card-categories">' +
					cats.map( function( c ) {
						var style = c.color
							? ' style="background-color:' + c.color + ';color:' + ceTextColorFor( c.color ) + ';"'
							: '';
						return '<span class="ce-category-tag"' + style + '>' + decodeEntities( c.name ) + '</span>';
					} ).join( '' ) + '</div>';
			}

			case 'booking_link': {
				if ( ! meta.booking_url ) return '';
				const lbl = escHtml( meta.booking_text || cfg.i18n.bookNow );
				return '<div class="ce-card-booking"><a href="' + safeUrl( meta.booking_url ) + '" class="ce-btn ce-btn-primary" target="_blank" rel="noopener noreferrer">' + lbl + '</a></div>';
			}

			default:
				return '';
		}
	}

	function buildCard( event, fields ) {
		const metaFields = [ 'date', 'time', 'location', 'address' ];
		let html = '', metaOpen = false;

		if ( fields.indexOf( 'featured_image' ) !== -1 ) {
			const cats = event.event_categories || [];
			const pillHtml = cats.length
				? '<div class="ce-card-image-pills">' +
					cats.map( function( c ) {
						var style = c.color
							? ' style="background-color:' + c.color + ';color:' + ceTextColorFor( c.color ) + ';"'
							: '';
						return '<span class="ce-category-tag"' + style + '>' + decodeEntities( c.name ) + '</span>';
					} ).join( '' ) +
					'</div>'
				: '';

			if ( event.featured_image_url ) {
				html += '<div class="ce-card-image">'
					+ '<img src="' + event.featured_image_url + '" alt="' + escHtml( event.title && event.title.rendered || '' ) + '" loading="lazy" />'
					+ pillHtml
					+ '</div>';
			} else {
				html += '<div class="ce-card-no-image">' + pillHtml + '</div>';
			}
		}

		html += '<div class="ce-card-body">';

		fields.forEach( function( key ) {
			if ( key === 'featured_image' ) return;
			if ( key === 'categories' ) return;
			const isMeta = metaFields.indexOf( key ) !== -1;
			if ( isMeta && ! metaOpen )  { html += '<div class="ce-card-meta">'; metaOpen = true;  }
			if ( ! isMeta && metaOpen )  { html += '</div>';                      metaOpen = false; }
			html += renderField( key, event, 'archive' );
		} );

		if ( metaOpen ) html += '</div>';
		html += '</div>';
		return html;
	}

	function buildModalContent( event ) {
		const fields     = getEnabledFields( cfg.detailFields );
		const metaFields = [ 'date', 'time', 'location', 'address' ];
		let html = '', metaOpen = false;

		if ( fields.indexOf( 'featured_image' ) !== -1 && event.featured_image_url ) {
			const cats = event.event_categories || [];
			const pillHtml = cats.length
				? '<div class="ce-card-image-pills">' +
					cats.slice( 0, 1 ).map( function( c ) {
						var style = c.color
							? ' style="background-color:' + c.color + ';color:' + ceTextColorFor( c.color ) + ';"'
							: '';
						return '<span class="ce-category-tag"' + style + '>' + decodeEntities( c.name ) + '</span>';
					} ).join( '' ) +
					'</div>'
				: '';
			html += '<div class="ce-modal-image">'
				+ '<img src="' + event.featured_image_url
				+ '" alt="' + decodeEntities( ( event.title && event.title.rendered ) || '' ) + '" />'
				+ pillHtml
				+ '</div>';
		}

		html += '<div class="ce-modal-body">';

		fields.forEach( function( key ) {
			if ( key === 'featured_image' ) return;
			const isMeta = metaFields.indexOf( key ) !== -1;
			if ( isMeta && ! metaOpen )  { html += '<div class="ce-modal-meta">'; metaOpen = true;  }
			if ( ! isMeta && metaOpen )  { html += '</div>';                       metaOpen = false; }
			html += renderField( key, event, 'detail' );
		} );

		if ( metaOpen ) html += '</div>';

		if ( event.event_url ) {
			var footerHtml = '<div class="ce-modal-footer">';
			if ( cfg.interaction === 'page' ) {
				footerHtml += '<a href="' + event.event_url
					+ '" class="ce-btn ce-btn-primary">' + cfg.i18n.viewDetails + '</a>';
			}
			footerHtml += '<div class="ce-modal-share">'
				+ '<button class="ce-share-btn" data-url="' + event.event_url + '" data-title="' + decodeEntities( ( event.title && event.title.rendered ) || '' ) + '" aria-label="Share this event">'
				+ '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>'
				+ '<span class="ce-share-label">Share</span>'
				+ '</button>'
				+ '</div>'
				+ '</div>';
			html += footerHtml;
		}

		html += '</div>';
		return html;
	}

	// ---------------------------------------------------------------------------
	// Agenda row builder
	// ---------------------------------------------------------------------------

	var _agendaLastDate = null;

	function buildAgendaRow( event ) {
		var meta  = event.event_meta || {};
		var title = decodeEntities( ( event.title && event.title.rendered ) || '' );
		var lang  = document.documentElement.lang || 'en-GB';

		var dateHtml = '';
		if ( meta.start_date && meta.start_date.length >= 8 ) {
			var y   = parseInt( meta.start_date.substring( 0, 4 ), 10 );
			var mo  = parseInt( meta.start_date.substring( 4, 6 ), 10 ) - 1;
			var d   = parseInt( meta.start_date.substring( 6, 8 ), 10 );
			var dt  = new Date( y, mo, d );
			var ymd = meta.start_date.substring( 0, 8 );

			if ( ymd !== _agendaLastDate ) {
				_agendaLastDate = ymd;
				dateHtml = '<span class="ce-agenda-month">'
					+ dt.toLocaleDateString( lang, { month: 'short' } ).toUpperCase() + '</span>'
					+ '<span class="ce-agenda-day-num">'
					+ dt.toLocaleDateString( lang, { day: '2-digit' } ) + '</span>'
					+ '<span class="ce-agenda-year">' + y + '</span>';
			}
		}

		var timeLine = '';
		if ( ! isAllDay( meta ) ) {
			var st = formatTime( meta.start_time );
			var et = meta.end_time ? formatTime( meta.end_time ) : '';
			var ts = et ? st + '\u2013' + et : st;
			if ( ts ) {
				timeLine = '<span class="ce-agenda-meta ce-agenda-time">'
					+ '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
					+ ts + '</span>';
			}
		}

		var locationLine = '';
		if ( meta.location ) {
			locationLine = '<span class="ce-agenda-meta ce-agenda-location">'
				+ '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
					+ escHtml( meta.location ) + '</span>';
		}

		var imageHtml = '';
		if ( event.featured_image_url ) {
			imageHtml = '<div class="ce-agenda-image-col">'
				+ '<img src="' + event.featured_image_url + '" alt="' + title + '" loading="lazy" />'
				+ '</div>';
		}

		return '<div class="ce-agenda-date-col">' + dateHtml + '</div>'
			+ '<div class="ce-agenda-detail-col">'
			+ '<span class="ce-agenda-title">' + title + '</span>'
			+ timeLine
			+ locationLine
			+ '</div>'
			+ imageHtml;
	}

	// ---------------------------------------------------------------------------
	// Modal
	// ---------------------------------------------------------------------------

	function EventModal( root ) {
		this.overlay  = root.querySelector( '.ce-modal-overlay' );
		this.content  = this.overlay && this.overlay.querySelector( '.ce-modal-content' );
		this.closeBtn = this.overlay && this.overlay.querySelector( '.ce-modal-close' );
		if ( ! this.overlay ) return;
		var self = this;
		this.closeBtn && this.closeBtn.addEventListener( 'click', function() { self.close(); } );
		this.overlay.addEventListener( 'click', function( e ) {
			if ( e.target === self.overlay ) self.close();
		} );
		document.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' && ! self.overlay.hidden ) self.close();
		} );
	}

	EventModal.prototype.open = function( event ) {
		if ( ! this.overlay ) return;
		this.content.innerHTML       = buildModalContent( event );
		this.overlay.hidden          = false;
		var overlay = this.overlay;
		requestAnimationFrame( function() { overlay.classList.add( 'is-open' ); } );
		this.closeBtn && this.closeBtn.focus();
		document.body.style.overflow = 'hidden';

		var shareBtn = this.content.querySelector( '.ce-share-btn' );
		if ( shareBtn ) {
			shareBtn.addEventListener( 'click', function() {
				var url   = shareBtn.dataset.url;
				var title = shareBtn.dataset.title;
				if ( navigator.share ) {
					navigator.share( { title: title, url: url } ).catch( function() {} );
				} else if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( function() {
						shareBtn.classList.add( 'is-copied' );
						setTimeout( function() { shareBtn.classList.remove( 'is-copied' ); }, 2000 );
					} );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = url;
					ta.style.position = 'fixed';
					ta.style.opacity  = '0';
					document.body.appendChild( ta );
					ta.select();
					document.execCommand( 'copy' );
					document.body.removeChild( ta );
					shareBtn.classList.add( 'is-copied' );
					setTimeout( function() { shareBtn.classList.remove( 'is-copied' ); }, 2000 );
				}
			} );
		}
	};

	EventModal.prototype.close = function() {
		if ( ! this.overlay ) return;
		this.overlay.classList.remove( 'is-open' );
		document.body.style.overflow = '';
		var overlay = this.overlay;
		setTimeout( function() { overlay.hidden = true; }, 200 );
	};

	// ---------------------------------------------------------------------------
	// Hover Preview
	// ---------------------------------------------------------------------------

	function HoverPreview( root ) {
		this.el      = root.querySelector( '.ce-hover-preview' );
		this.titleEl = this.el && this.el.querySelector( '.ce-hover-preview-title' );
		this.metaEl  = this.el && this.el.querySelector( '.ce-hover-preview-meta' );
	}

	HoverPreview.prototype.show = function( event, anchor ) {
		if ( ! this.el ) return;
		var meta  = event.event_meta || {};
		var time  = isAllDay( meta ) ? cfg.i18n.allDay : ( formatTime( meta.start_time ) || '' );
		this.titleEl.textContent = decodeEntities( ( event.title && event.title.rendered ) || '' );
		this.metaEl.textContent  = [ formatDate( meta.start_date ), time, meta.location ]
			.filter( Boolean ).join( ' \u00B7 ' );
		this.el.hidden = false;
		this.position( anchor );
		var el = this.el;
		requestAnimationFrame( function() { el.classList.add( 'is-visible' ); } );
	};

	HoverPreview.prototype.hide = function() {
		if ( ! this.el ) return;
		this.el.classList.remove( 'is-visible' );
		var el = this.el;
		setTimeout( function() { el.hidden = true; }, 200 );
	};

	HoverPreview.prototype.position = function( anchor ) {
		if ( ! this.el || ! anchor ) return;
		var rect = anchor.getBoundingClientRect();
		this.el.style.position = 'fixed';
		this.el.style.top  = ( rect.bottom + 8 ) + 'px';
		this.el.style.left = rect.left + 'px';
		// Keep within viewport horizontally
		var elWidth = this.el.offsetWidth || 280;
		if ( rect.left + elWidth > window.innerWidth - 8 ) {
			this.el.style.left = Math.max( 8, window.innerWidth - elWidth - 8 ) + 'px';
		}
	};

	// ---------------------------------------------------------------------------
	// Cards/List view — with pagination
	// 'cards' renders the grid/stack card layout.
	// 'list'  renders the agenda row layout.
	// ---------------------------------------------------------------------------

	function ListView( root, modal, hover, viewType ) {
		this.root        = root;
		this.modal       = modal;
		this.hover       = hover;
		this.viewType    = viewType || 'cards'; // 'cards' | 'list'
		var selector     = viewType === 'list' ? '.ce-view--list .ce-events-output' : '.ce-view--cards .ce-events-output';
		this.output      = root.querySelector( selector );
		this.loadMoreBtn = null;
		this.currentPage = 1;
		this.totalPages  = 1;
		this.filters     = {};
	}

	ListView.prototype.showLoading = function() {
		if ( this.output ) {
			this.output.innerHTML = '<div class="ce-loading">' + cfg.i18n.loading + '</div>';
		}
	};

	ListView.prototype.showLoadingMore = function() {
		if ( this.loadMoreBtn ) {
			this.loadMoreBtn.disabled     = true;
			this.loadMoreBtn.textContent  = cfg.i18n.loading;
		}
	};

	ListView.prototype.load = async function( filters, append ) {
		this.filters = filters;
		this.limit   = parseInt( this.root.dataset.limit, 10 ) || 0;

		if ( ! append ) {
			this.currentPage = 1;
			this.showLoading();
		} else {
			this.currentPage++;
			this.showLoadingMore();
		}

		try {
			const result = await fetchEventPage( filters, this.currentPage, this.limit || undefined );
			this.totalPages = result.totalPages;
			this.render( result.events, append );
		} catch ( e ) {
			console.warn( 'Church Events: list fetch failed.', e );
		}
	};

	ListView.prototype.render = function( events, append ) {
		if ( ! this.output ) return;

		// Remove existing load more button
		var existing = this.output.parentNode.querySelector( '.ce-load-more-wrap' );
		if ( existing ) existing.remove();

		if ( ! append && ! events.length ) {
			this.output.innerHTML = '<div class="ce-no-events">' + cfg.i18n.noEvents + '</div>';
			return;
		}

		var layout   = this.root.dataset.layout || 'grid';
		var columns  = parseInt( this.root.dataset.columns || cfg.gridColumns, 10 );
		var fields   = getEnabledFields( cfg.archiveFields );
		var modal    = this.modal;
		var hover    = this.hover;
		var isAgenda = ( this.viewType === 'list' );

		if ( ! append ) {
			this.output.innerHTML = '';
			_agendaLastDate = null;

			if ( isAgenda ) {
				var agendaList         = document.createElement( 'div' );
				agendaList.className   = 'ce-agenda-list';
				agendaList.dataset.ceList = '1';
				this.output.appendChild( agendaList );
			} else {
				var wrapper            = document.createElement( 'div' );
				wrapper.className      = layout === 'list' ? 'ce-events-list' : 'ce-events-grid';
				wrapper.dataset.ceList = '1';
				if ( layout === 'grid' ) wrapper.style.setProperty( '--ce-columns', columns );
				this.output.appendChild( wrapper );
			}
		}

		var wrapper = this.output.querySelector( '[data-ce-list]' );

		events.forEach( function( event ) {
			if ( isAgenda ) {
				var row        = document.createElement( 'div' );
				row.className  = 'ce-agenda-row' + ( event.event_featured ? ' ce-featured' : '' );
				row.innerHTML  = buildAgendaRow( event );
				row.dataset.id = event.id;

				row.addEventListener( 'click', function() {
					if ( cfg.interaction === 'modal' ) {
						modal && modal.open( event );
					} else if ( event.event_url ) {
						window.location.href = event.event_url;
					}
				} );

				wrapper.appendChild( row );
			} else {
				var card         = document.createElement( 'article' );
				card.className   = 'ce-event-card' + ( event.event_featured ? ' ce-featured' : '' );
				card.innerHTML   = buildCard( event, fields );
				card.dataset.id  = event.id;

				card.addEventListener( 'click', function() {
					if ( cfg.interaction === 'modal' ) {
						modal && modal.open( event );
					} else if ( event.event_url ) {
						window.location.href = event.event_url;
					}
				} );

				wrapper.appendChild( card );
			}
		} );

		// Load more button — suppressed when a hard limit is set
		if ( ! this.limit && this.currentPage < this.totalPages ) {
			var self       = this;
			var wrap       = document.createElement( 'div' );
			wrap.className = 'ce-load-more-wrap';

			var btn         = document.createElement( 'button' );
			btn.className   = 'ce-btn ce-btn-load-more';
			btn.textContent = cfg.i18n.loadMore;
			btn.addEventListener( 'click', function() {
				self.load( self.filters, true );
			} );

			wrap.appendChild( btn );
			this.output.parentNode.appendChild( wrap );
			this.loadMoreBtn = btn;
		}
	};

	// ---------------------------------------------------------------------------
	// Calendar view
	// ---------------------------------------------------------------------------

	function CalendarView( root, modal, hover ) {
		this.root           = root;
		this.modal          = modal;
		this.hover          = hover;
		this.el             = root.querySelector( '#ce-calendar' );
		this.calendar       = null;
		this.lockedSite     = root.dataset.lockedSite     || '';
		this.lockedCategory = root.dataset.lockedCategory || '';
		this.activeCategory = this.lockedCategory;
		this.activeSite     = this.lockedSite;
		this.activeSearch   = '';
		this.activeMonth    = '';
	}

	CalendarView.prototype.init = function() {
		if ( ! this.el || typeof FullCalendar === 'undefined' ) return;
		var self = this;

		this.calendar = new FullCalendar.Calendar( this.el, {
			initialView:     'dayGridMonth',
			eventDisplay:    'block',
			headerToolbar:   { left: '', center: 'title', right: 'prev,next today' },
			height:          'auto',
			firstDay:        ( typeof cfg.calendarFirstDay !== 'undefined' ) ? cfg.calendarFirstDay : 1,
			eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

			events: function( info, successCallback, failureCallback ) {
				self.loadEvents( info, successCallback, failureCallback );
			},

			eventContent: function( arg ) {
				var time  = arg.event.extendedProps.startTime || '';
				var title = arg.event.title;
				return {
					html: '<div class="ce-fc-event">'
						+ ( time ? '<span class="ce-fc-time">' + time + '</span>' : '' )
						+ '<span class="ce-fc-title">' + title + '</span>'
						+ '</div>',
				};
			},

			eventClick: function( info ) {
				var event = info.event.extendedProps.rawEvent;
				if ( ! event ) return;
				if ( cfg.interaction === 'modal' ) {
					self.modal && self.modal.open( event );
				} else if ( event.event_url ) {
					window.location.href = event.event_url;
				}
			},

			eventMouseEnter: function( info ) {
				if ( cfg.hoverPreview && self.hover ) {
					self.hover.show( info.event.extendedProps.rawEvent, info.el );
				}
			},

			eventMouseLeave: function() {
				if ( cfg.hoverPreview && self.hover ) self.hover.hide();
			},
		loading: function( isLoading ) {
			var el = self.el;
			if ( ! el ) return;
			if ( isLoading ) {
				el.classList.add( 'ce-calendar-loading' );
			} else {
				el.classList.remove( 'ce-calendar-loading' );
			}
		},
		} );

		this.calendar.render();
	};

	CalendarView.prototype.loadEvents = async function( info, successCallback, failureCallback ) {
		try {
			var after, before;
			if ( this.activeMonth ) {
				var parts = this.activeMonth.split( '-' );
				if ( parts.length === 2 ) {
					var y = parseInt( parts[0], 10 );
					var m = parseInt( parts[1], 10 );
					after  = y + ( m < 10 ? '0' : '' ) + m + '01';
					var lastDay = new Date( y, m, 0 ).getDate();
					before = y + ( m < 10 ? '0' : '' ) + m + lastDay;
				}
			}
			after  = after  || dateToYMD( info.start );
			before = before || dateToYMD( info.end );
			var events = await fetchEvents( after, before, this.activeCategory, this.activeSite, this.activeSearch );
			var self   = this;
			successCallback( events.map( function( e ) { return self.toFCEvent( e ); } ) );
		} catch ( err ) {
			console.warn( 'Church Events: calendar fetch failed.', err );
			failureCallback( err );
		}
	};

	/**
	 * Pick readable text colour (light/dark) for a hex background.
	 */
	function ceTextColorFor( hex ) {
		var h = ( hex || '' ).replace( '#', '' );
		if ( h.length === 3 ) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
		if ( h.length !== 6 ) return '';
		var r = parseInt( h.substr( 0, 2 ), 16 );
		var g = parseInt( h.substr( 2, 2 ), 16 );
		var b = parseInt( h.substr( 4, 2 ), 16 );
		// Perceived luminance (ITU-R BT.709)
		var lum = ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;
		return lum > 0.6 ? '#1a1a1a' : '#ffffff';
	}

	CalendarView.prototype.toFCEvent = function( event ) {
		var meta   = event.event_meta || {};
		var allDay = isAllDay( meta );
		var color  = ( event.event_categories && event.event_categories[0] && event.event_categories[0].color ) || '';
		var fcEvent = {
			id:    event.id,
			title: decodeEntities( ( event.title && event.title.rendered ) || '' ),
			start: toISO( meta.start_date, allDay ? null : meta.start_time ),
			end:   meta.end_date ? toISO( meta.end_date, allDay ? null : meta.end_time ) : null,
			allDay: allDay,
			extendedProps: {
				rawEvent:  event,
				startTime: allDay ? '' : formatTime( meta.start_time ),
			},
		};

		if ( color ) {
			fcEvent.backgroundColor = color;
			fcEvent.borderColor     = color;
			fcEvent.textColor       = ceTextColorFor( color );
		}

		return fcEvent;
	};

	CalendarView.prototype.setFilters = function( category, site, search, month ) {
		this.activeCategory = this.lockedCategory || category;
		this.activeSite     = this.lockedSite     || site;
		this.activeSearch   = search;
		this.activeMonth    = month || '';
		if ( this.calendar ) {
			if ( month ) {
				var parts = month.split( '-' );
				if ( parts.length === 2 ) {
					this.calendar.gotoDate( parts[0] + '-' + parts[1] + '-01' );
				}
			}
			this.calendar.refetchEvents();
		}
	};

	// ---------------------------------------------------------------------------
	// Main controller
	// ---------------------------------------------------------------------------

	function ChurchEvents( root ) {
		this.root           = root;
		this.modal          = new EventModal( root );
		this.hover          = cfg.hoverPreview ? new HoverPreview( root ) : null;
		this.cards          = null; // CardsView — initialised on demand
		this.list           = null; // AgendaView — initialised on demand
		this.cal            = null;
		this.currentView    = null;
		this.lockedSite     = root.dataset.lockedSite     || '';
		this.lockedCategory = root.dataset.lockedCategory || '';
		this.activeCategory = this.lockedCategory;
		this.activeSite     = this.lockedSite;
		this.activeSearch   = '';
		this.activeDateFrom = '';
		this.activeDateTo   = '';
		this.activeMonth    = '';
		this.searchTimer    = null;
		this.enabledViews   = cfg.enabledViews || [ 'calendar', 'cards' ];

		// Override with what's actually rendered in the DOM — the shortcode may have
		// restricted views via layout= without changing cfg.enabledViews (which is global).
		var domViews = [];
		if ( root.querySelector( '.ce-view--calendar' ) ) domViews.push( 'calendar' );
		if ( root.querySelector( '.ce-view--cards' ) )    domViews.push( 'cards' );
		if ( root.querySelector( '.ce-view--list' ) )     domViews.push( 'list' );
		if ( domViews.length ) this.enabledViews = domViews;
		this.defaultView    = root.dataset.defaultView || cfg.defaultView || this.enabledViews[0];

		// Ensure defaultView is one of the enabled views
		if ( this.enabledViews.indexOf( this.defaultView ) === -1 ) {
			this.defaultView = this.enabledViews[0];
		}

		// Lazily create ListView instances only for the views that exist in the DOM
		if ( this.enabledViews.indexOf( 'cards' ) !== -1 ) {
			this.cards = new ListView( root, this.modal, this.hover, 'cards' );
		}
		if ( this.enabledViews.indexOf( 'list' ) !== -1 ) {
			this.list = new ListView( root, this.modal, this.hover, 'list' );
		}

		this.init();
	}

	ChurchEvents.prototype.init = async function() {
		var catSelect   = this.root.querySelector( '.ce-filter-category' );
		if ( catSelect ) await populateCategoryFilter( catSelect );
		var monthSelect = this.root.querySelector( '.ce-filter-month' );
		if ( monthSelect ) await populateMonthFilter( monthSelect );
		this.bindToolbar();
		this.initViews();
		this.handleResponsive();
	};

	ChurchEvents.prototype.getListFilters = function() {
		var now    = new Date();
		var future = new Date( now.getFullYear() + 2, now.getMonth(), now.getDate() );

		var after, before;

		if ( this.activeMonth ) {
			var parts = this.activeMonth.split( '-' );
			if ( parts.length === 2 ) {
				var y = parseInt( parts[0], 10 );
				var m = parseInt( parts[1], 10 );
				after  = y + ( m < 10 ? '0' : '' ) + m + '01';
				var lastDay = new Date( y, m, 0 ).getDate();
				before = y + ( m < 10 ? '0' : '' ) + m + lastDay;
			}
		}

		after  = after  || this.activeDateFrom || dateToYMD( now );
		before = before || this.activeDateTo   || dateToYMD( future );

		return {
			after:    after,
			before:   before,
			category: this.lockedCategory || this.activeCategory,
			site:     this.lockedSite     || this.activeSite,
			search:   this.activeSearch,
		};
	};

	ChurchEvents.prototype.bindToolbar = function() {
		var self = this;
		var btns = this.root.querySelectorAll( '[data-ce-view]' );

		btns.forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				self.switchView( btn.dataset.ceView );
				btns.forEach( function( b ) {
					b.classList.toggle( 'is-active', b === btn );
					b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' );
				} );
			} );
		} );

		var catSelect = this.root.querySelector( '.ce-filter-category' );
		catSelect && catSelect.addEventListener( 'change', function() {
			if ( self.lockedCategory ) return;
			self.activeCategory = catSelect.value;
			self.applyFilters();
		} );

		var searchInput = this.root.querySelector( '.ce-filter-search' );
		searchInput && searchInput.addEventListener( 'input', function() {
			clearTimeout( self.searchTimer );
			self.searchTimer = setTimeout( function() {
				self.activeSearch = searchInput.value.trim();
				self.applyFilters();
			}, 350 );
		} );

		var dateFrom = this.root.querySelector( '.ce-filter-date-from' );
		dateFrom && dateFrom.addEventListener( 'change', function() {
			self.activeDateFrom = dateFrom.value ? dateFrom.value.replace( /-/g, '' ) : '';
			self.applyFilters();
		} );

		var dateTo = this.root.querySelector( '.ce-filter-date-to' );
		dateTo && dateTo.addEventListener( 'change', function() {
			self.activeDateTo = dateTo.value ? dateTo.value.replace( /-/g, '' ) : '';
			self.applyFilters();
		} );

		var monthSelect = this.root.querySelector( '.ce-filter-month' );
		monthSelect && monthSelect.addEventListener( 'change', function() {
			self.activeMonth = monthSelect.value;
			self.applyFilters();
		} );

		var siteSelect = this.root.querySelector( '.ce-filter-site' );
		siteSelect && siteSelect.addEventListener( 'change', function() {
			if ( self.lockedSite ) return;
			self.activeSite = siteSelect.value;
			self.applyFilters();
		} );
	};

	/**
	 * Activate one view container, deactivate all others.
	 */
	ChurchEvents.prototype._activateViewEl = function( view ) {
		var viewMap = {
			calendar: '.ce-view--calendar',
			cards:    '.ce-view--cards',
			list:     '.ce-view--list',
		};
		var self = this;
		Object.keys( viewMap ).forEach( function( v ) {
			var el = self.root.querySelector( viewMap[ v ] );
			if ( ! el ) return;
			if ( v === view ) {
				el.classList.add( 'is-active' );
			} else {
				el.classList.remove( 'is-active' );
			}
		} );
	};

	ChurchEvents.prototype.initViews = function() {
		this._activateViewEl( this.defaultView );
		this.currentView = this.defaultView;

		if ( this.defaultView === 'calendar' ) {
			this.initCalendar();
		} else if ( this.defaultView === 'cards' ) {
			this.cards && this.cards.load( this.getListFilters(), false );
		} else if ( this.defaultView === 'list' ) {
			this.list && this.list.load( this.getListFilters(), false );
		}
	};

	ChurchEvents.prototype.initCalendar = function() {
		if ( this.cal ) return;
		if ( this.enabledViews.indexOf( 'calendar' ) === -1 ) return;
		this.cal = new CalendarView( this.root, this.modal, this.hover );
		this.cal.init();
	};

	ChurchEvents.prototype.switchView = function( view ) {
		if ( this.enabledViews.indexOf( view ) === -1 ) return;
		this.currentView = view;
		this._activateViewEl( view );

		if ( view === 'calendar' ) {
			this.initCalendar();
			this.cal && this.cal.setFilters( this.activeCategory, this.activeSite, this.activeSearch );
		} else if ( view === 'cards' ) {
			this.cards && this.cards.load( this.getListFilters(), false );
		} else if ( view === 'list' ) {
			this.list && this.list.load( this.getListFilters(), false );
		}
	};

	ChurchEvents.prototype.applyFilters = function() {
		if ( this.currentView === 'calendar' ) {
			this.cal && this.cal.setFilters( this.activeCategory, this.activeSite, this.activeSearch, this.activeMonth );
		} else if ( this.currentView === 'cards' ) {
			this.cards && this.cards.load( this.getListFilters(), false );
		} else if ( this.currentView === 'list' ) {
			this.list && this.list.load( this.getListFilters(), false );
		}
	};

	ChurchEvents.prototype.handleResponsive = function() {
		// Only need to do anything if calendar is one of the rendered views.
		// If the shortcode forced a non-calendar view, that view shows on all
		// screen sizes and there is nothing to swap.
		var hasCalendar = this.enabledViews.indexOf( 'calendar' ) !== -1;
		if ( ! hasCalendar ) return;

		// Non-calendar views that are actually rendered in this embed.
		var nonCalViews = this.enabledViews.filter( function( v ) { return v !== 'calendar'; } );
		if ( ! nonCalViews.length ) return; // Calendar-only embed; no swap needed.

		// Pick the mobile fallback:
		//   1. cfg.mobileView if it exists in THIS embed's rendered views.
		//   2. Otherwise fall back to the first non-calendar rendered view.
		// This means layout="list" with a global mobileView="cards" still shows
		// list on mobile, because cards isn't rendered in that embed.
		var mobileView = ( cfg.mobileView && nonCalViews.indexOf( cfg.mobileView ) !== -1 )
			? cfg.mobileView
			: nonCalViews[0];

		var self           = this;
		var mobileOverride = false;
		var mq             = window.matchMedia( '(max-width: 768px)' );

		function handler( e ) {
			var toggles = self.root.querySelectorAll( '.ce-view-toggle' );
			if ( e.matches ) {
				mobileOverride = true;
				self.switchView( mobileView );
				toggles.forEach( function( el ) { el.style.display = 'none'; } );
			} else {
				toggles.forEach( function( el ) { el.style.display = ''; } );
				if ( mobileOverride ) {
					mobileOverride = false;
					self.switchView( self.defaultView );
				}
			}
		}

		mq.addEventListener( 'change', handler );
		handler( mq );
	};

	// ---------------------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------------------

	function boot() {
		document.querySelectorAll( '[data-ce-root]' ).forEach( function( root ) {
			new ChurchEvents( root );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

} )();