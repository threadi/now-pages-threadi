/**
 * Initiate the import of new entries.
 */
function threadi_now_pages_import( process_id ) {
  threadi_now_pages_start_ajax_process( {
    'start_action': 'threadi_now_pages_import',
    'start_nonce': threadiNowPagesProgressHandlerJsVars.start_nonce,
    'info_action': 'threadi_now_pages_process_info',
    'info_nonce': threadiNowPagesProgressHandlerJsVars.process_info_nonce,
    'process_title': threadiNowPagesProgressHandlerJsVars.title,
    'process_id': process_id
  });
}

let threadi_now_pages_import_running = false;
// marker for a failed start request: stops the polling.
let threadi_now_pages_import_failed = false;
// marker for progress timeout.
let threadi_now_pages_progress_timeout = false;

/**
 * Function to handle any AJAX process.
 */
function threadi_now_pages_start_ajax_process( config ) {
  // start the process.
  jQuery.ajax({
    type: "POST",
    url: threadiNowPagesProgressHandlerJsVars.ajax_url,
    data: {
      'action': config.start_action,
      'nonce': config.start_nonce,
      'post': config.post,
      'process_id': config.process_id
    },
    beforeSend: function() {
      // show progress.
      let dialog_config = {
        detail: {
          className: 'now-pages-dialog',
          title: config.process_title,
          progressbar: {
            active: true,
            progress: 0,
            id: 'progress',
            label_id: 'progress_status'
          },
        }
      }
      threadi_now_pages_create_dialog( dialog_config );

      // mark in JS as running.
      threadi_now_pages_import_running = true;
      threadi_now_pages_import_failed = false;

      // get info about progress.
      threadi_now_pages_progress_timeout = setTimeout(function() { threadi_now_pages_ajax_process( config ) }, 1000 );
    },
    success: function( response ) {
      if( response && response.load_more ) {
        clearTimeout(threadi_now_pages_progress_timeout);
        threadi_now_pages_start_ajax_process( config );
        return;
      }

      // mark import as not running.
      threadi_now_pages_import_running = false;

      // stop if the server did not accept the request (e.g. "0", "-1" or success = false).
      if( ! response || typeof response !== 'object' || false === response.success ) {
        threadi_now_pages_stop_with_error( '' );
      }
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      // mark import as not running and stop the polling.
      threadi_now_pages_import_running = false;
      threadi_now_pages_stop_with_error( errorThrown );
    }
  });
}

/**
 * Stop the polling and show an error.
 */
function threadi_now_pages_stop_with_error( errortext ) {
  // bail if this has already been done.
  if( threadi_now_pages_import_failed ) {
    return;
  }

  threadi_now_pages_import_failed = true;
  clearTimeout( threadi_now_pages_progress_timeout );
  threadi_now_pages_ajax_error_dialog( errortext );
}

/**
 * Get info until import is done.
 */
function threadi_now_pages_ajax_process( config ) {
  // bail if the start request failed.
  if( threadi_now_pages_import_failed ) {
    return;
  }

  jQuery.ajax( {
    type: "POST",
    url: threadiNowPagesProgressHandlerJsVars.ajax_url,
    data: {
      'action': config.info_action,
      'nonce': config.info_nonce,
      'process_id': config.process_id
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      threadi_now_pages_stop_with_error( errorThrown );
    },
    success: function (data) {
      // bail if the start request failed meanwhile.
      if( threadi_now_pages_import_failed ) {
        return;
      }

      // bail on an unexpected answer (e.g. "0", "-1" or success = false).
      if( ! Array.isArray( data ) || data.length < 5 ) {
        threadi_now_pages_stop_with_error( '' );
        return;
      }

      let count = parseInt( data[0] );
      let max = parseInt( data[1] );
      let running = parseInt( data[2] );
      let status = data[3];
      let dialog_config = {};
      try {
        dialog_config = JSON.parse( data[4] );
      }
      catch( e ) {
        dialog_config = {};
      }

      // show progress.
      if( max > 0 ) {
        jQuery( '#progress' ).attr( 'value', (count / max) * 100 );
      }
      jQuery( '#progress_status' ).html( status );

      /**
       * If import is still running, get next info in 500ms.
       * If import is not running and error occurred, show the error.
       * If import is not running and no error occurred, show ok-message.
       */
      if (running >= 1 || threadi_now_pages_import_running) {
        threadi_now_pages_progress_timeout = setTimeout( function () {
          threadi_now_pages_ajax_process( config )
        }, 500 );
      } else if( dialog_config && dialog_config.detail ) {
        threadi_now_pages_create_dialog( dialog_config );
      } else {
        threadi_now_pages_stop_with_error( '' );
      }
    }
  } )
}
