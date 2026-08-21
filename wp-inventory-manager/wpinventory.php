<?php

/**
 * Plugin Name:    WP Inventory
 * Plugin URI:    http://www.wpinventory.com
 * Description:    Manage and display your products just like a shopping cart, but without the cart.
 * Version:        2.5.3
 * Author:        WP Inventory Manager
 * Author URI:    http://www.wpinventory.com/
 * Text Domain:    wpinventory
 *
 * ------------------------------------------------------------------------
 * Copyright 2009-2021 WP Inventory Manager, LLC
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

// No direct access allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stand down when WP Inventory Pro is also active.
 *
 * Free and Pro are separate plugins in separate folders that both declare
 * wp_inventory_activate(), wp_inventory_launch(), WPIMConstants and WPIMCore. With both active
 * the second to load fatals the site:
 *
 *     PHP Fatal error: Cannot redeclare wp_inventory_activate()
 *
 * That is reachable now that the plugin sells Pro through Freemius — a customer who buys and
 * installs Pro without deactivating the free version first would white-screen straight after
 * paying. Plugins load alphabetically, so this file runs before Pro and can bail out cleanly,
 * leaving Pro (the paid product) to load normally.
 *
 * is_plugin_active() lives in wp-admin/includes/plugin.php and is not loaded this early, so
 * read the options directly — the same approach the add-ons use to detect their parent.
 */
if ( ! function_exists( 'wpim_free_is_pro_active' ) ) {
	function wpim_free_is_pro_active() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $basename ) {
			if ( 0 === strpos( $basename, 'wp-inventory-manager-pro/' ) ) {
				return TRUE;
			}
		}

		return FALSE;
	}
}

if ( wpim_free_is_pro_active() ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WP Inventory', 'wpinventory' ) . '</strong> — '
		     . esc_html__( 'the free version has stood down because WP Inventory Pro is active. Pro includes everything in the free plugin, so nothing is missing. You can safely deactivate the free version.', 'wpinventory' )
		     . '</p></div>';
	} );

	return;
}

/**
 * Catch-all: another copy of WP Inventory has already declared the core symbols.
 *
 * The check above only recognises Pro in its expected folder. A duplicate under any other name
 * — a manual upload, a renamed folder, a leftover from testing — fatals just the same. Bailing
 * on the symbols themselves covers every case rather than the one we can name.
 */
if ( function_exists( 'wp_inventory_activate' ) || class_exists( 'WPIMConstants', FALSE ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WP Inventory', 'wpinventory' ) . '</strong> — '
		     . esc_html__( 'this copy has stood down because another copy of WP Inventory is already active. Keep one and deactivate the rest.', 'wpinventory' )
		     . '</p></div>';
	} );

	return;
}

