<?php

/**
 * Plugin Name:    WP Inventory
 * Plugin URI:    http://www.wpinventory.com
 * Description:    Manage and display your products just like a shopping cart, but without the cart.
 * Version:        2.4.2
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
				'has_addons'       => false,
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

	// Point the Upgrade CTA to the EDD checkout on wpinventory.com.
	wpim_fs()->add_filter( 'pricing_url', function() {
		return 'https://www.wpinventory.com/wp-inventory-license/';
	} );

	// Skip opt-in modal for users who already consented via the legacy system.
	$wpim_existing = get_option( 'wpim_registered' );
	if ( ! empty( $wpim_existing['email'] ) ) {
		wpim_fs()->skip_connection( null, true );
	}
	unset( $wpim_existing );

	// Signal that SDK was initiated.
	do_action( 'wpim_fs_loaded' );
}

abstract class WPIMConstants {
	const VERSION = '2.4.2';
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

function wp_inventory_activate() {
	update_option( 'wp_inventory_rewrite', TRUE );
	// Remove legacy registration cron job that referenced the now-deleted update_reg_key method.
	wp_clear_scheduled_hook( 'wpim_cron_hook' );
}

function wp_inventory_launch() {
	if ( 0 < version_compare( WPIMConstants::MIN_PHP_VERSION, phpversion() ) ) {
		add_action( 'admin_notices', 'wp_inventory_min_php_version' );

		return;
	}

	define('WPIM_PLUGIN_FILE', plugin_basename(__FILE__));
	require_once 'wpinventory.core.php';

	WPInventoryInit::initialize();
}

function wp_inventory_min_php_version() {
	echo '<div class="notice notice-error"><p><strong>' . __( 'IMPORTANT!', WPIMConstants::LANG ) . '</strong><br>' . sprintf( __( 'Your server is using version %s of PHP, which is over 6 years old, not maintained, and exposes your website to attack.', WPIMConstants::LANG ), phpversion() );
	echo '<br><strong>' . sprintf( __( ' WP Inventory requires version %s or higher, so it is not loaded.', WPIMConstants::LANG ), WPIMConstants::MIN_PHP_VERSION ) . '</strong>';
	echo '<p>' . sprintf( __( 'This is normally easy to correct.  Contact your host provider and ask them to upgrade you to at least PHP version %s', WPIMConstants::LANG ), WPIMConstants::MIN_PHP_VERSION );
	echo '<br>' . __( 'It is very insecure to use this old version of PHP, so we strongly recommend upgrading, even if you choose not to use WP Inventory.', WPIMConstants::LANG ) . '</p>';
	echo '</div>';
}

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
