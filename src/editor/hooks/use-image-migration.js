import { useEffect, useRef } from '@wordpress/element';
import { parseUrlsFromText, urlsToImages } from '../utils/url-helpers';

export default function useImageMigration( urls, images, setAttributes ) {
	const migratedRef = useRef( false );

	useEffect( () => {
		if ( migratedRef.current ) return;
		if ( ( ! images || images.length === 0 ) && urls && urls.trim() ) {
			const parsedUrls = parseUrlsFromText( urls );
			if ( parsedUrls.length > 0 ) {
				setAttributes( {
					images: urlsToImages( parsedUrls ),
					urls: '',
				} );
				migratedRef.current = true;
			}
		}
	}, [ urls, images ] );
}
