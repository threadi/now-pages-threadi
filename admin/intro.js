( function () {
  'use strict';

  // bail if the tour should not run on this page.
  if ( ! document.body.classList.contains( 'threadi-now-pages-show-intro' ) ) {
    return;
  }

  // bail if the library or our data did not load.
  if ( typeof window.driver === 'undefined' || typeof threadiNowPagesIntroVars === 'undefined' ) {
    return;
  }

  /**
   * Turn our steps into the shape driver.js expects.
   */
  const steps = threadiNowPagesIntroVars.steps.map( function ( step ) {
    const result = {
      popover: {
        title: step.title,
        description: step.description
      }
    };

    // a step without an element is shown centered, without an anchor.
    if ( step.element ) {
      result.element = step.element;
    }

    // open the submenu before pointing at one of its entries, and close
    // it again afterward - it is collapsed on every other page too.
    if ( step.open_menu ) {
      result.onHighlightStarted = function () {
        const menu = document.querySelector( step.open_menu );

        if ( menu ) {
          menu.classList.add( 'opensub' );
        }

        // WordPress fixes the admin menu, so the page does not scroll
        // to it — the element has to bring itself into view.
        const target = document.querySelector( step.element );

        if ( target ) {
          target.scrollIntoView( { block: 'center', behavior: 'smooth' } );
        }
      };

      result.onDeselected = function () {
        const menu = document.querySelector( step.open_menu );

        if ( menu ) {
          menu.classList.remove( 'opensub' );
        }
      };
    }

    return result;
  } );

  /**
   * Remember that the tour is done, so it does not start again.
   */
  const markAsClosed = function () {
    const data = new FormData();

    data.append( 'action', 'threadi_now_pages_intro_closed' );
    data.append( 'nonce', threadiNowPagesIntroVars.intro_closed_nonce );

    fetch( threadiNowPagesIntroVars.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    } ).catch( function () {
      // nothing to do: the tour simply starts again next time.
    } );
  };

  const tour = window.driver.js.driver( {
    showProgress: true,
    nextBtnText: threadiNowPagesIntroVars.next_button,
    prevBtnText: threadiNowPagesIntroVars.prev_button,
    doneBtnText: threadiNowPagesIntroVars.done_button,
    steps: steps,
    onDestroyed: markAsClosed
  } );

  tour.drive();
} )();
