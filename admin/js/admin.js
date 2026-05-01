/* global jQuery, wp */
jQuery( function ( $ ) {

	// --- Colour pickers ---
	$( '.ce-color-picker' ).wpColorPicker();

	// --- Sortable field lists ---
	$( '.ce-sortable-fields' ).sortable( {
		handle: '.ce-drag-handle',
		axis: 'y',
		placeholder: 'ce-sort-placeholder',
		update: function () {
			// Re-number order inputs after drag
			$( this ).find( '.ce-order-input' ).each( function ( index ) {
				$( this ).val( index + 1 );
			} );
		},
	} );

	// --- Source type show/hide ---
	function toggleSourceRows() {
		var source = $( 'input[name="ce_settings[source_type]"]:checked' ).val();
		$( '.ce-source-row' ).hide();
		$( '.ce-source-' + source ).show();
	}

	toggleSourceRows();
	$( 'input[name="ce_settings[source_type]"]' ).on( 'change', toggleSourceRows );

	// --- Manual sync button ---
	$( '#ce-sync-now' ).on( 'click', function () {
		var $btn    = $( this );
		var $status = $( '#ce-sync-status' );

		$btn.prop( 'disabled', true );
		$status.removeClass( 'success error' ).text( 'Syncing…' );

		$.post(
			window.ajaxurl,
			{
				action: 'ce_manual_sync',
				nonce: ceAdmin.nonce,
			},
			function ( response ) {
				$btn.prop( 'disabled', false );
				if ( response.success ) {
					$status.addClass( 'success' ).text( response.data.message );
				} else {
					$status.addClass( 'error' ).text( response.data.message || 'Sync failed.' );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false );
			$status.addClass( 'error' ).text( 'Request failed. Please try again.' );
		} );
	} );
	// --- Fallback image picker ---
	var ceMediaFrame;
	$( '#ce-select-fallback-image' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( ceMediaFrame ) { ceMediaFrame.open(); return; }
		ceMediaFrame = wp.media( {
			title:    'Select Fallback Image',
			button:   { text: 'Use this image' },
			multiple: false,
		} );
		ceMediaFrame.on( 'select', function () {
			var attachment = ceMediaFrame.state().get( 'selection' ).first().toJSON();
			$( '#ce_fallback_image_id' ).val( attachment.id );
			var wrap = $( '.ce-fallback-image-wrap' );
			wrap.find( 'img' ).remove();
			wrap.prepend( '<img src="' + attachment.url + '" style="max-width:150px;display:block;margin-bottom:8px;border-radius:4px;" />' );
			$( '#ce-select-fallback-image' ).text( 'Change Image' );
			if ( ! $( '#ce-remove-fallback-image' ).length ) {
				$( '#ce-select-fallback-image' ).after( '<button type="button" class="button button-secondary" id="ce-remove-fallback-image" style="margin-left:4px;">Remove</button>' );
				bindRemoveFallback();
			}
		} );
		ceMediaFrame.open();
	} );

	function bindRemoveFallback() {
		$( document ).on( 'click', '#ce-remove-fallback-image', function () {
			$( '#ce_fallback_image_id' ).val( '' );
			$( '.ce-fallback-image-wrap img' ).remove();
			$( '#ce-select-fallback-image' ).text( 'Select Image' );
			$( '#ce-remove-fallback-image' ).remove();
		} );
	}
	bindRemoveFallback();

	// --- Generate sync key ---
	$( '#ce-generate-key' ).on( 'click', function () {
		var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		var key   = '';
		for ( var i = 0; i < 32; i++ ) {
			key += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		$( '#ce_sync_key' ).val( key );
	} );

} );
