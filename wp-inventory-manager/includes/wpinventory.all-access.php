<?php
/**
 * All Access promotion for sites running the Freemius SDK.
 *
 * All Access is a Freemius bundle. The SDK has no notion of one on the surfaces it renders:
 * the pricing page shows only this product's own plans, and the add-ons page lists add-ons.
 * So the bundle is never offered anywhere in the plugin, even though it is live and
 * purchasable. This file adds it to the add-ons page.
 *
 * The pricing-page card is handled separately, in js/wpinventory-all-access.js.
 *
 * Loaded only from inside the SDK bootstrap in wpinventory.php, so nothing here runs on a
 * site where the SDK never loads.
 *
 * @package WPInventory
 */

// No direct access allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpim_all_access_url' ) ) {
	/**
	 * The Freemius checkout for the All Access bundle.
	 *
	 * @return string
	 */
	function wpim_all_access_url() {
		return 'https://checkout.freemius.com/plugin/' . WPIM_ALL_ACCESS_BUNDLE_ID . '/plan/' . WPIM_ALL_ACCESS_PLAN_ID . '/';
	}
}

if ( ! function_exists( 'wpim_all_access_addon_count' ) ) {
	/**
	 * How many of this product's add-ons are active on the site.
	 *
	 * The customer's own number, never the size of the catalogue, so it stays true as
	 * add-ons are added or retired. Counts what the SDK can see as activated rather than
	 * what is merely for sale.
	 *
	 * @return int
	 */
	function wpim_all_access_addon_count() {
		if ( ! function_exists( 'wpim_fs' ) ) {
			return 0;
		}

		$fs = wpim_fs();

		if ( ! method_exists( $fs, 'get_addons' ) || ! method_exists( $fs, 'is_addon_activated' ) ) {
			return 0;
		}

		$addons = $fs->get_addons();

		if ( empty( $addons ) || ! is_array( $addons ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $addons as $addon ) {
			if ( isset( $addon->id ) && $fs->is_addon_activated( $addon->id ) ) {
				$count ++;
			}
		}

		return $count;
	}
}

if ( ! function_exists( 'wpim_all_access_holder' ) ) {
	/**
	 * Whether this site already runs on an All Access licence.
	 *
	 * A bundle licence is recognised by its parent_license_id: the licence issued for the
	 * child product carries a link up to the bundle licence that granted it. That is the
	 * same signal the SDK's own account page uses to label a bundle plan.
	 *
	 * @return bool
	 */
	function wpim_all_access_holder() {
		if ( ! function_exists( 'wpim_fs' ) ) {
			return FALSE;
		}

		$fs = wpim_fs();

		if ( ! method_exists( $fs, 'has_active_valid_license' ) || ! $fs->has_active_valid_license() ) {
			return FALSE;
		}

		if ( ! method_exists( $fs, '_get_license' ) ) {
			return FALSE;
		}

		$license = $fs->_get_license();

		return ( is_object( $license ) && ! empty( $license->parent_license_id ) );
	}
}

/**
 * Offer All Access above the add-ons marketplace.
 *
 * Hooked on the SDK's own addons/after_title action, which fires directly under the page
 * heading and above the add-on grid, so the offer sits with the products it bundles.
 *
 * Silent for a site that already holds All Access. Shown to everyone else: on this page a
 * customer is looking at add-on prices, which is exactly the moment the bundle is worth
 * knowing about, and the copy leans harder once they already own some.
 */
wpim_fs()->add_action( 'addons/after_title', function () {
	if ( wpim_all_access_holder() ) {
		return;
	}

	$count = wpim_all_access_addon_count();

	if ( $count > 0 ) {
		$line = ( 1 === $count )
			? sprintf( __( 'You have %d add-on.', 'wpinventory' ), $count )
			: sprintf( __( 'You have %d add-ons.', 'wpinventory' ), $count );
	} else {
		$line = __( 'Buying more than one add-on?', 'wpinventory' );
	}

	$message = sprintf(
		/* translators: %s: price, e.g. $199 */
		__( 'All Access includes every add-on on unlimited sites for %s a year.', 'wpinventory' ),
		WPIM_ALL_ACCESS_PRICE
	);

	echo '<div class="wpim_all_access">';
	echo '<h3>' . esc_html__( 'All Access', 'wpinventory' ) . '</h3>';
	echo '<p>' . esc_html( $line ) . ' ' . esc_html( $message ) . '</p>';
	echo '<p class="wpim_all_access_cta"><a class="button button-primary" href="' . esc_url( wpim_all_access_url() ) . '" target="_blank" rel="noopener">'
	     . esc_html__( 'Get All Access', 'wpinventory' ) . '</a></p>';
	echo '</div>';

	// The add-ons page is a Freemius template and does not load the plugin's admin stylesheet,
	// so the panel carries its own styling rather than depending on one being present.
	echo '<style>
		.wpim_all_access { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2d5faa; border-radius: 3px; padding: 14px 18px; margin: 0 0 20px; max-width: 780px; }
		.wpim_all_access h3 { margin: 0 0 6px; font-size: 15px; line-height: 1.4; }
		.wpim_all_access p { margin: 0 0 10px; font-size: 13px; line-height: 1.5; color: #50575e; }
		.wpim_all_access .wpim_all_access_cta { margin: 0; }
	</style>';
} );
