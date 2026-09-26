<?php

class WPIMPromo extends WPIMCore {
	private $plugins;
	private $promote = [];
	private $promotion_shown = FALSE;
	private $can_dismiss = FALSE;
	private $this_promo = '';
	private $dismissed = '';
	private $TEST_MODE = FALSE;

	/**
	 * TRUE when this site buys through Freemius rather than the website checkout.
	 *
	 * @var bool
	 */
	private $sell_through_freemius = FALSE;

	public function __construct() {
		/**
		 * Turns every surface off: promo pages, inline teasers and dismissal notices.
		 * Nothing in this plugin sets it any more; it stays for anyone who hooked it.
		 */
		if ( apply_filters( 'wpim_suppress_promos', FALSE ) ) {
			return;
		}

		/**
		 * One shop per site. The promo pages sell through the website checkout, so a site that
		 * buys through Freemius never gets them. The inline teasers (empty inventory, item form,
		 * Labels screen) still render there, carry no price, and open the add-on in Freemius's
		 * own Add-Ons page instead. Set from the Freemius bootstrap block in wpinventory.php,
		 * which only runs when the SDK actually initialises.
		 */
		$this->sell_through_freemius = (bool) apply_filters( 'wpim_promos_sell_through_freemius', FALSE );

		$this->set_up_promotions();

		add_action( 'admin_notices', [ $this, 'dismissal_notices' ] );
		// Always hooked: it also records "No thanks, hide this" clicks from the inline teasers.
		add_action( 'wpim_admin_menu', [ $this, 'wpim_admin_menu' ] );

		if ( ! $this->sell_through_freemius ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
			add_action( 'admin_footer', [ $this, 'admin_footer' ] );
		}

		add_action( 'wpim_admin_items_empty_state', [ $this, 'empty_state_import_teaser' ] );
		add_action( 'wpim_admin_edit_form_after_quantity', [ $this, 'edit_form_locations_teaser' ] );
		add_action( 'wpim_admin_edit_form_end', [ $this, 'edit_form_custom_field_teaser' ] );
		add_filter( 'wpim_manage_labels_notices', [ $this, 'labels_custom_field_teaser' ] );
		add_filter( 'wpim_manage_display_notices', [ $this, 'display_advanced_search_teaser' ] );
		add_action( 'wpim_edit_settings_reserve', [ $this, 'settings_reserve_cart_teaser' ] );
		add_action( 'wpim_edit_settings_notifications', [ $this, 'settings_notifications_teaser' ] );
	}

	/**
	 * Running out of fields happens while filling the form in, so that is where the
	 * add-on that adds fields is worth mentioning.
	 * Hooked to wpim_admin_edit_form_end, which fires inside the field table.
	 * The same promo shows on the Labels screen, and one dismissal hides both.
	 */
	public function edit_form_custom_field_teaser() {
		$this->row_teaser(
			'aim',
			'item-editor',
			self::__( 'Need another field?' ),
			self::__( 'Add custom fields, dropdowns and number fields to match the information you need to track.' ),
			self::__( 'Add custom fields' )
		);
	}

	/**
	 * Quantity is where stock kept in more than one place stops fitting a single number.
	 * Hooked to wpim_admin_edit_form_after_quantity, which fires inside the field table.
	 */
	public function edit_form_locations_teaser() {
		$this->row_teaser(
			'locations',
			'item-editor-quantity',
			self::__( 'Keep stock in more than one place?' ),
			self::__( 'Manage inventory across warehouses, stores and vans, and transfer stock between locations.' ),
			self::__( 'Track locations' )
		);
	}

