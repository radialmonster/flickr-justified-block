export function generateId() {
	return Date.now().toString( 36 ) + Math.random().toString( 36 ).substr( 2, 9 );
}

export function parseUrlsFromText( text ) {
	if ( ! text || ! text.trim() ) return [];
	const lines = text.split( /[\r\n]+/ ).filter( ( l ) => l.trim() );
	const results = [];
	lines.forEach( ( line ) => {
		const matches = line.match( /https?:\/\/[^\s]+/gi );
		if ( matches && matches.length > 0 ) {
			matches.forEach( ( u ) => results.push( u.trim() ) );
		} else if ( line.trim() ) {
			results.push( line.trim() );
		}
	} );
	return results;
}

export function urlsToImages( urls ) {
	return urls.map( ( url ) => ( { id: generateId(), url, fullRow: false } ) );
}

export function isAlbumUrl( url ) {
	return /(?:www\.)?flickr\.com\/photos\/[^/]+\/(sets|albums)\/\d+/i.test( url );
}

export function isFlickrPhotoUrl( url ) {
	return (
		/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test( url ) &&
		! isAlbumUrl( url )
	);
}

export function isFlickrCdnUrl( url ) {
	return (
		/(?:live|farm\d*)\.staticflickr\.com\//i.test( url ) ||
		/flickr\.com\/photos\/[^/]+\/\d+\/sizes\//i.test( url )
	);
}

export function isSupportedUrl( url ) {
	if ( ! url ) return false;
	const trimmed = url.trim();
	if ( /(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test( trimmed ) )
		return true;
	if (
		/\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test( trimmed ) &&
		! isFlickrCdnUrl( trimmed )
	)
		return true;
	return false;
}

export function extractUrlsFromDropEvent( e ) {
	const flickrPageUrls = [];
	const otherUrls = [];

	function collectUrl( u ) {
		if ( ! isSupportedUrl( u ) ) return;
		if ( /(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+/i.test( u ) ) {
			flickrPageUrls.push( u );
		} else {
			otherUrls.push( u );
		}
	}

	function collectFromText( text ) {
		const found = parseUrlsFromText( text );
		found.forEach( collectUrl );
	}

	const uriList = e.dataTransfer.getData( 'text/uri-list' );
	if ( uriList ) {
		uriList.split( /[\r\n]+/ ).forEach( ( line ) => {
			line = line.trim();
			if ( line && line.charAt( 0 ) !== '#' ) collectFromText( line );
		} );
	}

	const plain = e.dataTransfer.getData( 'text/plain' );
	if ( plain ) collectFromText( plain );

	const html = e.dataTransfer.getData( 'text/html' );
	if ( html ) {
		const hrefMatches =
			html.match( /href="(https?:\/\/[^"]+)"/gi ) || [];
		hrefMatches.forEach( ( attr ) => {
			const m = attr.match( /"(https?:\/\/[^"]+)"/i );
			if ( m && m[ 1 ] ) collectUrl( m[ 1 ] );
		} );
		const srcMatches =
			html.match( /src="(https?:\/\/[^"]+)"/gi ) || [];
		srcMatches.forEach( ( attr ) => {
			const m = attr.match( /"(https?:\/\/[^"]+)"/i );
			if ( m && m[ 1 ] ) collectUrl( m[ 1 ] );
		} );
	}

	const urls = flickrPageUrls.length > 0 ? flickrPageUrls : otherUrls;

	const seen = {};
	return urls.filter( ( u ) => {
		if ( seen[ u ] ) return false;
		seen[ u ] = true;
		return true;
	} );
}
