/**
 * Flickr Justified Gallery - Debug Logging Utility
 *
 * Gates console.log/warn output behind the WP_DEBUG flag,
 * which is passed via the script module data JSON tag.
 * console.error calls remain unconditional.
 */

import { getConfig } from './config';

let _debug = null;

function isDebug() {
	if ( _debug === null ) {
		_debug = !! getConfig().debug;
	}
	return _debug;
}

export function log( ...args ) {
	if ( isDebug() ) {
		console.log( '[FlickrGallery]', ...args );
	}
}

export function warn( ...args ) {
	if ( isDebug() ) {
		console.warn( '[FlickrGallery]', ...args );
	}
}