	/**
	 * The reserve form settings are where a site that takes reservations decides how
	 * visitors ask for items, one request per item unless they have the cart.
	 * Hooked to wpim_edit_settings_reserve, at the end of the reserve Form Settings table.
	 * Only shown while reservations are switched on.
	 *
	 * @param array $settings
	 */
	public function settings_reserve_cart_teaser( $settings ) {
		if ( empty( $settings['reserve_allow'] ) ) {
			return;
		}

		$this->row_teaser(
			'reserve_cart',
			'settings-reserve',
			self::__( 'Want visitors to reserve several items at once?' ),
			self::__( 'Let them build a cart and submit everything in one request.' ),
			self::__( 'Add a reservation cart' )
		);
	}

	/**
	 * The low quantity settings are one threshold and one address for every item;
	 * the add-on is what makes them per item.
	 * Hooked to wpim_edit_settings_notifications, at the end of the Notifications table.
	 */
	public function settings_notifications_teaser() {
		$this->row_teaser(
			'notifications',
			'settings-notifications',
			self::__( 'Need different low-stock alerts for different items?' ),
			self::__( 'Set individual alert levels and choose who gets notified.' ),
			self::__( 'Set per-item alerts' )
		);
	}

	/**
	 * The Labels screen is where people land when they hope renaming a field will
	 * do what they actually need, so it is worth drawing the line between renaming
	 * a field and adding one.
	 * Hooked to the wpim_manage_labels_notices filter.
	 *
	 * @param string $notices
	 *
	 * @return string
	 */
	public function labels_custom_field_teaser( $notices ) {
		return $notices . $this->notice_teaser(
			'aim',
			'labels',
			self::__( 'Need a field that doesn\'t exist?' ),
			self::__( 'Renaming only changes what a field is called. Create new fields, dropdowns and number fields instead.' ),
			self::__( 'Add custom fields' )
		);
	}

	/**
	 * The Display screen is where people decide what visitors see on the inventory
	 * pages, so it is where filtering those pages is worth mentioning.
	 * Hooked to the wpim_manage_display_notices filter.
	 *
	 * @param string $notices
	 *
	 * @return string
	 */
	public function display_advanced_search_teaser( $notices ) {
		return $notices . $this->notice_teaser(
			'advanced_search',
			'display',
			self::__( 'Want visitors to narrow down your inventory?' ),
			self::__( 'Add filters for price, categories and other inventory fields.' ),
			self::__( 'Add search filters' )
		);
	}

	/**
	 * One line inside a form table (item form, settings).
	 *
	 * Every link opens elsewhere and the dismiss link works in the background, because
	 * leaving the page would throw away whatever the user has typed into the form.
	 *
	 * @param string $key    - promo key, e.g. 'aim'
	 * @param string $source - which surface sent them
	 * @param string $lead   - the question, in bold
	 * @param string $body   - the outcome in plain words; the add-on is named in the details it opens
	 * @param string $cta    - link text
	 */
	private function row_teaser( $key, $source, $lead, $body, $cta ) {
		if ( ! $this->can_promote( $key ) ) {
			return;
		}

		$url = $this->promo_page_url( $key, $source );
		if ( ! $url ) {
			return;
		}

		echo '<tr class="wpim-field-teaser wpim-teaser">';
		echo '<th></th>';
		echo '<td>';
		echo '<strong>' . esc_html( $lead ) . '</strong> ' . esc_html( $body ) . ' ';
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $cta ) . ' &rarr;</a>';
		echo '<span class="wpim-teaser-dismiss"><a href="' . esc_url( $this->background_dismiss_url( $key ) ) . '" target="_blank" rel="noopener" data-wpim-dismiss>'
		     . self::__( 'No thanks, hide this' ) . '</a></span>';
		echo '</td>';
		echo '</tr>';

