jQuery(document).ready(function($) {
  // add option near to list-headline, if the user is allowed to run the import.
  if( nowPagesThreadiJsVars.import_url.length > 0 ) {
    $('body.post-type-np_threadi_entry.edit-php h1.wp-heading-inline, body.post-type-np_threadi_source.edit-php h1.wp-heading-inline, body.post-type-np_threadi_source.post-php h1.wp-heading-inline, body.settings_page_now_pages_threadi_settings h1').after('<a class="page-title-action now-pages-threadi-import" href="' + nowPagesThreadiJsVars.import_url + '">' + nowPagesThreadiJsVars.title_run_import + '</a>');
  }
  $('body.post-type-np_threadi_entry:not(.now-pages-threadi-hide-buttons) h1, body.post-type-np_threadi_source:not(.now-pages-threadi-hide-buttons) h1, body.settings_page_now_pages_threadi_settings:not(.now-pages-threadi-hide-buttons) h1').each(function() {
    let button = document.createElement('a');
    button.className = 'review-hint-button page-title-action';
    button.href = nowPagesThreadiJsVars.review_url;
    button.innerHTML = nowPagesThreadiJsVars.title_rate_us;
    button.target = '_blank';
    this.after(button);
  })

  /**
   * Force to reload the page if for a source a new provider is chosen.
   */
  $('body.post-new-php.post-type-np_threadi_source #now-pages-threadi-provider').on('change', function() {
    if( this.value.length > 0 ) {
      jQuery( '#post' ).submit();
    }
  });

  $('.now-pages-threadi-import').on('click', function( e ) {
    e.preventDefault();
    now_pages_threadi_get_import_dialog();
  })
});

/**
 * Send testmail via AJAX.
 */
function now_pages_threadi_send_testmail( obj_name ) {
  // get the dialog via AJAX.
  jQuery.ajax({
    type: "POST",
    url: nowPagesThreadiJsVars.ajax_url,
    data: {
      'action': 'now_pages_threadi_sent_test_email',
      'nonce': nowPagesThreadiJsVars.test_email_nonce,
      'object': obj_name
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      now_pages_threadi_ajax_error_dialog( errorThrown )
    },
    success: function( result ) {
      now_pages_threadi_create_dialog( result );
    }
  });
}

/**
 * Define dialog for AJAX-errors.
 */
function now_pages_threadi_ajax_error_dialog( errortext, texts ) {
  if( errortext === undefined || errortext.length === 0 ) {
    errortext = nowPagesThreadiJsVars.generate_error_text;
  }
  let message = '<p>' + nowPagesThreadiJsVars.txt_error + '</p>';
  message = message + '<ul>';
  if( texts && texts[errortext] ) {
    message = message + '<li>' + texts[errortext] + '</li>';
  }
  else {
    message = message + '<li>' + errortext + '</li>';
  }
  message = message + '</ul>';

  // show dialog.
  let dialog_config = {
    detail: {
      title: nowPagesThreadiJsVars.title_error,
      texts: [
        message
      ],
      buttons: [
        {
          'action': 'location.reload();',
          'variant': 'primary',
          'text': nowPagesThreadiJsVars.lbl_ok
        }
      ]
    }
  }
  now_pages_threadi_create_dialog( dialog_config );
}

/**
 * Get the import dialog via AJAX.
 */
function now_pages_threadi_get_import_dialog() {
  // get the dialog via AJAX.
  jQuery.ajax({
    type: "POST",
    url: nowPagesThreadiJsVars.ajax_url,
    data: {
      'action': 'now_pages_threadi_get_import_dialog',
      'nonce': nowPagesThreadiJsVars.get_import_dialog_nonce
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      now_pages_threadi_ajax_error_dialog( errorThrown )
    },
    success: function( result ) {
      now_pages_threadi_create_dialog( result );
    }
  });
}

/**
 * Helper to create a new dialog with given config.
 *
 * @param config
 */
function now_pages_threadi_create_dialog( config ) {
  document.body.dispatchEvent(new CustomEvent("easy-dialog-for-wordpress", config));
}
