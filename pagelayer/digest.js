/**
 * Render the digest widget of Now Pages in the editor of Pagelayer.
 *
 * Pagelayer renders a widget in the editor from its HTML template. It looks
 * for a global function named "pagelayer_render_" followed by the tag of the
 * widget and calls it before the template is filled. This function asks the
 * server for the output with the current settings and puts it into the
 * widget once it arrives.
 *
 * @package now-pages-threadi
 */
(() => {
  'use strict';

  // the data from PHP, see Pagelayer::add_editor_scripts().
  const data = window.nowPagesThreadiPagelayer || {};

  // bail if the data is missing.
  if (!data.tag) {
    return;
  }

  // the settings which are sent to the server.
  const names = [
    'period',
    'format',
    'override_display',
    'show_time',
    'show_source',
    'link_source',
    'show_period',
    'group_by_category',
  ];

  // the timers per element, so a fast series of changes ends in one request.
  const timers = {};

  // the settings of the last request per element.
  const requested = {};

  /**
   * Show the given HTML in the widget and remember it for the next rendering.
   *
   * @param el The element of Pagelayer.
   * @param html The HTML to show.
   */
  const show = (el, html) => {
    // remember it: Pagelayer fills the template on each change from these values,
    // they are not saved with the page.
    if ('function' === typeof window.pagelayer_el_data_ref) {
      window.pagelayer_el_data_ref(el.$).tmp.digest = html;
    }

    el.$.find('.now-pages-threadi-pagelayer-digest').html(html);
  };

  /**
   * Render the widget.
   *
   * @param el The element of Pagelayer.
   */
  window['pagelayer_render_' + data.tag] = (el) => {
    // show the last output until the new one arrives, or a hint for a new widget.
    if (!el.tmp || 'undefined' === typeof el.tmp.digest) {
      el.tmp = el.tmp || {};
      el.tmp.digest = '<p>' + data.loading + '</p>';
    }

    // collect the settings.
    const request = {
      action: data.action,
      pagelayer_nonce: window.pagelayer_ajax_nonce,
    };
    names.forEach((name) => {
      if (el.atts && 'undefined' !== typeof el.atts[name]) {
        request[name] = el.atts[name];
      }
    });

    // bail if the settings did not change since the last request: Pagelayer renders
    // the widget also after a change of its styles, which does not change the entries.
    const key = JSON.stringify(request);
    if (key === requested[el.id]) {
      return;
    }
    requested[el.id] = key;

    // wait for further changes before asking the server.
    clearTimeout(timers[el.id]);
    timers[el.id] = setTimeout(() => {
      jQuery.ajax({
        url: window.pagelayer_ajax_url + '&action=' + data.action,
        type: 'POST',
        data: request,
        success: (response) => {
          if (!response || !response.success || !response.data) {
            show(el, '<p>' + data.error + '</p>');
            return;
          }

          show(el, response.data.html);
        },
        error: () => {
          show(el, '<p>' + data.error + '</p>');
        },
      });
    }, 500);
  };
})();
