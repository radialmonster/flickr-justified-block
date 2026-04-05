/**
 * Automatic image fallback handler for Flickr photos
 * Detects 404 errors and fetches fresh URLs from Flickr API
 */

import { getAjaxUrl, getFallbackNonce } from './config';
import { initJustifiedGallery } from './layout';

const attemptedRefresh = new WeakSet();

function extractPhotoId( img ) {
	const card = img.closest( '.flickr-justified-card' );
	if ( card && card.dataset.photoId ) {
		return card.dataset.photoId;
	}

	const link = img.closest( 'a' );
	if ( link && link.dataset.photoId ) {
		return link.dataset.photoId;
	}

	const srcMatch = img.src.match(
		/\/(\d{8,})_[a-f0-9]+_[a-z]\.(?:jpe?g|png|webp)/i
	);
	if ( srcMatch && srcMatch[ 1 ] ) {
		return srcMatch[ 1 ];
	}

	if ( link ) {
		const pageMatch = link.href.match(
			/flickr\.com\/photos\/[^/]+\/(\d{8,})/i
		);
		if ( pageMatch && pageMatch[ 1 ] ) {
			return pageMatch[ 1 ];
		}
		const hrefMatch = link.href.match(
			/\/(\d{8,})_[a-f0-9]+_[a-z]\.(?:jpe?g|png|webp)/i
		);
		if ( hrefMatch && hrefMatch[ 1 ] ) {
			return hrefMatch[ 1 ];
		}
	}

	return null;
}

function getSizeSuffix( url ) {
	const match = url.match( /_([a-z])\.(?:jpe?g|png|webp)$/i );
	return match ? match[ 1 ] : 'b';
}

async function fetchFreshUrl( photoId, size = 'large' ) {
	const ajaxUrl = getAjaxUrl();
	const fallbackNonce = getFallbackNonce();
	if ( ! ajaxUrl || ! fallbackNonce ) {
		console.error(
			'Flickr Justified Block: AJAX URL or nonce not configured. Image fallback will not work.'
		);
		return null;
	}

	const formData = new URLSearchParams();
	formData.append( 'action', 'flickr_justified_refresh_photo_url' );
	formData.append( 'photo_id', photoId );
	formData.append( 'size', size );
	formData.append( 'nonce', fallbackNonce );

	try {
		const response = await fetch( ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: formData,
			credentials: 'same-origin',
		} );

		if ( ! response.ok ) {
			if ( response.status === 429 ) {
				return null;
			}
			throw new Error( `HTTP ${ response.status }` );
		}

		const data = await response.json();

		if ( data.success && data.data && data.data.url ) {
			return data.data;
		}
		return null;
	} catch ( error ) {
		console.error(
			`Error fetching fresh URL for photo ${ photoId }:`,
			error
		);
		return null;
	}
}

async function handleImageError( event ) {
	const img = event.target;

	if ( attemptedRefresh.has( img ) ) {
		return;
	}

	attemptedRefresh.add( img );

	const photoId = extractPhotoId( img );
	if ( ! photoId ) {
		return;
	}

	const sizeSuffix = getSizeSuffix( img.src );
	const sizeMap = {
		o: 'original',
		k: 'large2048',
		h: 'large1600',
		l: 'large1024',
		c: 'medium800',
		z: 'medium640',
		m: 'medium500',
		n: 'small320',
		s: 'small240',
		t: 'thumbnail100',
		q: 'thumbnail150s',
		sq: 'thumbnail75s',
		b: 'large',
	};
	const size = sizeMap[ sizeSuffix ] || 'large';

	const freshData = await fetchFreshUrl( photoId, size );

	if ( ! freshData || ! freshData.url ) {
		img.alt = 'Image unavailable';
		img.style.opacity = '0.3';
		return;
	}

	img.removeAttribute( 'srcset' );
	img.removeAttribute( 'sizes' );

	img.src = freshData.url;
	if ( freshData.width ) {
		img.setAttribute(
			'srcset',
			`${ freshData.url } ${ freshData.width }w`
		);
	}

	if ( freshData.width && freshData.height ) {
		img.setAttribute( 'data-width', freshData.width );
		img.setAttribute( 'data-height', freshData.height );
	}

	const link = img.closest( 'a' );
	if ( link && link.href.includes( 'staticflickr.com' ) ) {
		const lightboxData = await fetchFreshUrl( photoId, 'original' );
		if ( lightboxData && lightboxData.url ) {
			link.href = lightboxData.url;
			if ( lightboxData.width && lightboxData.height ) {
				link.setAttribute( 'data-width', lightboxData.width );
				link.setAttribute( 'data-height', lightboxData.height );
			}
		}
	}

	const gallery = img.closest( '.flickr-justified-grid' );
	if ( gallery ) {
		gallery.classList.remove( 'justified-initialized' );
		setTimeout( () => {
			initJustifiedGallery();
		}, 100 );
	}

	if ( gallery ) {
		const evt = new CustomEvent( 'flickr-gallery-updated', {
			detail: { gallery },
		} );
		document.dispatchEvent( evt );
	}
}

function initImageFallback() {
	const images = document.querySelectorAll( '.flickr-justified-grid img' );

	images.forEach( ( img ) => {
		img.addEventListener( 'error', handleImageError );

		if (
			img.complete &&
			img.naturalWidth === 0 &&
			! attemptedRefresh.has( img )
		) {
			handleImageError( { target: img } );
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initImageFallback );
} else {
	initImageFallback();
}

document.addEventListener( 'flickr-gallery-updated', () => {
	initImageFallback();
} );

if ( typeof MutationObserver !== 'undefined' ) {
	const observer = new MutationObserver( ( mutations ) => {
		const hasNewGalleries = mutations.some( ( m ) =>
			Array.from( m.addedNodes ).some(
				( n ) =>
					n.nodeType === 1 &&
					( ( n.classList &&
						n.classList.contains(
							'flickr-justified-grid'
						) ) ||
						( n.querySelector &&
							n.querySelector(
								'.flickr-justified-grid'
							) ) )
			)
		);

		if ( hasNewGalleries ) {
			setTimeout( initImageFallback, 200 );
		}
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
}
