/**
 * Church Events — Frontend JS
 *
 * Handles:
 *  - FullCalendar month grid initialisation
 *  - List/grid view rendering
 *  - Category filter population (paginated REST fetch)
 *  - Text search + category filtering
 *  - View toggle (calendar <-> list) with responsive auto-switch at 768px
 *  - Filter persistence across calendar month navigation
 *  - Event detail modal
 *  - Hover preview popover
 *
 * All config passed from PHP via ceConfig (wp_localize_script).
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

	async function fetchEvents( after, before, category, search ) {
		let url = cfg.restUrl + '?cal_after=' + after + '&cal_before=' + before + '&per_page=100';
		if ( category ) url += '&event_category=' + encodeURIComponent( category );
		if ( search )   url += '&event_search='   + encodeURIComponent( search );
		const response = await fetch( url, { headers: { 'X-WP-Nonce': cfg.restNonce } } );
		if ( ! response.ok ) return [];
		return response.json();
	}

	// ---------------------------------------------------------------------------
	// Category filter
	// ---------------------------------------------------------------------------

	async function populateCategoryFilter( select ) {
		try {
			const base  = cfg.restUrl.replace( /\/events\/?$/, '' );
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
				const de = ( meta.end_date && meta.end_date !== meta.start_date ) ? ' \u2013 ' + formatDate( meta.end_date ) : '';
				return '<span class="ce-card-meta-item ce-meta-date">' + ds + de + '</span>';
			}

			case 'time': {
				if ( isAllDay( meta ) ) return '<span class="ce-card-meta-item ce-meta-time">' + cfg.i18n.allDay + '</span>';
				const st = formatTime( meta.start_time );
				const et = meta.end_time ? formatTime( meta.end_time ) : '';
				const ts = et ? st + ' \u2013 ' + et : st;
				if ( ! ts ) return '';
				return '<span class="ce-card-meta-item ce-meta-time">' + ts + '</span>';
			}

			case 'location':
				if ( ! meta.location ) return '';
				return '<span class="ce-card-meta-item ce-meta-location">' + meta.location + '</span>';

			case 'address':
				if ( ! meta.address ) return '';
				return '<span class="ce-card-meta-item ce-meta-address">' + meta.address + '</span>';

			case 'excerpt': {
				if ( context === 'detail' ) return '';
				const ex = event.excerpt && event.excerpt.rendered
					? event.excerpt.rendered.replace( /<[^>]+>/g, '' ).trim()
					: '';
				if ( ! ex ) return '';
				return '<p class="ce-card-excerpt">' + ex + '</p>';
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
						return '<span class="ce-category-tag">' + decodeEntities( c.name ) + '</span>';
					} ).join( '' ) +
				'</div>';
			}

			case 'booking_link': {
				if ( ! meta.booking_url ) return '';
				const lbl = meta.booking_text || cfg.i18n.bookNow;
				return '<div class="ce-card-booking"><a href="' + meta.booking_url + '" class="ce-btn ce-btn-primary" target="_blank" rel="noopener noreferrer">' + lbl + '</a></div>';
			}

			default:
				return '';
		}
	}

	function buildCard( event, fields ) {
		const metaFields = [ 'date', 'time', 'location', 'address' ];
		let html = '', metaOpen = false;

		if ( fields.indexOf( 'featured_image' ) !== -1 ) {
			html += renderField( 'featured_image', event, 'archive' );
		}

		html += '<div class="ce-card-body">';

		fields.forEach( function( key ) {
			if ( key === 'featured_image' ) return;
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
			html += '<div class="ce-modal-image"><img src="' + event.featured_image_url + '" alt="' + decodeEntities( ( event.title && event.title.rendered ) || '' ) + '" /></div>';
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

		if ( cfg.interaction === 'page' && event.event_url ) {
			html += '<div class="ce-modal-footer"><a href="' + event.event_url + '" class="ce-btn ce-btn-primary">' + cfg.i18n.viewDetails + '</a></div>';
		}

		html += '</div>';
		return html;
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
		this.metaEl.textContent  = [ formatDate( meta.start_date ), time, meta.location ].filter( Boolean ).join( ' \u00B7 ' );
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
		this.el.style.position = 'absolute';
		this.el.style.top  = ( rect.bottom + window.scrollY + 8 ) + 'px';
		this.el.style.left = rect.left + 'px';
	};

	// ---------------------------------------------------------------------------
	// List view
	// ---------------------------------------------------------------------------

	function ListView( root, modal, hover ) {
		this.root   = root;
		this.modal  = modal;
		this.hover  = hover;
		this.output = root.querySelector( '.ce-view--list .ce-events-output' );
	}

	ListView.prototype.showLoading = function() {
		if ( this.output ) this.output.innerHTML = '<div class="ce-loading">' + cfg.i18n.loading + '</div>';
	};

	ListView.prototype.render = function( events ) {
		if ( ! this.output ) return;

		if ( ! events.length ) {
			this.output.innerHTML = '<div class="ce-no-events">' + cfg.i18n.noEvents + '</div>';
			return;
		}

		var layout  = this.root.dataset.layout || 'grid';
		var columns = parseInt( this.root.dataset.columns || cfg.gridColumns, 10 );
		var fields  = getEnabledFields( cfg.archiveFields );
		var modal   = this.modal;
		var hover   = this.hover;

		var wrapper     = document.createElement( 'div' );
		wrapper.className = layout === 'list' ? 'ce-events-list' : 'ce-events-grid';
		if ( layout === 'grid' ) wrapper.style.setProperty( '--ce-columns', columns );

		events.forEach( function( event ) {
			var card      = document.createElement( 'article' );
			card.className  = 'ce-event-card';
			card.innerHTML  = buildCard( event, fields );
			card.dataset.id = event.id;

			card.addEventListener( 'click', function() {
				if ( cfg.interaction === 'modal' ) {
					modal && modal.open( event );
				} else if ( event.event_url ) {
					window.location.href = event.event_url;
				}
			} );

			if ( cfg.hoverPreview && hover ) {
				card.addEventListener( 'mouseenter', function() { hover.show( event, card ); } );
				card.addEventListener( 'mouseleave', function() { hover.hide(); } );
			}

			wrapper.appendChild( card );
		} );

		this.output.innerHTML = '';
		this.output.appendChild( wrapper );
	};

	// ---------------------------------------------------------------------------
	// Calendar view
	// ---------------------------------------------------------------------------

	function CalendarView( root, modal, hover ) {
		this.root            = root;
		this.modal           = modal;
		this.hover           = hover;
		this.el              = root.querySelector( '#ce-calendar' );
		this.calendar        = null;
		this.activeCategory  = '';
		this.activeSearch    = '';
	}

	CalendarView.prototype.init = function() {
		if ( ! this.el || typeof FullCalendar === 'undefined' ) return;

		var self = this;

		this.calendar = new FullCalendar.Calendar( this.el, {
			initialView:   'dayGridMonth',
			headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
			height:        'auto',
			firstDay:      1,
			eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

			events: function( info, successCallback, failureCallback ) {
				self.loadEvents( info, successCallback, failureCallback );
			},

			eventContent: function( arg ) {
				var time  = arg.event.extendedProps.startTime || '';
				var title = arg.event.title;
				return {
					html: '<div class="ce-fc-event">' +
						( time ? '<span class="ce-fc-time">' + time + '</span>' : '' ) +
						'<span class="ce-fc-title">' + title + '</span>' +
					'</div>',
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
		} );

		this.calendar.render();
	};

	CalendarView.prototype.loadEvents = async function( info, successCallback, failureCallback ) {
		try {
			var after  = dateToYMD( info.start );
			var before = dateToYMD( info.end );
			var events = await fetchEvents( after, before, this.activeCategory, this.activeSearch );
			successCallback( events.map( function( e ) { return this.toFCEvent( e ); }, this ) );
		} catch ( err ) {
			console.warn( 'Church Events: calendar fetch failed.', err );
			failureCallback( err );
		}
	};

	CalendarView.prototype.toFCEvent = function( event ) {
		var meta   = event.event_meta || {};
		var allDay = isAllDay( meta );
		return {
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
	};

	CalendarView.prototype.setFilters = function( category, search ) {
		this.activeCategory = category;
		this.activeSearch   = search;
		this.calendar && this.calendar.refetchEvents();
	};

	// ---------------------------------------------------------------------------
	// Main controller
	// ---------------------------------------------------------------------------

	function ChurchEvents( root ) {
		this.root           = root;
		this.modal          = new EventModal( root );
		this.hover          = cfg.hoverPreview ? new HoverPreview( root ) : null;
		this.list           = new ListView( root, this.modal, this.hover );
		this.cal            = null;
		this.currentView    = 'calendar';
		this.activeCategory = '';
		this.activeSearch   = '';
		this.searchTimer    = null;
		this.defaultView    = root.dataset.defaultView || cfg.defaultView || 'toggle';

		this.init();
	}

	ChurchEvents.prototype.init = async function() {
		var select = this.root.querySelector( '.ce-filter-category' );
		if ( select ) await populateCategoryFilter( select );
		this.bindToolbar();
		this.initViews();
		this.handleResponsive();
	};

	ChurchEvents.prototype.bindToolbar = function() {
		var self     = this;
		var btns     = this.root.querySelectorAll( '[data-ce-view]' );

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
	};

	ChurchEvents.prototype.initViews = function() {
		var calView  = this.root.querySelector( '.ce-view--calendar' );
		var listView = this.root.querySelector( '.ce-view--list' );

		if ( this.defaultView === 'list' ) {
			calView  && calView.classList.remove( 'is-active' );
			listView && listView.classList.add( 'is-active' );
			this.currentView = 'list';
			this.loadListView();
		} else {
			calView  && calView.classList.add( 'is-active' );
			listView && listView.classList.remove( 'is-active' );
			this.currentView = 'calendar';
			this.initCalendar();
		}
	};

	ChurchEvents.prototype.initCalendar = function() {
		if ( this.cal ) return;
		this.cal = new CalendarView( this.root, this.modal, this.hover );
		this.cal.init();
	};

	ChurchEvents.prototype.switchView = function( view ) {
		var calView  = this.root.querySelector( '.ce-view--calendar' );
		var listView = this.root.querySelector( '.ce-view--list' );
		this.currentView = view;

		if ( view === 'calendar' ) {
			listView && listView.classList.remove( 'is-active' );
			calView  && calView.classList.add( 'is-active' );
			this.initCalendar();
			this.cal && this.cal.setFilters( this.activeCategory, this.activeSearch );
		} else {
			calView  && calView.classList.remove( 'is-active' );
			listView && listView.classList.add( 'is-active' );
			this.loadListView();
		}
	};

	ChurchEvents.prototype.loadListView = async function() {
		this.list.showLoading();
		var now    = new Date();
		var after  = dateToYMD( now );
		var future = new Date( now.getFullYear(), now.getMonth() + 3, 1 );
		var before = dateToYMD( future );
		var events = await fetchEvents( after, before, this.activeCategory, this.activeSearch );
		this.list.render( events );
	};

	ChurchEvents.prototype.applyFilters = function() {
		if ( this.currentView === 'calendar' ) {
			this.cal && this.cal.setFilters( this.activeCategory, this.activeSearch );
		} else {
			this.loadListView();
		}
	};

	ChurchEvents.prototype.handleResponsive = function() {
		if ( this.defaultView === 'list' ) return;
		var self = this;
		var mq   = window.matchMedia( '(max-width: 768px)' );

		function handler( e ) {
			var toggles = self.root.querySelectorAll( '.ce-view-toggle' );
			if ( e.matches ) {
				self.switchView( 'list' );
				toggles.forEach( function( el ) { el.style.display = 'none'; } );
			} else {
				toggles.forEach( function( el ) { el.style.display = ''; } );
				if ( self.defaultView !== 'list' ) self.switchView( 'calendar' );
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