		$this->print_background_dismiss_script();
	}

	/**
	 * A boxed notice above a screen's content (Labels, Display).
	 *
	 * @param string $key    - promo key, e.g. 'aim'
	 * @param string $source - which surface sent them
	 * @param string $lead   - the question, in bold
	 * @param string $body   - the outcome in plain words; the add-on is named in the details it opens
	 * @param string $cta    - link text
	 *
	 * @return string
	 */
	private function notice_teaser( $key, $source, $lead, $body, $cta ) {
		if ( ! $this->can_promote( $key ) ) {
			return '';
		}

		$url = $this->promo_page_url( $key, $source );
		if ( ! $url ) {
			return '';
		}

		$html = '<div class="wpim-labels-teaser wpim-teaser">';
		$html .= '<p><strong>' . esc_html( $lead ) . '</strong> ' . esc_html( $body ) . ' '
		         . '<a href="' . esc_url( $url ) . '">' . esc_html( $cta ) . ' &rarr;</a></p>';
		$html .= '<p class="wpim-teaser-dismiss"><a href="' . esc_url( add_query_arg( 'dismiss', $key ) ) . '">'
		         . self::__( 'No thanks, hide this' ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * A plain GET of the inventory list with ?dismiss= records the dismissal (see handle_dismissal()).
	 * The list screen is used rather than the current URL, which after a save can still carry the form's action.
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	private function background_dismiss_url( $key ) {
		return add_query_arg( [
			'page'    => 'wpim_manage_inventory_items',
			'dismiss' => $key,
		], admin_url( 'admin.php' ) );
	}

	/**
	 * Clicking a data-wpim-dismiss link requests it in the background and hides the teaser,
	 * so the form and anything typed into it stay put. Without fetch, the link's own
	 * target="_blank" does the same job in a new tab.
	 */
	private function print_background_dismiss_script() {
		static $printed = FALSE;

		if ( $printed ) {
			return;
		}

		$printed = TRUE;
		?>
      <script>
        document.addEventListener( 'click', function( e ) {
          var link = e.target && e.target.closest ? e.target.closest( 'a[data-wpim-dismiss]' ) : null;
          if ( !link || !window.fetch ) {
            return;
          }

          e.preventDefault();
          window.fetch( link.href, { credentials: 'same-origin' } );

          var teaser = link.closest( '.wpim-teaser' );
          if ( teaser ) {
            teaser.style.display = 'none';
          }
        } );
      </script>
		<?php
	}

	/**
	 * Link to a promo page, tagged with the surface the click came from so the
	 * checkout link on that page can carry the attribution through.
	 *
	 * @param string $key    - promo key, e.g. 'aim'
	 * @param string $source - which surface sent them
	 *
	 * @return string
	 */
	private function promo_page_url( $key, $source ) {
		if ( $this->sell_through_freemius ) {
			return $this->freemius_addon_url( $key );
		}

		if ( empty( $this->promote[ $key ]['callback'] ) ) {
			return '';
		}

		return add_query_arg( [
			'page' => 'wpim_' . $this->promote[ $key ]['callback'],
			'from' => $source,
		], admin_url( 'admin.php' ) );
	}

	/**
	 * Freemius's own Add-Ons page (the marketplace), or '' if the SDK isn't loaded.
	 *
	 * @return string
	 */
	private function freemius_addons_page_url() {
		if ( ! function_exists( 'wpim_fs' ) ) {
			return '';
		}

		return wpim_fs()->get_addons_url();
	}

	/**
	 * Freemius's Add-Ons page with this add-on's details already open, where it can be bought.
	 *
	 * The slug is looked up by the add-on's Freemius ID in the add-on list the SDK has already
	 * stored, rather than hardcoded (at least one live slug is not the plugin's folder name).
	 * Reading the stored list never calls the Freemius API, so an admin screen cannot be slowed
	 * or stalled by it; if the list has not been fetched yet, this falls back to the Add-Ons page.
	 *
	 * @param string $key - promo key, e.g. 'aim'
	 *
	 * @return string
	 */
	private function freemius_addon_url( $key ) {
		$fallback = $this->freemius_addons_page_url();

		if ( empty( $this->promote[ $key ]['freemius_id'] ) || ! $fallback || ! class_exists( 'Freemius' ) ) {
			return $fallback;
		}

		$fs         = wpim_fs();
		$all_addons = Freemius::get_all_addons();
		$parent_id  = $fs->get_id();

		if ( empty( $all_addons[ $parent_id ] ) || ! is_array( $all_addons[ $parent_id ] ) ) {
			return $fallback;
		}

		foreach ( $all_addons[ $parent_id ] as $addon ) {
			if ( is_object( $addon ) && ! empty( $addon->slug ) && $addon->id == $this->promote[ $key ]['freemius_id'] ) {
				return $fs->addon_url( $addon->slug );
			}
		}

		return $fallback;
	}

	/**
	 * Whether a promotion should still be shown: the add-on isn't installed and
	 * the user hasn't dismissed it.
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	private function can_promote( $key ) {
		if ( NULL === $this->plugins ) {
			$this->find_wpim_plugins();
			$this->ensure_can_promote();
		}

		if ( ! isset( $this->promote[ $key ] ) ) {
			return FALSE;
		}

		return empty( self::$config->get( "dismissed_{$key}" ) );
	}

	/**
	 * Getting existing data in is the first thing people try, and the answer is an
	 * add-on, so the empty inventory is where that is worth saying.
	 * Hooked to wpim_admin_items_empty_state.
	 */
	public function empty_state_import_teaser() {
		if ( ! $this->can_promote( 'ie' ) ) {
			return;
		}

		$promo_url = $this->promo_page_url( 'ie', 'empty-items' );

		echo '<div class="wpim-empty-state-teaser">';
		echo '<p class="wpim-teaser-lead">' . self::__( 'Already have your inventory in a spreadsheet?' ) . '</p>';
		echo '<p>' . self::__( 'Import all your items from a CSV instead of adding them one by one.' ) . '</p>';
		echo '<p><a href="' . esc_url( $promo_url ) . '">' . self::__( 'Import from CSV' ) . ' &rarr;</a></p>';
		echo '<p class="wpim-teaser-dismiss"><a href="' . esc_url( add_query_arg( 'dismiss', 'ie' ) ) . '">' . self::__( 'No thanks, hide this' ) . '</a></p>';
		echo '</div>';
	}

	public function dismissal_notices() {
		if ( ! $this->dismissed ) {
			return;
		}

		$dismissed = $this->promote[ $this->dismissed ];
		if ( empty( $dismissed['title'] ) ) {
			return;
		}

		if ( $this->sell_through_freemius ) {
			// One shop per site: point only at Freemius's Add-Ons page, never the website.
			$add_on_link = '<a href="' . esc_url( $this->freemius_addons_page_url() ) . '">' . self::__( 'the Add-Ons page' ) . '</a>';

			echo '<div class="notice notice-success">';
			echo '<p>' . sprintf( self::__( 'The %s Add-On promo will not be shown again. You can always find it on %s.' ), esc_html( $dismissed['title'] ), $add_on_link ) . '</p>';
			echo '</div>';

			return;
		}

		$add_on_link      = admin_url( 'admin.php?page=wpim_manage_add_ons' );
		$wpinventory_link = 'https://www.wpinventory.com/add-ons';
		$add_on_link      = '<a href="' . $add_on_link . '">' . self::__( 'the Add-Ons page' ) . '</a>';
		$wpinventory_link = '<a href="' . $wpinventory_link . '">' . self::__( 'The WPInventory Website' ) . '</a>';

		echo '<div class="notice notice-success">';
		echo '<p>' . self::__( sprintf( 'The %s Add-On promo will not be shown again.  You can always find it by checking out %s or visiting %s.', $dismissed['title'], $add_on_link, $wpinventory_link ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * WP Inventory wpim_admin_menu hook,
	 * which is a "child" hook of WordPress admin_menu hook.
	 * Allows registering menus that are sub-menus of the WP Inventory menu
	 * at the appropriate position.
	 *
	 * This function first checks if the Add-Ons are present (even if not active),
	 * and if so, prevents adding the menus.
	 */
	public function wpim_admin_menu() {
		$this->handle_dismissal();

		// The promo pages sell through the website checkout; a Freemius site never gets them.
		if ( $this->sell_through_freemius ) {
			return;
		}

		$lowest_role = self::$config->get( 'permissions_lowest_role' );
		$this->find_wpim_plugins();
		$this->ensure_can_promote();

		if ( empty( $this->promote ) ) {
			return;
		}

		foreach ( $this->promote AS $key => $data ) {
			if ( ! empty( self::$config->get( "dismissed_{$key}" ) ) ) {
				continue;
			}

			// Teaser-only entries have no promo page.
			if ( ! empty( $data['callback'] ) && is_callable( [ $this, $data['callback'] ] ) ) {
				add_submenu_page( self::MENU, $data['menu'], $data['menu'], $lowest_role, "wpim_{$data['callback']}", [ $this, $data['callback'] ] );
			}
		}
	}

	/**
	 * WordPress admin_enqueue_scripts hook.
	 * Registers the styles, which are then only included (in the footer)
	 * if a "promo" page is displayed.
	 */
	public function admin_enqueue_scripts() {
		wp_register_style( 'wpim-promo', self::$url . 'css/promo-admin.css' );
	}

	/**
	 * WordPress admin_footer hook.
	 * IF the promo page has been shown, then output the special stylesheet.
	 */
	public function admin_footer() {
		if ( ! $this->promotion_shown ) {
			return;
		}

		wp_print_styles( [ 'wpim-promo' ] );
	}

	private function handle_dismissal() {
		if ( ! self::is_wpinventory_page() ) {
			return;
		}

		if ( empty( $_GET['dismiss'] ) ) {
			return;
		}

		$dismiss = sanitize_text_field( wp_unslash( $_GET['dismiss'] ) );

		/**
		 * Only accept keys we actually promote.
		 *
		 * The value is interpolated into a persisted settings key below
		 * ("dismissed_<value>"), so without this check any request carrying
		 * ?dismiss=... on a WP Inventory admin screen writes an arbitrary key
		 * into the options array. Every real dismissal link is built from a
		 * promo key (see the "dismiss-promo" link in open_promo_markup), and
		 * handle_dismissal() runs before ensure_can_promote() prunes the list,
		 * so all legitimate keys are present here.
		 */
		if ( ! isset( $this->promote[ $dismiss ] ) ) {
			return;
		}

		$this->dismissed = $dismiss;

		self::$config->set( "dismissed_{$this->dismissed}", TRUE );
	}

	/**
	 * Get a list of all installed plugins that appear to be "WP Inventory" related
	 * (including add-ons, even if not activated).
	 */
	private function find_wpim_plugins() {
		$plugins = get_plugins();
		$plugins = array_map( function ( $plugin ) {
			return $plugin['Name'];
		}, $plugins );

		$this->plugins = array_filter( $plugins, function ( $plugin ) {
			return ( 0 === stripos( $plugin, 'wp inventory' ) );
		} );
	}

	/**
	 * Reduces the "promote" list to ONLY add-ons that aren't actually
	 * installed (even if not activated).
	 */
	private function ensure_can_promote() {
		if ( $this->TEST_MODE ) {
			return;
		}

		foreach ( $this->promote AS $key => $data ) {
			$keywords = $data['keywords'];
			foreach ( $this->plugins AS $plugin ) {
				$match = TRUE;
				foreach ( $keywords AS $keyword ) {
					if ( FALSE === stripos( $plugin, $keyword ) ) {
						$match = FALSE;
					}
				}

				if ( $match ) {
					unset( $this->promote[ $key ] );
					break;
				}
			}
		}
	}

	/**
	 * Common method to render the "promo" markup.
	 * Additionally sets the flag for styles to be output in the footer.
	 * NOTE: This MUST be used, otherwise the styles won't be output!
	 *
	 * @param string $title
	 * @param string $key
	 */
	private function open_promo_markup( $title, $key ) {
		$this->this_promo = $key;
		echo '<div class="inventorywrap">' . PHP_EOL;

		WPIMAdmin::header( $title, 'latest', 'version' );

		$this->promotion_shown = TRUE;
	}

	/**
	 * Common method to render the "promo" closing markup.
	 */
	private function close_promo_markup() {
		if ( $this->can_dismiss ) {
			echo '<div class="dismiss-promo">';
			echo '<a href="' . add_query_arg( 'dismiss', $this->this_promo, self::$self_url ) . '">' . self::__( 'Dismiss and Do Not Show Again' ) . '</a>';
			echo '</div>';
		}

		echo '</div>';
	}


	/**
	 * Render the purchase link
	 *
	 * @param string $id
	 * @param string $campaign - which promo the click came from, for attribution
	 *
	 * @return string
	 */
	public function render_checkout_link( $id, $campaign = '' ) {
		if ( ! $id ) {
			return '';
		}

		return '<a class="button button-primary green" href="' . esc_url( self::checkout_url( $id, $campaign ) ) . '" target="_blank">' . self::__( 'Buy Now' ) . '</a>';

	}

	/**
	 * Checkout URL tagged so the store can tell which in-plugin surface sent the
	 * visitor. utm_content carries the entry point (e.g. the empty item list)
	 * when the promo page was reached from one.
	 *
	 * @param string $id
	 * @param string $campaign
	 *
	 * @return string
	 */
	private static function checkout_url( $id, $campaign = '' ) {
		$source = ( ! empty( $_GET['from'] ) ) ? sanitize_key( wp_unslash( $_GET['from'] ) ) : 'menu';

		return add_query_arg( array_filter( [
			'edd_action'   => 'add_to_cart',
			'download_id'  => $id,
			'utm_source'   => 'wpim-plugin',
			'utm_medium'   => 'promo',
			'utm_campaign' => $campaign,
			'utm_content'  => $source,
		] ), 'https://www.wpinventory.com/checkout' );
	}

	/**
	 * Renders the "All Access Pass" sidebar, which is common to all of the promo pages.
	 */
	private function promote_all_access_pass() {
		echo '<div class="col col-2">';
		echo '<div class="wpim_sidebar">';
		echo '<h2>' . self::__( 'All-Access Pass' ) . '</h2>';
		echo '<h3 class="best-value">' . self::__( 'Best Value!' ) . '</h3>';
		echo '<h3>' . self::__( 'For just $149, unlock full access to all WP Inventory Add-ons:' ) . '</h3>';
		echo '<ul>';
		echo '<li><a href="https://www.wpinventory.com/downloads/add-advanced-inventory-manager/" target="_blank">Advanced Inventory Manager</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/wp-inventory-import-and-export/" target="_blank">Import / Export</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/wp-inventory-ledger/" target="_blank">Ledger</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/advanced-user-control/" target="_blank">Advanced User Control</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/add-on-locations-manager/" target="_blank">Locations Manager</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/add-on-bulk-item-manager/" target="_blank">Bulk Item Manager</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/add-on-advanced-search/" target="_blank">Advanced Search Filter</a></li>';
		echo '<li><a href="https://www.wpinventory.com/downloads/add-reserve-cart/" target="_blank">Reserve Cart</a></li>';
		echo '</ul>';

		echo '<div class="promotion_ctas"><a href="https://www.wpinventory.com/wp-inventory-license/" target="_blank">Learn More</a> <a class="button button-primary green" href="' . esc_url( self::checkout_url( 1990, 'all-access' ) ) . '" target="_blank">' . self::__( 'Buy Now' ) . '</a></div>';
		echo '</div>';
		echo '</div>'; // End wpim_sidebar

	}

	/**
	 * Promo markup for Import / Export add-on.
	 */
	public function promote_ie() {
		$this->open_promo_markup( self::__( 'Import / Export' ), 'ie' );

		echo '<div class="col col-6">';
		echo '<h2>' . self::__( 'Powerful Importing / Exporting Tools' ) . '</h2>';
		echo '<h3>' . self::__( 'Just $59.99' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . self::__( 'Allows importing and exporting items quickly and easily.' ) . '</li>';
		echo '<li>' . self::__( 'Update your inventory offline, and simply import a CSV.' ) . '</li>';
		echo '<li>' . self::__( 'Add new items quickly.' ) . '</li>';
		echo '</ul>';
		echo '<iframe width="560" height="315" src="https://www.youtube.com/embed/K9wmzE_viMM" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';

		echo '<div class="promotion_ctas"><a href="https://www.wpinventory.com/documentation/user/add-on-documentation/importing-exporting-inventory/" target="_blank">' . self::__( 'Learn More' ) . '</a> ' . self::render_checkout_link( 655, 'import-export' ) . '</div>';

		echo '<p><strong>Please note</strong> that an active license of <a href="https://www.wpinventory.com/checkout?edd_action=add_to_cart&download_id=675" target="_blank">WP Inventory Manager</a> is required in addition to this add on.</p>';
		echo '</div>';

		$this->promote_all_access_pass();
		$this->close_promo_markup();
	}

	/**
	 * Promo markup for Advanced Inventory Manager
	 */
	public function promote_aim() {
		$this->open_promo_markup( self::__( 'Advanced Inventory Manager' ), 'aim' );

		echo '<div class="col col-6">';
		echo '<h2>' . self::__( 'More fields, more types.' ) . '</h2>';
		echo '<h3>' . self::__( 'Just $79.99' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . self::__( 'Add "types" of inventory and fields for each' ) . '</li>';
		echo '<li>' . self::__( 'Add as many fields as you want and various types like radio, text, number and drop down select' ) . '</li>';
		echo '<li>' . self::__( 'Display item types via shortcode option on any page' ) . '</li>';
		echo '</ul>';

		echo '<iframe width="560" height="315" src="https://www.youtube.com/embed/LBnlSokdOpk" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';

		echo '<div class="promotion_ctas"><a href="https://www.wpinventory.com/documentation/user/add-on-documentation/advanced-inventory-manager/" target="_blank">' . self::__( 'Learn More' ) . '</a> ' . self::render_checkout_link( 2917, 'advanced-inventory-manager' ) . '</div>';
		echo '</div>';

		$this->promote_all_access_pass();
		$this->close_promo_markup();
	}

	/**
	 * Promo markup for Locations Manager
	 */
	public function promote_locations() {
		$this->open_promo_markup( self::__( 'Locations Manager' ), 'locations' );

		echo '<div class="col col-6">';
		echo '<h2>' . self::__( 'Track Inventory Across Multiple Locations.' ) . '</h2>';
		echo '<h3>' . self::__( 'Just $39.99' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . self::__( 'Add as many locations as you want' ) . '</li>';
		echo '<li>' . self::__( 'Manage each item and the location(s) it is at' ) . '</li>';
		echo '<li>' . self::__( 'No limits on quantities you can add to each location' ) . '</li>';
		echo '</ul>';

		echo '<iframe width="560" height="315" src="https://www.youtube.com/embed/6xs4jIhpE58" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';

		echo '<div class="promotion_ctas"><a href="https://www.wpinventory.com/documentation/user/add-on-documentation/managing-inventory-locations/" target="_blank">' . self::__( 'Learn More' ) . '</a> ' . self::render_checkout_link( 13153, 'locations-manager' ) . '</div>';
		echo '</div>';

		$this->promote_all_access_pass();
		$this->close_promo_markup();
	}

	/**
	 * Sets up the data for all the promotions.
	 * Data is structured as an array.
	 * The key is just for convenient reference, the data
	 * dictates:
	 * keywords - which keywords must be present in the add-on name in order for it to be considered a "match"
	 * menu - the "Title" that is used in the admin menu
	 * callback - the name of the function in this class that is called when the menu item is clicked
	 * freemius_id - the add-on's Freemius product ID, used to open it in Freemius's Add-Ons page
	 */
	private function set_up_promotions() {
		$this->promote = [
			'ie'        => [
				'keywords' => [
					'Import',
					'Export'
				],
				'menu'     => self::__( 'Import / Export' ),
				'title'    => self::__( 'Import / Export' ),
				'callback' => 'promote_ie',
				'freemius_id' => 34650
			],
			'aim'       => [
				'keywords' => [
					'Advanced',
					'Inventory',
					'Manager'
				],
				'menu'     => self::__( 'Advanced Management' ),
				'title'    => self::__( 'Advanced Inventory Manager' ),
				'callback' => 'promote_aim',
				'freemius_id' => 35588
			],
			'locations'  => [
				'keywords' => [ 'Location' ],
				'menu'     => self::__( 'Locations Manager' ),
				'title'    => self::__( 'Locations Manager' ),
				'callback' => 'promote_locations',
				'freemius_id' => 34826
			],
			'analytics'  => [
				'keywords' => [ 'Analytics' ],
				'menu'     => self::__( 'Analytics' ),
				'title'    => self::__( 'Analytics' ),
				'callback' => 'promote_analytics',
				'freemius_id' => 35595
			],
			// Teaser-only entries: no promo page, so on a site that does not sell through
			// Freemius there is nowhere to send the click and their teasers stay hidden.
			'reserve_cart'    => [
				'keywords'    => [ 'Reserv', 'Cart' ],
				'title'       => self::__( 'Reserve Cart' ),
				'freemius_id' => 34827
			],
			'notifications'   => [
				'keywords'    => [ 'Notification' ],
				'title'       => self::__( 'Notifications' ),
				'freemius_id' => 34828
			],
			'advanced_search' => [
				'keywords'    => [ 'Advanced', 'Search' ],
				'title'       => self::__( 'Advanced Search' ),
				'freemius_id' => 35590
			],
		];
	}

	/**
	 * Promo markup for Analytics add-on (shown to free/Core users).
	 */
	public function promote_analytics() {
		$this->open_promo_markup( self::__( 'Inventory Analytics' ), 'analytics' );

		echo '<div class="col col-6">';
		echo '<h2>' . self::__( 'Know your inventory at a glance.' ) . '</h2>';
		echo '<ul>';
		echo '<li>' . self::__( 'Summary cards: active items, stock levels, out-of-stock counts' ) . '</li>';
		echo '<li>' . self::__( 'Most viewed items (top 10 by detail-page views)' ) . '</li>';
		echo '<li>' . self::__( 'Category and status breakdowns' ) . '</li>';
		echo '<li>' . self::__( 'Low-stock alert table with category and price details' ) . '</li>';
		echo '<li>' . self::__( 'Reservation analytics and CSV export (requires Reserve Cart)' ) . '</li>';
		echo '<li>' . self::__( 'Payments summary with gross/net revenue (requires Stripe or PayPal)' ) . '</li>';
		echo '<li>' . self::__( 'Ledger value tracking (requires Ledger)' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . self::__( 'Note:' ) . '</strong> ' . self::__( 'Analytics requires WP Inventory Pro. Upgrade to Pro and add Analytics for the full dashboard.' ) . '</p>';
		echo '<div class="promotion_ctas">';
		echo '<a href="https://www.wpinventory.com/wp-inventory-license/" target="_blank" rel="noopener" class="button button-primary green">' . self::__( 'Get Pro' ) . '</a> ';
		echo '<a href="https://www.wpinventory.com/documentation/user/add-on-documentation/analytics/" target="_blank" rel="noopener">' . self::__( 'Learn More about Analytics' ) . '</a>';
		echo '</div>';
		echo '</div>';

		$this->promote_all_access_pass();
		$this->close_promo_markup();
	}
}

new WPIMPromo();
