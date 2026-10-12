import { addAction } from '@wordpress/hooks';
const { registerModule } = window?.divi?.moduleLibrary;

/**
 * Import our modules.
 */
import { digest, digestMetadata } from "./digest";

/**
 * Register our modules.
 */
addAction('divi.moduleLibrary.registerModuleLibraryStore.after', 'threadi-now-pages', () => {
	registerModule(digestMetadata, digest);
});
