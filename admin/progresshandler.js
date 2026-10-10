/**
 * Initiate the import of new entries.
 */
function now_pages_threadi_import( process_id ) {
  now_pages_threadi_start_ajax_process( {
    'start_action': 'now_pages_threadi_import',
    'start_nonce': nowPagesThreadiProgressHandlerJsVars.start_nonce,
    'info_action': 'now_pages_threadi_process_info',
    'info_nonce': nowPagesThreadiProgressHandlerJsVars.process_info_nonce,
    'process_title': nowPagesThreadiProgressHandlerJsVars.title,
    'process_id': process_id
  });
}

let now_pages_threadi_import_running = false;
// marker for a failed start request: stops the polling.
let now_pages_threadi_import_failed = false;
// marker for progress timeout.
let now_pages_threadi_progress_timeout = false;

/**
 * Function to handle any AJAX process.
 */
function now_pages_threadi_start_ajax_process( config ) {
  // start the process.
  jQuery.ajax({
    type: "POST",
    url: nowPagesThreadiProgressHandlerJsVars.ajax_url,
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
      now_pages_threadi_create_dialog( dialog_config );

      // mark in JS as running.
      now_pages_threadi_import_running = true;
      now_pages_threadi_import_failed = false;

      // get info about progress.
      now_pages_threadi_progress_timeout = setTimeout(function() { now_pages_threadi_ajax_process( config ) }, 1000 );
    },
    success: function( response ) {
      if( response && response.load_more ) {
        clearTimeout(now_pages_threadi_progress_timeout);
        now_pages_threadi_start_ajax_process( config );
        return;
      }

      // mark import as not running.
      now_pages_threadi_import_running = false;

      // stop if the server did not accept the request (e.g. "0", "-1" or success = false).
      if( ! response || typeof response !== 'object' || false === response.success ) {
        now_pages_threadi_stop_with_error( '' );
      }
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      // mark import as not running and stop the polling.
      now_pages_threadi_import_running = false;
      now_pages_threadi_stop_with_error( errorThrown );
    }
  });
}

/**
 * Stop the polling and show an error.
 */
function now_pages_threadi_stop_with_error( errortext ) {
  // bail if this has already been done.
  if( now_pages_threadi_import_failed ) {
    return;
  }

  now_pages_threadi_import_failed = true;
  clearTimeout( now_pages_threadi_progress_timeout );
  now_pages_threadi_ajax_error_dialog( errortext );
}

/**
 * Get info until import is done.
 */
function now_pages_threadi_ajax_process( config ) {
  // bail if the start request failed.
  if( now_pages_threadi_import_failed ) {
    return;
  }

  jQuery.ajax( {
    type: "POST",
    url: nowPagesThreadiProgressHandlerJsVars.ajax_url,
    data: {
      'action': config.info_action,
      'nonce': config.info_nonce,
      'process_id': config.process_id
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      now_pages_threadi_stop_with_error( errorThrown );
    },
    success: function (data) {
      // bail if the start request failed meanwhile.
      if( now_pages_threadi_import_failed ) {
        return;
      }

      // bail on an unexpected answer (e.g. "0", "-1" or success = false).
      if( ! Array.isArray( data ) || data.length < 5 ) {
        now_pages_threadi_stop_with_error( '' );
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
      if (running >= 1 || now_pages_threadi_import_running) {
        now_pages_threadi_progress_timeout = setTimeout( function () {
          now_pages_threadi_ajax_process( config )
        }, 500 );
      } else if( dialog_config && dialog_config.detail ) {
        now_pages_threadi_create_dialog( dialog_config );
      } else {
        now_pages_threadi_stop_with_error( '' );
      }
    }
  } )
}
