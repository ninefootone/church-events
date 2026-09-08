<?php
/**
 * Sync health monitor.
 *
 * Surfaces a WP Admin notice — and optionally emails a hardcoded address —
 * when the last event sync failed or has gone stale. Read-only with respect
 * to the import process: it reports on the 'ce_last_sync_status' option that
 * the importers already write, and never touches the import itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hardcoded alert recipient. Set to your address.
 * Leave empty ('') to disable email and rely on the admin notice only.
 */
define( 'CE_SYNC_ALERT_EMAIL', 'jon@ninefootone.co.uk' );

/**
 * How many sync intervals may pass before a sync is considered stale.
 */
function ce_sync_stale_multiplier() {
	return (int) apply_filters( 'ce_sync_stale_multiplier', 3 );
}

/**
 * How many consecutive failed syncs before an email is sent.
 *
 * With an hourly sync, the default of 3 means roughly three hours of
 * sustained failure before you are alerted - long enough that a single
 * transient blip, which the next run clears, never reaches you.
 */
function ce_sync_fail_threshold() {
	return (int) apply_filters( 'ce_sync_fail_threshold', 3 );
}

/**
 * Map a WP-Cron schedule slug to its length in seconds.
 */
function ce_interval_seconds( $slug ) {
	$schedules = wp_get_schedules();
	if ( isset( $schedules[ $slug ]['interval'] ) ) {
		return (int) $schedules[ $slug ]['interval'];
	}
	return HOUR_IN_SECONDS;
}

/**
 * Evaluate sync health.
 *
 * @return array { state: 'ok'|'error'|'stale'|'never', message: string }
 */
function ce_sync_health() {
	$status = get_option( 'ce_last_sync_status', false );

	if ( empty( $status ) || empty( $status['time'] ) ) {
		return array( 'state' => 'never', 'message' => __( 'No event sync has run yet.', 'church-events' ) );
	}

	if ( isset( $status['status'] ) && $status['status'] === 'error' ) {
		return array(
			'state'   => 'error',
			'message' => isset( $status['message'] ) ? $status['message'] : __( 'The last sync reported an error.', 'church-events' ),
		);
	}

	$last_ts = strtotime( $status['time'] );
	$age     = current_time( 'timestamp' ) - $last_ts;
	$grace   = ce_interval_seconds( ce_get_option( 'sync_interval', 'hourly' ) ) * ce_sync_stale_multiplier();

	if ( $age > $grace ) {
		return array(
			'state'   => 'stale',
			'message' => sprintf(
				/* translators: %s = human-readable time difference */
				__( 'The last successful sync was %s ago.', 'church-events' ),
				human_time_diff( $last_ts, current_time( 'timestamp' ) )
			),
		);
	}

	return array( 'state' => 'ok', 'message' => '' );
}

/**
 * Admin notice — always available, needs no mail layer.
 */
function ce_sync_health_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$health = ce_sync_health();

	if ( $health['state'] === 'ok' || $health['state'] === 'never' ) {
		return;
	}

	printf(
		'<div class="notice %1$s"><p><strong>%2$s</strong> %3$s</p></div>',
		esc_attr( $health['state'] === 'error' ? 'notice-error' : 'notice-warning' ),
		esc_html__( 'Church Events sync:', 'church-events' ),
		esc_html( $health['message'] )
	);
}
add_action( 'admin_notices', 'ce_sync_health_notice' );

/**
 * Email alert — failures only, one email per failure episode.
 *
 * Dedupe: 'ce_sync_alert_state' records the state we last emailed about.
 * We email only when entering a bad state we haven't already reported, and
 * reset to 'ok' on recovery so the next failure notifies again. No email is
 * sent on recovery itself (failures-only, per configuration).
 */
function ce_sync_check_and_alert() {
	$recipient = apply_filters( 'ce_sync_alert_email', CE_SYNC_ALERT_EMAIL );

	if ( empty( $recipient ) || ! is_email( $recipient ) ) {
		return; // No valid address — admin notice still covers it.
	}

	$health    = ce_sync_health();
	$last_sent = get_option( 'ce_sync_alert_state', 'ok' );

	// Healthy or brand-new: clear prior alert so a future failure re-notifies.
	if ( $health['state'] === 'ok' || $health['state'] === 'never' ) {
		if ( $last_sent !== 'ok' ) {
			update_option( 'ce_sync_alert_state', 'ok' );
		}
		return;
	}

	// Already emailed for this episode.
	if ( $last_sent === $health['state'] ) {
		return;
	}

	$fail_streak = (int) get_option( 'ce_sync_fail_streak', 0 );

	// Transient failures self-heal on the next run, so hold off on an 'error'
	// email until failures have persisted across several consecutive syncs.
	// 'stale' is exempt: it already means data has gone un-refreshed for 3+
	// intervals (e.g. cron has stopped), which is worth knowing immediately.
	if ( $health['state'] === 'error' && $fail_streak < ce_sync_fail_threshold() ) {
		return;
	}

	$site    = wp_parse_url( home_url(), PHP_URL_HOST );
	$subject = sprintf( '[Church Events] Sync %1$s on %2$s', $health['state'], $site );

	$last_success = get_option( 'ce_last_success_time', '' );

	$body  = "The Church Events plugin has detected a sync problem.\n\n";
	$body .= 'Site: ' . home_url() . "\n";
	$body .= 'Status: ' . $health['state'] . "\n";
	$body .= 'Detail: ' . $health['message'] . "\n";
	if ( $health['state'] === 'error' ) {
		$body .= 'Consecutive failed syncs: ' . $fail_streak . "\n";
	}
	if ( $last_success ) {
		$body .= sprintf( "Last successful sync: %s (%s ago)\n", $last_success, human_time_diff( strtotime( $last_success ), current_time( 'timestamp' ) ) );
	} else {
		$body .= "Last successful sync: none recorded\n";
	}
	$body .= "\nYou will not be emailed again about this issue until it recovers and then fails again.\n";

	$sent = wp_mail( $recipient, $subject, $body );

	// Only record the episode if the mail layer accepted it. If wp_mail()
	// fails (no SMTP), we leave the state unset so a later run can retry.
	if ( $sent ) {
		update_option( 'ce_sync_alert_state', $health['state'] );
	}
}

// Fire right after each import (priority 20 = after the importer's default 10).
add_action( 'ce_churchsuite_sync', 'ce_sync_check_and_alert', 20 );
add_action( 'ce_google_sync',      'ce_sync_check_and_alert', 20 );
add_action( 'ce_manual_sync',      'ce_sync_check_and_alert', 20 );

// Catch stale state between runs, and reset on recovery, whenever admin loads.
add_action( 'admin_init', 'ce_sync_check_and_alert' );