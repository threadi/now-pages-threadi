/**
 * Tell the user that the advisor is working.
 *
 * The setup shows nothing while it fetches its next step. Every other step
 * arrives within a blink, but this one asks an AI model and tries each of
 * its proposals against the real service, which can take a while.
 *
 * The hint lives outside the element the setup renders into: whatever the
 * setup does with its own markup, it cannot collide with ours.
 *
 * @package threadi-now-pages
 */

( function () {
	'use strict';

	const vars = window.threadiNowPagesAdvisorVars;
	const setup = document.getElementById( 'easy-setup-for-wordpress' );

	// bail if we are not on the setup, or the advisor is not offered.
	if ( ! vars || ! setup ) {
		return;
	}

	const selector = '.threadi-now-pages-advisor-wish';
	let hint = null;

	/**
	 * Remove the hint.
	 */
	const hide = function () {
		if ( hint ) {
			hint.remove();
			hint = null;
		}
	};

	/**
	 * Show the hint below the setup.
	 */
	const show = function () {
		if ( hint ) {
			return;
		}

		hint = document.createElement( 'p' );
		hint.className = 'threadi-now-pages-advisor-waiting';
		hint.setAttribute( 'role', 'status' );

		const spinner = document.createElement( 'span' );
		spinner.className = 'spinner is-active';

		hint.append( spinner, document.createTextNode( vars.waiting ) );
		setup.after( hint );
	};

	// show the hint when the step asking for the wish is left via its primary button.
	setup.addEventListener( 'click', function ( event ) {
		const button = event.target instanceof Element ? event.target.closest( 'button.is-primary' ) : null;

		if ( ! button || button.disabled || ! setup.querySelector( selector ) ) {
			return;
		}

		show();
	}, true );

	// remove it as soon as the setup shows another step.
	new MutationObserver( function () {
		if ( hint && ! setup.querySelector( selector ) ) {
			hide();
		}
	} ).observe( setup, { childList: true, subtree: true } );
} )();
