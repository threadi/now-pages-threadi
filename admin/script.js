jQuery(document).ready(function($) {
  // add option near to list-headline, if the user is allowed to run the import.
  if( threadiNowPagesJsVars.import_url.length > 0 ) {
    $('body.post-type-threadi_np_entry.edit-php h1.wp-heading-inline, body.post-type-threadi_np_source.edit-php h1.wp-heading-inline, body.post-type-threadi_np_source.post-php h1.wp-heading-inline, body.settings_page_threadi_now_pages_settings h1').after('<a class="page-title-action threadi-now-pages-import" href="' + threadiNowPagesJsVars.import_url + '">' + threadiNowPagesJsVars.title_run_import + '</a>');
  }
  $('body.post-type-threadi_np_entry:not(.threadi-now-pages-hide-buttons) h1, body.post-type-threadi_np_source:not(.threadi-now-pages-hide-buttons) h1, body.settings_page_threadi_now_pages_settings:not(.threadi-now-pages-hide-buttons) h1').each(function() {
    let button = document.createElement('a');
    button.className = 'review-hint-button page-title-action';
    button.href = threadiNowPagesJsVars.review_url;
    button.innerHTML = threadiNowPagesJsVars.title_rate_us;
    button.target = '_blank';
    this.after(button);
  })

  /**
   * Force to reload the page if for a source a new provider is chosen.
   */
  $('body.post-new-php.post-type-threadi_np_source #threadi-now-pages-provider').on('change', function() {
    if( this.value.length > 0 ) {
      jQuery( '#post' ).submit();
    }
  });

  $('.threadi-now-pages-import').on('click', function( e ) {
    e.preventDefault();
    threadi_now_pages_get_import_dialog();
  })
});

/**
 * Send testmail via AJAX.
 */
function threadi_now_pages_send_testmail( obj_name ) {
  // get the dialog via AJAX.
  jQuery.ajax({
    type: "POST",
    url: threadiNowPagesJsVars.ajax_url,
    data: {
      'action': 'threadi_now_pages_sent_test_email',
      'nonce': threadiNowPagesJsVars.test_email_nonce,
      'object': obj_name
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      threadi_now_pages_ajax_error_dialog( errorThrown )
    },
    success: function( result ) {
      threadi_now_pages_create_dialog( result );
    }
  });
}

/**
 * Define dialog for AJAX-errors.
 */
function threadi_now_pages_ajax_error_dialog( errortext, texts ) {
  if( errortext === undefined || errortext.length === 0 ) {
    errortext = threadiNowPagesJsVars.generate_error_text;
  }
  let message = '<p>' + threadiNowPagesJsVars.txt_error + '</p>';
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
      title: threadiNowPagesJsVars.title_error,
      texts: [
        message
      ],
      buttons: [
        {
          'action': 'location.reload();',
          'variant': 'primary',
          'text': threadiNowPagesJsVars.lbl_ok
        }
      ]
    }
  }
  threadi_now_pages_create_dialog( dialog_config );
}

/**
 * Get the import dialog via AJAX.
 */
function threadi_now_pages_get_import_dialog() {
  // get the dialog via AJAX.
  jQuery.ajax({
    type: "POST",
    url: threadiNowPagesJsVars.ajax_url,
    data: {
      'action': 'threadi_now_pages_get_import_dialog',
      'nonce': threadiNowPagesJsVars.get_import_dialog_nonce
    },
    error: function( jqXHR, textStatus, errorThrown ) {
      threadi_now_pages_ajax_error_dialog( errorThrown )
    },
    success: function( result ) {
      threadi_now_pages_create_dialog( result );
    }
  });
}

/**
 * Helper to create a new dialog with given config.
 *
 * @param config
 */
function threadi_now_pages_create_dialog( config ) {
  document.body.dispatchEvent(new CustomEvent("easy-dialog-for-wordpress", config));
}
