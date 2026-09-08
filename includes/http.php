<?php
/**
 * Shared HTTP helpers.
 *
 * ce_remote_get_retry() wraps wp_remote_get() with a small, bounded number of
 * retries and a short backoff, so a single transient network blip — a timeout,
 * a connection reset, or a transient non-200 — does not get recorded as a sync
 * failure. This is the first line of defence against the overnight "sync
 * problem" emails: most of those are one slow request while the server is under
 * load, and a retry clears them within the same run.
 *
 * Returns exactly what wp_remote_get() returns: a response array (from the last
 * attempt) on success, or a WP_Error. Callers keep their existing is_wp_error()
 * and response-code checks unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_remote_get() with bounded retries and a short backoff.
 *
 * @param string $url  Request URL.
 * @param array  $args Arguments passed straight through to wp_remote_get().
 * @return array|WP_Error Response array from the last attempt, or WP_Error.
 */
function ce_remote_get_retry( $url, $args = array() ) {
	// Number of RETRIES after the first attempt, and seconds to wait between them.
	$retries = (int) apply_filters( 'ce_http_retries', 2 );
	$backoff = (int) apply_filters( 'ce_http_retry_backoff', 2 );

	$response = null;

	for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
		$response = wp_remote_get( $url, $args );

		$ok = ! is_wp_error( $response )
			&& (int) wp_remote_retrieve_response_code( $response ) === 200;

		// Success, or we've used our last attempt: return whatever we have.
		if ( $ok || $attempt === $retries ) {
			break;
		}

		$why = is_wp_error( $response )
			? $response->get_error_message()
			: 'HTTP ' . wp_remote_retrieve_response_code( $response );

		ce_log( sprintf(
			'HTTP fetch attempt %d/%d failed (%s); retrying in %ds.',
			$attempt + 1,
			$retries + 1,
			$why,
			$backoff
		) );

		if ( $backoff > 0 ) {
			sleep( $backoff );
		}
	}

	return $response;
}
