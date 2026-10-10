/**
 * Run the availability check for a single source.
 *
 * Has to live on window: the dialog runs a button's action through
 * new Function(), which only sees globals.
 *
 * @package now-pages-threadi
 */

( function () {
	'use strict';

	/**
	 * Show a dialog with a single OK button.
	 *
	 * @param {string} text The text to show.
	 */
  const showMessage = function ( text ) {
    now_pages_threadi_create_dialog( {
      detail: {
        title: nowPagesThreadiCheckVars.title,
        texts: [ '<p>' + text + '</p>' ],
        buttons: [
          {
            action: 'closeDialog();',
            variant: 'primary',
            text: nowPagesThreadiCheckVars.ok
          }
        ]
      }
    } );
  };

	/**
	 * Run the check for one source and show its result.
	 *
	 * @param {number} sourceId The post ID of the source.
	 */
	window.now_pages_threadi_check_source = function ( sourceId ) {
		// replace the question with a dialog showing that something runs.
		// the progress bar carries no value on purpose: the check reports no
		// intermediate state, so an indeterminate bar is the honest one.
    now_pages_threadi_create_dialog( {
      detail: {
        id: 'now-pages-threadi-check',
        title: nowPagesThreadiCheckVars.title,
        texts: [ '<p>' + nowPagesThreadiCheckVars.running + '</p>' ],
        progressbar: {
          active: true,
          id: 'now-pages-threadi-check-progress'
        },
        buttons: [
          {
            action: 'closeDialog();',
            variant: 'secondary',
            text: nowPagesThreadiCheckVars.cancel
          }
        ]
      }
		} );

		const data = new FormData();

		data.append( 'action', 'now_pages_threadi_check_source' );
		data.append( 'nonce', nowPagesThreadiCheckVars.nonce );
		data.append( 'source', sourceId );

		fetch( nowPagesThreadiCheckVars.ajax_url, {
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
				// the answer carries a ready-made dialog, so every text is
				// translated on the server where the error codes are known.
				if ( ! result.success || ! result.data ) {
					showMessage( nowPagesThreadiCheckVars.error );

					return;
				}

        now_pages_threadi_create_dialog( { detail: result.data } );
			} )
			.catch( function () {
				showMessage( nowPagesThreadiCheckVars.error );
			} );
	};

  const params = new URLSearchParams( window.location.search );
  const requested = params.get( 'now_pages_threadi_check' );

  if ( requested ) {
    window.now_pages_threadi_check_source( parseInt( requested, 10 ) );
  }
} )();
