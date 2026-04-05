import { useState, useEffect, useRef } from '@wordpress/element';
import { extractUrlsFromDropEvent } from '../utils/url-helpers';

export default function useExternalDrop( blockBodyRef, handleAddImages ) {
	const [ externalDragOver, setExternalDragOver ] = useState( false );
	const externalDragCountRef = useRef( 0 );
	const addImagesRef = useRef( handleAddImages );
	addImagesRef.current = handleAddImages;

	function handleExternalDragOver( e ) {
		e.preventDefault();
		e.stopPropagation();
		e.dataTransfer.dropEffect = 'copy';
	}

	function handleExternalDragEnter( e ) {
		e.preventDefault();
		externalDragCountRef.current++;
		setExternalDragOver( true );
	}

	function handleExternalDragLeave() {
		externalDragCountRef.current--;
		if ( externalDragCountRef.current <= 0 ) {
			externalDragCountRef.current = 0;
			setExternalDragOver( false );
		}
	}

	function handleExternalDrop( e ) {
		e.preventDefault();
		e.stopPropagation();
		const urls = extractUrlsFromDropEvent( e );
		if ( urls.length > 0 ) {
			handleAddImages( urls );
		}
		externalDragCountRef.current = 0;
		setExternalDragOver( false );
	}

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

		document.addEventListener( 'drop', onDropCapture, true );
		document.addEventListener( 'dragover', onDragOverCapture, true );

		return () => {
			document.removeEventListener( 'drop', onDropCapture, true );
			document.removeEventListener(
				'dragover',
				onDragOverCapture,
				true
			);
		};
	}, [] );

	return {
		externalDragOver,
		handleExternalDragOver,
		handleExternalDragEnter,
		handleExternalDragLeave,
		handleExternalDrop,
	};
}
