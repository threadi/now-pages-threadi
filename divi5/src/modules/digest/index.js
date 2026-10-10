import { edit } from './edit';
import metadata from './module.json';

export const digestMetadata = metadata;

/**
 * Define the main object for this module.
 *
 * No settings components here: the fields are declared in module.json and
 * Divi builds the panels from them, which is what "content": "auto" and
 * its siblings in that file are for. Passing components would replace
 * that generated output with whatever they render.
 *
 * @type {{renderers: {edit: function({attrs: *, elements: *, id: *, name: *}): *}}}
 */
export const digest = {
  renderers: {
    edit: edit,
  },
};
