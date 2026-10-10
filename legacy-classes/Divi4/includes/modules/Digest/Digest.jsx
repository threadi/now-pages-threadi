// External Dependencies
import React, { Component } from 'react';

// Internal Dependencies
import './style.css';

/**
 * Function which wraps the ajax-request and processing the result.
 *
 * @param obj
 */
function get_html( obj ) {
    /**
     * Define form-data-object.
     *
     * @type {FormData}
     */
    let body = new FormData();
    body.append('action', 'now_pages_threadi_digest');
    body.append('et_admin_load_nonce', window.et_fb_options.et_admin_load_nonce);
    body.append('attributes', JSON.stringify(Object.assign({}, obj.props)));

    /**
     * Run ajax-request now.
     */
    fetch(
        window.et_fb_options.ajaxurl,
        {
            body: body,
            method: 'POST',
        }
    )
        .then(res => {
            if (!res.ok) {
                throw new Error('Request failed with status ' + res.status);
            }
            return res.json();
        })
        .then(
            (result) => {
                obj.setState({
                    isLoaded: true,
                    htmlString: result && 'string' === typeof result.htmlString ? result.htmlString : ''
                });
            }
        )
        .catch(
            (error) => {
                console.log(error);
                obj.setState({
                    isLoaded: true,
                    htmlString: ''
                });
            }
        )
}

/**
 * Main class to run for PersonioPosition-module in Divi-editor.
 */
class Digest extends Component {

    static slug = 'now_pages_threadi_digest';

    /**
     * Constructor.
     *
     * @param props
     */
    constructor(props) {
        super(props);
        this.state = {
            isLoaded: false,
            htmlString: ''
        };
    }

    /**
     * Get actual data via ajax until component is mount.
     */
    componentDidMount() {
        get_html(this);
    }

    /**
     * Get actual data via ajax on each update.
     *
     * @param prevProps
     * @param prevState
     */
    componentDidUpdate(prevProps,prevState) {
        if( false === Object.is( this.props, prevProps) ) {
            get_html(this);
        }
    }

    /**
     * Render the output until data is available.
     *
     * @returns {JSX.Element|string}
     */
    render() {
        const { isLoaded, htmlString } = this.state;

        if (!isLoaded) {
            return 'Loading'
        }
        else {
            let output = {__html:htmlString}
            return (
                <div dangerouslySetInnerHTML={ output }></div>
            )
        }
    }
}
export default Digest;
