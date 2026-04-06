import { registerBlockType, unregisterBlockType, getBlockType } from '@wordpress/blocks';
import metadata from '../../block.json';
import edit from './edit';
import save from './save';

// PHP may inject admin-configured attribute defaults (e.g. responsive settings)
// as window.flickrJustifiedDefaults. Apply them over block.json's static defaults.
const phpDefaults = window.flickrJustifiedDefaults || {};

const blockSettings = {
	...metadata,
	edit,
	save,
};

if ( phpDefaults.responsiveSettings && blockSettings.attributes?.responsiveSettings ) {
	blockSettings.attributes = {
		...blockSettings.attributes,
		responsiveSettings: {
			...blockSettings.attributes.responsiveSettings,
			default: phpDefaults.responsiveSettings,
		},
	};
}

// If the block was already registered (e.g. by another script),
// unregister first to avoid a duplicate-type error.
const existingBlock = getBlockType( metadata.name );
if ( existingBlock ) {
	unregisterBlockType( metadata.name );
}
registerBlockType( metadata.name, blockSettings );
