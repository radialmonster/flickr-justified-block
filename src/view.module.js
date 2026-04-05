/**
 * Flickr Justified Gallery - ES Module Entry Point
 *
 * Registered via block.json "viewScriptModule" and auto-enqueued by WP
 * when the block is rendered on the frontend.
 */

import { initJustifiedGallery } from './frontend/layout';
import { initFlickrAlbumLazyLoading } from './frontend/lazy-loading';

// Side-effect imports — each module self-initializes
import './frontend/photoswipe-init';
import './frontend/image-fallback';
import './frontend/async-loader';

function initialize() {
	initJustifiedGallery();

	// Defer lazy-loading init until after layout paint completes
	requestAnimationFrame( () => {
		initFlickrAlbumLazyLoading();
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initialize );
} else {
	initialize();
}
