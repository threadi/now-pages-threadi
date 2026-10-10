/**
 * Import the necessary libraries.
 */
import { Brizy } from "@brizy/core";
import { useEffect, useState, JSX } from "react";

/**
 * Return the editor data.
 */
interface TemplateChoice { title: string; value: string; }
interface EditorData {
	i18n?: Record<string, string>;
	restUrl?: string;
	nonce?: string;
	periods?: TemplateChoice[];
	formats?: TemplateChoice[];
	default_period?: string;
    default_format?: string;
}
let cachedEditorData: EditorData | null = null;

const getEditorData = (): EditorData => {
	if (typeof window === "undefined") return cachedEditorData ?? {};

	const current = (window as unknown as { nowPagesThreadiBrizy?: EditorData }).nowPagesThreadiBrizy;

	// Nur übernehmen, wenn tatsächlich Daten da sind – ein leeres/undefined
	// Objekt in einem späteren Aufruf überschreibt den Cache nicht.
	if (current && Object.keys(current).length > 0) {
		cachedEditorData = current;
	}

	return cachedEditorData ?? current ?? {};
};

const __ = (text: string, _textdomain: string): string => {
	const data = getEditorData();

	return data.i18n?.[text] ?? text;
};

/**
 * Props shared by editor and view component (comes from the model,
 * key "template" matches the control id defined below).
 */
interface WidgetProps {
	format?: string;
	period?: string;
    override_display?: boolean;
    show_time?: boolean;
    show_source?: boolean;
    link_source?: boolean;
    show_period?: boolean;
    group_by_category?: boolean;
}

/**
 * Define output in frontend via our Provider class.
 * The chosen template is passed along as data-attribute, so the
 * PHP-side rendering (shortcode/content-filter) knows which template to use.
 *
 * @constructor
 */
const WidgetView = ({ format, period, override_display, show_time, show_source, link_source, show_period, group_by_category }: WidgetProps): JSX.Element => (
	<div className="now-pages-threadi-digest">
		{`{{ now_pages_threadi_digest format="${format ?? ""}" period="${period ?? ""}" override_display="${String(override_display) ?? ""}" show_time="${String(show_time) ?? ""}" show_source="${String(show_source) ?? ""}" link_source="${String(link_source) ?? ""}" show_period="${String(show_period) ?? ""}" group_by_category="${String(group_by_category) ?? ""}" }}`}
	</div>
);

/**
 * Define output in editor via AJAX request to our custom AJAX endpoint.
 *
 * @constructor
 */
const WidgetEditor = ({ format, period, override_display, show_time, show_source, link_source, show_period, group_by_category }: WidgetProps): JSX.Element => {
	const [html, setHtml] = useState<string>("");

	// request the content whenever the selected template changes.
	useEffect(() => {
		let cancelled = false;

		// build the URL. the route is appended to the whole REST URL, so it
		// also works without pretty permalinks (?rest_route=...). only path
		// and query are taken over, the request stays on the current origin.
		const data = getEditorData();
		const target = data.restUrl
			? new URL(data.restUrl + "digest", window.location.origin)
			: new URL("/wp-json/now-pages-threadi/v1/digest", window.location.origin);
		const url = new URL(target.pathname + target.search, window.location.origin);
		if (format) {
			url.searchParams.set("format", format);
		}
		if (period) {
			url.searchParams.set("period", period);
		}
        if (override_display) {
            url.searchParams.set("override_display", String(override_display));
        }
        if (show_time) {
            url.searchParams.set("show_time", String(show_time));
        }
        if (show_source) {
            url.searchParams.set("show_source", String(show_source));
        }
        if (link_source) {
            url.searchParams.set("link_source", String(link_source));
        }
        if (show_period) {
            url.searchParams.set("show_period", String(show_period));
        }
        if (group_by_category) {
            url.searchParams.set("group_by_category", String(group_by_category));
        }

		// send request.
		fetch(url.toString(), {
			credentials: "same-origin",
			headers: data.nonce ? { "X-WP-Nonce": data.nonce } : {},
		})
			.then((r) => r.json())
			.then((h) => { if (!cancelled) setHtml(typeof h === "string" ? h : ""); })
			.catch(() => { if (!cancelled) setHtml(""); });

		return () => { cancelled = true; };
	}, [format, period, override_display, show_time, show_source, link_source, show_period, group_by_category]);

    // define the output depending on response.
    return html
            ? <div className="now-pages-threadi-digest" dangerouslySetInnerHTML={{ __html: html }} />
            : <div className="now-pages-threadi-digest">{__('Now Pages: Digest (Preview)', 'now-pages-threadi' )}</div>;
};

/**
 * Register this component.
 */
Brizy.registerComponent({
	id: "Now.Pages.Digest",
	component: {
		editor: WidgetEditor,
		view: WidgetView
	},
	title: __( 'Digest', 'now-pages-threadi' ),
	category: "now-pages-threadi",
	options: () => {
		const data = getEditorData();

		return [
			{
				selector: ".now-pages-threadi-digest",
				toolbar: [
					{
						id: "toolbarSettings",
						type: "popover",
						config: { icon: "nc-cog", title: __("Settings", "now-pages-threadi") },
						position: 20,
						options: [
							{
								id: "openNowPagesTab",
								type: "sidebarTabsButton",
								config: {
									tabId: "nowPagesThreadiTab",
									text: __("Settings", "now-pages-threadi"),
									icon: "nc-cog",
								},
							},
						],
					},
				],
				sidebar: [
					{
						id: "sidebarTabs",
						type: "sidebarTabs",
						tabs: [
							{
								id: "nowPagesThreadiTab",
								title: __("Settings", "now-pages-threadi"),
								label: __("Settings", "now-pages-threadi"),
								options: [
									{
										id: "period",
										type: "select",
										label: __("Period", "now-pages-threadi"),
										choices: data.periods,
										default: data.default_period ?? "",
									},
									{
										id: "format",
										type: "select",
										label: __("Format", "now-pages-threadi"),
										choices: data.formats,
										default: data.default_format ?? "",
									},
                                    {
                                        id: "override_display",
                                        type: "switch",
                                        label: __("Use own display settings", "now-pages-threadi"),
                                        default: {
                                            value: "off",
                                        },
                                    },
                                    {
                                        id: "show_time",
                                        type: "switch",
                                        label: __("Show times", "now-pages-threadi"),
                                        default: {
                                            value: "off",
                                        },
                                    },
                                    {
                                        id: "show_source",
                                        type: "switch",
                                        label: __("Show data source", "now-pages-threadi"),
                                        default: {
                                            value: "off",
                                        },
                                    },
                                    {
                                        id: "link_source",
                                        type: "switch",
                                        label: __("Link the data source", "now-pages-threadi"),
                                        default: {
                                            value: "off",
                                        },
                                    },
                                    {
                                        id: "show_period",
                                        type: "switch",
                                        label: __("Show period of entries", "now-pages-threadi"),
                                        default: {
                                            value: "on",
                                        },
                                    },
                                    {
                                        id: "group_by_category",
                                        type: "switch",
                                        label: __("Group by category", "now-pages-threadi"),
                                        default: {
                                            value: "on",
                                        },
                                    },
								],
							},
						],
					},
				],
			},
		];
	},
});