if ( ! function_exists( 'wpim_fs' ) ) {
	// Create a helper function for easy SDK access.
	function wpim_fs() {
		global $wpim_fs;

		if ( ! isset( $wpim_fs ) ) {
			// Include Freemius SDK.
			require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

			$wpim_fs = fs_dynamic_init( array(
				'id'               => '31646',
				'slug'             => 'wp-inventory-manager',
				'type'             => 'plugin',
				'public_key'       => 'pk_4043b83f03bdb478d66c2531e0877',
				'is_premium'       => false,
				// The free plugin is the upgrade funnel: wordpress.org users buy Pro and the
				// add-on suite through Freemius. has_addons lets the SDK present the add-ons
				// for purchase, so the catalogue comes from the Freemius dashboard rather than
				// the hardcoded get_add_ons() list, which has already drifted out of date.
				'has_addons'       => true,
				'has_paid_plans'   => true,
				'is_org_compliant' => true,
				'menu'             => array(
					'slug'       => 'wpinventory',
					'first-path' => 'admin.php?page=wpinventory',
					'account'    => false,
					'contact'    => true,
					'support'    => true,
					'pricing'    => true,
				),
			) );
		}

		return $wpim_fs;
	}

	// Init Freemius.
	wpim_fs();

	/**
	 * The Upgrade CTA now opens Freemius checkout.
	 *
	 * It previously redirected to https://www.wpinventory.com/wp-inventory-license/ (the EDD
	 * licence page), which meant the wordpress.org funnel produced EDD licences and Freemius
	 * never saw a sale — ~141 installs and 2 licences. EDD and Freemius run in parallel: the
	 * website sells through EDD, the plugin sells through Freemius. Removing the filter lets
	 * the SDK use its own pricing page, so prices stay in sync with the dashboard.
	 *
	 * Paid build on Freemius is wp-inventory-manager-pro.zip (verified in the dashboard, both
	 * 3.1.8 and 3.1.9 released), so a buyer has something real to download.
	 */

	/**
	 * One shop per site.
	 *
	 * The Add Ons grid and the promo pages sell through the website checkout off a hardcoded
	 * add-on list. A wordpress.org user buys through Freemius, whose catalogue is authoritative
	 * and stays in sync with the dashboard — so they get Freemius's pricing and add-ons pages
	 * and none of the website surfaces. Showing both would put two prices for the same add-on
	 * in front of one person.
	 *
	 * EDD customers are unaffected: they run Pro, where the SDK does not load at all while their
	 * licence is valid, so they keep the custom Add Ons page and its reworked design.
	 */
	add_filter( 'wpim_suppress_admin_menu_add_ons', '__return_true' );
	add_filter( 'wpim_suppress_promos', '__return_true' );

	/**
	 * Send the suppressed pages to the Freemius marketplace instead of a 403.
	 *
	 * Unregistering a submenu means WordPress answers "Sorry, you are not allowed to access this
	 * page" for it. Anyone with a bookmark, or following a link from the docs or an older email,
	 * hits that dead end — the pages existed and worked before the SDK was switched on. Redirect
	 * to Freemius's add-ons page, which is where that intent now leads.
	 *
	 * Hooked on both admin_init and admin_page_access_denied: the former catches the request
	 * before WordPress rejects it, the latter is the backstop if core's ordering ever changes.
	 */
	$wpim_fs_redirect_suppressed = function () {
		if ( empty( $_GET['page'] ) || ! function_exists( 'wpim_fs' ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( 'wpim_manage_add_ons' !== $page && 0 !== strpos( $page, 'wpim_promote_' ) ) {
			return;
		}

		if ( ! method_exists( wpim_fs(), 'get_addons_url' ) ) {
			return;
		}

		// Until the opt-in has been answered Freemius answers 403 for its own pages — add-ons,
		// pricing and account alike — exactly as WordPress does for the suppressed ones. Sending
		// the user there would swap one dead end for another, so route them to the opt-in screen,
		// which is the thing that actually has to be answered before any marketplace page renders.
		// Answering it either way clears this: registering or skipping both leave activation mode.
		if ( method_exists( wpim_fs(), 'is_activation_mode' ) && wpim_fs()->is_activation_mode() ) {
			$wpim_optin_url = method_exists( wpim_fs(), 'get_activation_url' )
				? wpim_fs()->get_activation_url()
				: admin_url( 'admin.php?page=' . WPIMConstants::MENU );

			wp_safe_redirect( $wpim_optin_url, 302 );
			exit;
		}

		wp_safe_redirect( wpim_fs()->get_addons_url(), 302 );
		exit;
	};

	add_action( 'admin_init', $wpim_fs_redirect_suppressed );
	add_action( 'admin_page_access_denied', $wpim_fs_redirect_suppressed );
	unset( $wpim_fs_redirect_suppressed );

	// Skip opt-in modal for users who already consented via the legacy system.
	$wpim_existing = get_option( 'wpim_registered' );
	if ( ! empty( $wpim_existing['email'] ) ) {
		wpim_fs()->skip_connection( null, true );
	}
	unset( $wpim_existing );

	// Signal that SDK was initiated.
	do_action( 'wpim_fs_loaded' );
}

// Declared conditionally: an unconditional top-level declaration is bound when
// the file is compiled, which fatals before any runtime guard above can run.
if ( ! class_exists( 'WPIMConstants', FALSE ) ) :
abstract class WPIMConstants {
	const VERSION = '2.5.3';
	const MIN_PHP_VERSION = '5.6';
	const SHORTCODE = 'wpinventory';
	const SETTINGS = 'wpinventory_settings';
	const SETTINGS_GROUP = 'wpinventory_settings_group';
	const VIEWFOLDER = 'wpinventory/views/';
	const LANG = 'wpinventory';
	const MENU = 'wpinventory';
	const NONCE_ACTION = 'wpinventory_&%k2s$%#!@#8vY^';
	const SUPPORT_CLASS = 'WPIMSupport';
	const USE_DATATABLES = 2;
}
endif;

// Declared conditionally: an unconditional top-level declaration is bound when
// the file is compiled, which fatals before any runtime guard above can run.
if ( ! function_exists( 'wp_inventory_activate' ) ) :
function wp_inventory_activate() {
	update_option( 'wp_inventory_rewrite', TRUE );
	// Remove legacy registration cron job that referenced the now-deleted update_reg_key method.
	wp_clear_scheduled_hook( 'wpim_cron_hook' );
}
endif;

// Declared conditionally: an unconditional top-level declaration is bound when
// the file is compiled, which fatals before any runtime guard above can run.
if ( ! function_exists( 'wp_inventory_launch' ) ) :
function wp_inventory_launch() {
	if ( 0 < version_compare( WPIMConstants::MIN_PHP_VERSION, phpversion() ) ) {
		add_action( 'admin_notices', 'wp_inventory_min_php_version' );

		return;
	}

	define('WPIM_PLUGIN_FILE', plugin_basename(__FILE__));
	require_once 'wpinventory.core.php';

	WPInventoryInit::initialize();
}
endif;

// Declared conditionally: an unconditional top-level declaration is bound when
// the file is compiled, which fatals before any runtime guard above can run.
if ( ! function_exists( 'wp_inventory_min_php_version' ) ) :
function wp_inventory_min_php_version() {
	echo '<div class="notice notice-error"><p><strong>' . __( 'IMPORTANT!', WPIMConstants::LANG ) . '</strong><br>' . sprintf( __( 'Your server is using version %s of PHP, which is over 6 years old, not maintained, and exposes your website to attack.', WPIMConstants::LANG ), phpversion() );
	echo '<br><strong>' . sprintf( __( ' WP Inventory requires version %s or higher, so it is not loaded.', WPIMConstants::LANG ), WPIMConstants::MIN_PHP_VERSION ) . '</strong>';
	echo '<p>' . sprintf( __( 'This is normally easy to correct.  Contact your host provider and ask them to upgrade you to at least PHP version %s', WPIMConstants::LANG ), WPIMConstants::MIN_PHP_VERSION );
	echo '<br>' . __( 'It is very insecure to use this old version of PHP, so we strongly recommend upgrading, even if you choose not to use WP Inventory.', WPIMConstants::LANG ) . '</p>';
	echo '</div>';
}
endif;

// actions necessary on activation
register_activation_hook( __FILE__, 'wp_inventory_activate' );

// Clean up legacy cron job on every load (safe to call repeatedly — no-op if not scheduled).
add_action( 'plugins_loaded', function() {
	if ( wp_next_scheduled( 'wpim_cron_hook' ) ) {
		wp_clear_scheduled_hook( 'wpim_cron_hook' );
	}
}, 1 );

// Instantiate the class
add_action( 'plugins_loaded', 'wp_inventory_launch' );
