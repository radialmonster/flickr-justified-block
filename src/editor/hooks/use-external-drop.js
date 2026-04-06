import { useState, useEffect, useRef } from '@wordpress/element';
import { extractUrlsFromDropEvent } from '../utils/url-helpers';

export default function useExternalDrop( blockBodyRef, handleAddImages ) {
	const [ externalDragOver, setExternalDragOver ] = useState( false );
	const externalDragCountRef = useRef( 0 );
	const addImagesRef = useRef( handleAddImages );
	addImagesRef.current = handleAddImages;

	useEffect( () => {
		const blockEl = blockBodyRef.current;
		if ( ! blockEl ) return;

		function isInsideBlock( e ) {
			return blockEl.contains( e.target );
		}

		function onDropCapture( e ) {
			if ( ! isInsideBlock( e ) ) return;
			const urls = extractUrlsFromDropEvent( e );
			if ( urls.length > 0 ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				addImagesRef.current( urls );
				externalDragCountRef.current = 0;
				setExternalDragOver( false );
			}
		}

		function onDragOverCapture( e ) {
			if ( ! isInsideBlock( e ) ) return;
			const types = e.dataTransfer.types || [];
			const hasExternal =
				types.indexOf( 'text/uri-list' ) !== -1 ||
				types.indexOf( 'text/plain' ) !== -1 ||
				types.indexOf( 'text/html' ) !== -1;
			if ( hasExternal ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				e.dataTransfer.dropEffect = 'copy';
			}
		}

		function onDragEnterCapture( e ) {
			if ( ! isInsideBlock( e ) ) return;
			e.preventDefault();
			externalDragCountRef.current++;
			setExternalDragOver( true );
		}

		function onDragLeaveCapture( e ) {
			if ( ! isInsideBlock( e ) ) return;
			externalDragCountRef.current--;
			if ( externalDragCountRef.current <= 0 ) {
				externalDragCountRef.current = 0;
				setExternalDragOver( false );
			}
		}

		const doc = blockEl.ownerDocument;

		doc.addEventListener( 'drop', onDropCapture, true );
		doc.addEventListener( 'dragover', onDragOverCapture, true );
		doc.addEventListener( 'dragenter', onDragEnterCapture, true );
		doc.addEventListener( 'dragleave', onDragLeaveCapture, true );

		return () => {
			doc.removeEventListener( 'drop', onDropCapture, true );
			doc.removeEventListener( 'dragover', onDragOverCapture, true );
			doc.removeEventListener( 'dragenter', onDragEnterCapture, true );
			doc.removeEventListener( 'dragleave', onDragLeaveCapture, true );
		};
	}, [] );

	return { externalDragOver };
}
