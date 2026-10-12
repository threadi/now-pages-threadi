/**
 * Run the availability check for a single source.
 *
 * Has to live on window: the dialog runs a button's action through
 * new Function(), which only sees globals.
 *
 * @package threadi-now-pages
 */

( function () {
	'use strict';

	/**
	 * Show a dialog with a single OK button.
	 *
	 * @param {string} text The text to show.
	 */
  const showMessage = function ( text ) {
    threadi_now_pages_create_dialog( {
      detail: {
        title: threadiNowPagesCheckVars.title,
        texts: [ '<p>' + text + '</p>' ],
        buttons: [
          {
            action: 'closeDialog();',
            variant: 'primary',
            text: threadiNowPagesCheckVars.ok
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
	window.threadi_now_pages_check_source = function ( sourceId ) {
		// replace the question with a dialog showing that something runs.
		// the progress bar carries no value on purpose: the check reports no
		// intermediate state, so an indeterminate bar is the honest one.
    threadi_now_pages_create_dialog( {
      detail: {
        id: 'threadi-now-pages-check',
        title: threadiNowPagesCheckVars.title,
        texts: [ '<p>' + threadiNowPagesCheckVars.running + '</p>' ],
        progressbar: {
          active: true,
          id: 'threadi-now-pages-check-progress'
        },
        buttons: [
          {
            action: 'closeDialog();',
            variant: 'secondary',
            text: threadiNowPagesCheckVars.cancel
          }
        ]
      }
		} );

		const data = new FormData();

		data.append( 'action', 'threadi_now_pages_check_source' );
		data.append( 'nonce', threadiNowPagesCheckVars.nonce );
		data.append( 'source', sourceId );

		fetch( threadiNowPagesCheckVars.ajax_url, {
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
					showMessage( threadiNowPagesCheckVars.error );

					return;
				}

        threadi_now_pages_create_dialog( { detail: result.data } );
			} )
			.catch( function () {
				showMessage( threadiNowPagesCheckVars.error );
			} );
	};

  const params = new URLSearchParams( window.location.search );
  const requested = params.get( 'threadi_now_pages_check' );

  if ( requested ) {
    window.threadi_now_pages_check_source( parseInt( requested, 10 ) );
  }
} )();
