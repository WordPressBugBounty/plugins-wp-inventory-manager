/**
 * Adds an "All Access" card to the Freemius pricing page, beside Pro.
 *
 * All Access is a Freemius bundle, and the SDK's pricing app has no concept of one: its
 * pricing data carries only the parent product's own plans, so a free user never sees the
 * bundle anywhere in the plugin. We add it ourselves.
 *
 * The card is a clone of the Pro package with its text, price and button swapped, so it
 * inherits whatever styling Freemius ships. The pricing app owns the row's layout and
 * writes an explicit width onto every package whenever it adjusts, which happens on mount,
 * on update and on resize; the card mirrors Pro's width on the same events so it never
 * falls out of step. If the app's markup ever changes enough that no Pro card can be found,
 * a plain banner with the same button is shown instead, so the link never silently vanishes.
 *
 * Nothing here runs for a paying site: the card is only inserted when the SDK reports no
 * active licence (see the PHP side), and the pricing page itself is one Freemius removes
 * from the menu once a licence is present.
 */
( function () {
	'use strict';

	var cfg = window.wpimAllAccess;

	if ( ! cfg || ! cfg.checkoutUrl ) {
		return;
	}

	var CARD_CLASS = 'wpim-all-access';
	var proCard = null;
	var card = null;
	var tries = 0;

	function findProCard() {
		var packages = document.querySelectorAll( '.fs-package:not(.' + CARD_CLASS + ')' );

		for ( var i = 0; i < packages.length; i++ ) {
			var title = packages[ i ].querySelector( '.fs-plan-title' );

			if ( title && cfg.proPlanName === title.textContent.trim().toLowerCase() ) {
				return packages[ i ];
			}
		}

		return null;
	}

	function setText( root, selector, text ) {
		var el = root.querySelector( selector );

		if ( el ) {
			el.textContent = text;
		}
	}

	// Replace every occurrence of the Pro site count ("2 Sites") in text nodes, wherever
	// Freemius happens to render it, without touching the surrounding markup.
	function replaceSiteCount( root ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		var node;

		while ( ( node = walker.nextNode() ) ) {
			if ( /\d+\s*Sites?/.test( node.textContent ) ) {
				node.textContent = node.textContent.replace( /\d+\s*Sites?/, cfg.sitesLabel );
			}
		}
	}

	function buildCard( pro ) {
		var clone = pro.cloneNode( true );
		clone.classList.add( CARD_CLASS );

		// Pro keeps its "Most Popular" ribbon.
		var ribbon = clone.querySelector( '.fs-most-popular' );
		if ( ribbon ) {
			ribbon.parentNode.removeChild( ribbon );
		}

		setText( clone, '.fs-plan-title strong', cfg.title );
		setText( clone, '.fs-plan-description strong', cfg.description );
		setText( clone, '.fs-undiscounted-price', cfg.undiscounted );
		setText( clone, '.fs-selected-pricing-amount-integer strong', cfg.priceInteger );
		setText( clone, '.fs-selected-pricing-amount-fraction', cfg.priceFraction );
		setText( clone, 'td.fs-license-quantity-price', cfg.priceLine );
		replaceSiteCount( clone );

		var list = clone.querySelector( 'ul.fs-plan-features' );
		if ( list ) {
			var template = list.querySelector( 'li' );
			list.innerHTML = '';

			if ( template ) {
				for ( var i = 0; i < cfg.features.length; i++ ) {
					var item = template.cloneNode( true );
					var label = item.querySelector( '.fs-feature-title' );

					if ( label ) {
						label.innerHTML = '';
						var strong = document.createElement( 'strong' );
						strong.textContent = cfg.features[ i ];
						label.appendChild( strong );
					}

					list.appendChild( item );
				}
			}
		}

		// A cloned node carries none of React's handlers, so the button is inert until we
		// wire it. Open the checkout in a new tab: it is Freemius-hosted, not part of wp-admin.
		var button = clone.querySelector( '.fs-upgrade-button' );
		if ( button ) {
			button.textContent = cfg.buttonText;
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				window.open( cfg.checkoutUrl, '_blank', 'noopener' );
			} );
		}

		return clone;
	}

	// The pricing app sets an inline width on each package and a total width on the row
	// (packages x width) every time it lays out. Mirror Pro so the row accounts for us.
	function syncWidth() {
		if ( ! proCard || ! card || ! proCard.style.width ) {
			return;
		}

		card.style.width = proCard.style.width;

		var row = card.parentNode;
		var count = row.querySelectorAll( '.fs-package' ).length;
		var width = parseFloat( proCard.style.width ) * count;

		row.style.width = width + 'px';

		if ( row.parentNode && row.parentNode.style.width ) {
			row.parentNode.style.width = width + 'px';
		}
	}

	function insertCard() {
		proCard = findProCard();

		if ( ! proCard || card ) {
			return !! card;
		}

		card = buildCard( proCard );
		proCard.parentNode.insertBefore( card, proCard.nextSibling );
		syncWidth();

		// Re-mirror after the app's own resize handler (it debounces at 250ms).
		window.addEventListener( 'resize', function () {
			setTimeout( syncWidth, 400 );
		} );

		return true;
	}

	function showFallback() {
		var wrapper = document.getElementById( 'fs_pricing_wrapper' );

		if ( ! wrapper || document.querySelector( '.' + CARD_CLASS + '-fallback' ) ) {
			return;
		}

		var banner = document.createElement( 'div' );
		banner.className = 'notice notice-info ' + CARD_CLASS + '-fallback';
		banner.innerHTML = '<p></p>';
		banner.firstChild.textContent = cfg.fallbackText + ' ';

		var link = document.createElement( 'a' );
		link.href = cfg.checkoutUrl;
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = cfg.buttonText;
		banner.firstChild.appendChild( link );

		wrapper.parentNode.insertBefore( banner, wrapper );
	}

	// The app renders asynchronously after an AJAX fetch. Poll briefly for the Pro card;
	// if it never appears, fall back rather than leave the bundle unreachable.
	function attempt() {
		if ( insertCard() ) {
			return;
		}

		if ( ++tries < 40 ) {
			setTimeout( attempt, 250 );
		} else {
			showFallback();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', attempt );
	} else {
		attempt();
	}
} )();
