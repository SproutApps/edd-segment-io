<?php

/**
 * EDD Segment API Controller
 *
 * @package EDD_Segment
 * @subpackage EDD_Segment EDD Hooks
 */
class EDD_Segment_Hooks extends EDD_Segment_Controller {

	public static function init() {

		// completed purchase
		add_action( 'edd_complete_purchase', array( __CLASS__, 'track_purchase' ), 100 );
		add_action( 'edd_complete_purchase', array( __CLASS__, 'track_purchased_items' ), 100 );
		// Payment status updated
		add_action( 'edd_update_payment_status', array( __CLASS__, 'track_payment_changes' ), 10 , 3 );
		// find old abandoned carts every half hour
		add_action( self::CRON_HOOK, array( __CLASS__, 'find_old_abandoned_carts' ) );

		// Renewal email sent
		add_action( 'edd_sl_send_renewal_reminder', array( __CLASS__, 'edd_sl_renewal_reminder' ), PHP_INT_MAX, 3 );

		// EDD JS Events
		add_action( 'edd_cart_items_after', array( __CLASS__, 'add_edd_cart_js_events' ), 10, 2 );
		add_action( 'edd_before_checkout_cart', array( __CLASS__, 'maybe_add_edd_errors_js_event' ) );
	}

	/**
	 * Create identity and track purchase
	 * @param  int $payment_id
	 * @return null
	 */
	public static function track_purchase( $payment_id ) {

		$user_id = edd_get_payment_user_id( $payment_id );
		$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );

		// Send identity
		$traits = array(
				'name' => edd_email_tag_fullname( $payment_id ),
				'email' => edd_get_payment_user_email( $payment_id ),
				);
		do_action( 'edd_segment_identify', $uid, $traits );

		// Track the purchase event
		$props = array(
				'trans_id' => edd_get_payment_transaction_id( $payment_id ),
				'total' => edd_get_payment_amount( $payment_id ),
				'time' => strtotime( edd_get_payment_completed_date( $payment_id ) ),
			);
		do_action( 'edd_segment_track', $uid, 'Checkout', $props );
	}

	/**
	 * Track each item purchased
	 * @param  int $payment_id
	 * @return null
	 */
	public static function track_purchased_items( $payment_id ) {
		$user_id = edd_get_payment_user_id( $payment_id );
		$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
		$meta = edd_get_payment_meta( $payment_id );

		// Adding an event for each item
		foreach ( $meta['cart_details'] as $key => $item_details ) {
			$item_details = array_merge( $item_details, array( 'time' => time() ) );
			do_action( 'edd_segment_track', $uid, 'Purchase', $item_details );
		}
	}

	/**
	 * Track if a purchase was refunded
	 * @param  int $payment_id
	 * @param  string $new_status
	 * @param  string $old_status
	 * @return
	 */
	public static function track_payment_changes( $payment_id, $new_status, $old_status ) {
		if ( $new_status == 'refunded' ) {
			$user_id = edd_get_payment_user_id( $payment_id );
			$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
			$meta = edd_get_payment_meta( $payment_id );

			// Adding an event for each item
			foreach ( $meta['cart_details'] as $key => $item_details ) {
				do_action( 'edd_segment_track', $uid, 'Refunded Payment', $item_details );
			}
		}
		if ( $new_status == 'abandoned' ) {
			$user_id = edd_get_payment_user_id( $payment_id );
			$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
			$meta = edd_get_payment_meta( $payment_id );
			// Adding an event for each item
			foreach ( $meta['cart_details'] as $key => $item_details ) {
				$item_details = array_merge( $item_details, array( 'time' => time() ) );
				do_action( 'edd_segment_track', $uid, 'Abandoned Payment', $item_details );
			}
		}
		if ( $new_status == 'cancelled' ) {
			$user_id = edd_get_payment_user_id( $payment_id );
			$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
			$meta = edd_get_payment_meta( $payment_id );
			// Adding an event for each item
			foreach ( $meta['cart_details'] as $key => $item_details ) {
				$item_details = array_merge( $item_details, array( 'time' => time() ) );
				do_action( 'edd_segment_track', $uid, 'Cancelled Payment', $item_details );
			}
		}
	}

	/**
	 * this is a filter so make sure to return what's being filtered. maybe later this can be an action
	 * @param  boolean $send
	 * @param  string  $license_id
	 * @param  string  $notice_id
	 * @return $send
	 */
	public static function edd_sl_renewal_reminder( $send = true, $license_id = '', $notice_id = '' ) {
		if ( ! $send ) {
			// renewal not sent
			return $send;
		}
		$item_id = EDD_Software_Licensing::get_download_id( $license_id );
		$payment_id = get_post_meta( $license_id, '_edd_sl_payment_id', true );
		$user_id = edd_get_payment_user_id( $payment_id );
		$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
		$meta = edd_get_payment_meta( $payment_id );
		// Adding an event for each item
		foreach ( $meta['cart_details'] as $key => $item_details ) {
			if ( $item_id === $item_details['id'] ) {
				$item_details = array_merge( $item_details, array( 'time' => time() ) );
				do_action( 'edd_segment_track', $uid, 'License Renewal Sent', $item_details );
			}
		}
		return $send;
	}


	/**
	 * Find old pending carts and consider them abandoned.
	 * @return null
	 */
	function find_old_abandoned_carts() {
		$time = time();
		$thirty_mins_ago = $time - apply_filters( 'find_old_abandoned_carts_mins_ago', 1800 ); // a half hour

		$args = array(
			'status' => 'pending',
			'start_date' => $thirty_mins_ago,
			'end_date' => $time,
		);

		$pending_payments  = new EDD_Payments_Query( $args );
		$payments = $pending_payments->get_payments();
		foreach ( $payments as $payment ) {
			$payment_id = $payment->ID;
			$user_id = edd_get_payment_user_id( $payment_id );
			$uid = EDD_Segment_Identity::get_uid_from_user_id( $user_id );
			$meta = edd_get_payment_meta( $payment_id );
			// Adding an event for each item
			foreach ( $meta['cart_details'] as $key => $item_details ) {
				$item_details = array_merge( $item_details, array( 'time' => time() ) );
				do_action( 'edd_segment_track', $uid, 'Abandoned Cart', $item_details );
			}
		}
	}

	/////////////////
	// JS Tracking //
	/////////////////

	public static function add_edd_cart_js_events() {
		$cart_items = edd_get_cart_contents();
		if ( $cart_items ) {
			foreach ( $cart_items as $key => $item ) {
				do_action( 'cio_js_track', 'Product in Cart', array(
					'item_name' => get_the_title( $item['id'] ),
					'options' => $item['options'],
					'time' => time(),
				) );
			}
		}
		do_action( 'cio_js_track', 'Cart', array(
			'total' => edd_get_cart_total(),
			'time' => time(),
		) );
	}

	public static function maybe_add_edd_errors_js_event() {
		$errors = edd_get_errors();
		if ( $errors ) {
			foreach ( $errors as $error_id => $error ) {
				do_action( 'cio_js_track', 'EDD Error', array(
					'error_id' => $error_id,
					'error' => $error,
					'time' => time(),
				) );
			}
		}
	}
}
