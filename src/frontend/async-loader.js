/**
 * Flickr Justified Gallery - Async Loader
 * Loads gallery content via AJAX for large uncached albums
 */

import { initJustifiedGallery } from './layout';
import { initFlickrAlbumLazyLoading } from './lazy-loading';
import { getAjaxUrl, getAsyncNonce } from './config';

function decodeAttributes( container ) {
	const rawB64 = container.getAttribute( 'data-attributes-b64' );
	if ( ! rawB64 ) {
		return null;
	}
	try {
		const decoded = atob( rawB64 );
		return JSON.parse( decoded );
	} catch ( e ) {
		console.error( 'Flickr Gallery: Failed to decode attributes', e );
		return null;
	}
}

function setStatus( container, text ) {
	const textEl = container.querySelector( '.flickr-loading-text' );
	if ( textEl ) {
		textEl.textContent = text;
	}
}

function initGallery( newBlock ) {
	try {
		initJustifiedGallery();
		setTimeout( () => {
			initFlickrAlbumLazyLoading();
			const event = new CustomEvent( 'flickr-gallery-updated', {
				detail: { gallery: newBlock },
			} );
			document.dispatchEvent( event );
			if ( newBlock && newBlock.focus ) {
				newBlock.setAttribute( 'tabindex', '-1' );
				newBlock.focus( { preventScroll: true } );
			}
		}, 200 );
	} catch ( e ) {
		console.error(
			'Flickr Gallery: Initialization failed after async load',
			e
		);
	}
}

function loadGallery( container ) {
	container.setAttribute( 'aria-busy', 'true' );
	const retryBtn = container.querySelector( '.flickr-loading-retry-btn' );
	if ( retryBtn ) {
		retryBtn.style.display = 'none';
	}

	const attrs = decodeAttributes( container );
	if ( ! attrs ) {
		setStatus( container, 'Missing gallery data' );
		if ( retryBtn ) {
			retryBtn.style.display = 'inline-block';
		}
		return;
	}

	let postId = '';
	const bodyClasses = document.body.className.match( /postid-(\d+)/ );
	if ( bodyClasses && bodyClasses[ 1 ] ) {
		postId = bodyClasses[ 1 ];
	}

	const form = new URLSearchParams();
	form.set( 'action', 'flickr_justified_load_async' );
	form.set( 'attributes', JSON.stringify( attrs ) );
	form.set( 'post_id', postId );
	form.set( 'nonce', getAsyncNonce() );

	fetch(
		getAjaxUrl(),
		{
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			credentials: 'same-origin',
			body: form.toString(),
		}
	)
		.then( ( res ) => {
			if ( res.status === 429 ) {
				const retryAfter = parseInt(
					res.headers.get( 'Retry-After' ) || '5',
					10
				);
				throw Object.assign( new Error( 'Rate limited' ), {
					retryAfter: retryAfter * 1000,
				} );
			}
			if ( res.status === 401 || res.status === 403 ) {
				throw Object.assign(
					new Error(
						'Authorization expired. Please refresh the page.'
					),
					{ noRetry: true }
				);
			}
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} )
		.then( ( response ) => {
			if (
				response &&
				response.success &&
				response.data &&
				response.data.html
			) {
				// container reference becomes stale after outerHTML replacement — re-query below
				container.outerHTML = response.data.html;
				const newBlock = attrs._target_gallery_id
					? document.getElementById( attrs._target_gallery_id )
					: document.querySelector( '.flickr-justified-grid' );
				if ( newBlock ) {
					initGallery( newBlock );
				}
			} else {
				throw new Error( 'Bad response' );
			}
		} )
		.catch( ( err ) => {
			console.error( 'Flickr Gallery: Async load failed', err );
			if ( err.noRetry ) {
				setStatus( container, err.message );
			} else {
				setStatus( container, 'Failed to load gallery' );
			}
			container.setAttribute( 'aria-busy', 'false' );
			if ( retryBtn && ! err.noRetry ) {
				retryBtn.style.display = 'inline-block';
			}
		} );
}

function attach( container ) {
	if ( container.getAttribute( 'data-fjb-processed' ) ) {
		return;
	}
	container.setAttribute( 'data-fjb-processed', '1' );

	const retryBtn = container.querySelector( '.flickr-loading-retry-btn' );
	if ( retryBtn ) {
		retryBtn.addEventListener( 'click', () => {
			loadGallery( container );
		} );
	}

	loadGallery( container );
}

function scan() {
	const nodes = document.querySelectorAll( '.flickr-justified-loading' );
	nodes.forEach( ( node ) => {
		attach( node );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', scan );
} else {
	scan();
}
