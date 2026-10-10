/**
 * Run the cleanup of old entries from the settings.
 *
 * The function has to live on window: the dialog runs a button's action
 * through new Function(), which only sees globals.
 *
 * @package now-pages-threadi
 */

( function () {
	'use strict';

	/**
	 * Show a dialog.
	 *
	 * The global helper hands its argument straight to the CustomEvent, so
	 * the dialog itself has to sit under "detail".
	 *
	 * @param {Object} dialog The dialog configuration.
	 */
	const showDialog = function ( dialog ) {
		now_pages_threadi_create_dialog( { detail: dialog } );
	};

	/**
	 * Show a dialog with a single OK button.
	 *
	 * @param {string} text The text to show.
	 */
	const showMessage = function ( text ) {
		showDialog( {
			title: nowPagesThreadiCleanupVars.title,
			texts: [ '<p>' + text + '</p>' ],
			buttons: [
				{
					action: 'closeDialog();',
					variant: 'primary',
					text: nowPagesThreadiCleanupVars.ok
				}
			]
		} );
	};

	/**
	 * Delete every entry older than the age given in the dialog.
	 */
	window.now_pages_threadi_cleanup_entries = function () {
		const field = document.getElementById( 'now-pages-threadi-cleanup-days' );

		// bail if the dialog is not the one we expect.
		if ( ! field ) {
			showMessage( nowPagesThreadiCleanupVars.error );

			return;
		}

		// read the whole value: parseInt() would turn e.g. "1e3" into 1.
		const days = Number( field.value );

		// bail on an age nobody can have meant.
		if ( '' === field.value.trim() || ! Number.isInteger( days ) || days < 1 ) {
			showMessage( nowPagesThreadiCleanupVars.invalid );

			return;
		}

		// replace the question with a dialog showing that something runs.
		// the progress bar carries no value on purpose: the deletion
		// reports no intermediate state, so an indeterminate bar is the
		// honest one.
		showDialog( {
			id: 'now-pages-threadi-cleanup-running',
			title: nowPagesThreadiCleanupVars.title,
			texts: [ '<p>' + nowPagesThreadiCleanupVars.running + '</p>' ],
			progressbar: {
				active: true,
				id: 'now-pages-threadi-cleanup-progress'
			}
		} );

		const data = new FormData();

		data.append( 'action', 'now_pages_threadi_cleanup' );
		data.append( 'nonce', nowPagesThreadiCleanupVars.nonce );
		data.append( 'days', days );

		fetch( nowPagesThreadiCleanupVars.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}

				return response.json();
			} )
			.then( function ( result ) {
				// the answer carries a ready-made dialog, so the counts and
				// their plural forms are built where the numbers are known.
				if ( ! result.success || ! result.data ) {
					showMessage( nowPagesThreadiCleanupVars.error );

					return;
				}

				showDialog( result.data );
			} )
			.catch( function () {
				showMessage( nowPagesThreadiCleanupVars.error );
			} );
	};
} )();
