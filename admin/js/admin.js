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
} );
