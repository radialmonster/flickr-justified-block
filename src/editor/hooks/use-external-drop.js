import { useState, useEffect, useRef } from '@wordpress/element';
import { extractUrlsFromDropEvent } from '../utils/url-helpers';

export default function useExternalDrop( blockBodyRef, handleAddImages ) {
	const [ externalDragOver, setExternalDragOver ] = useState( false );
	const externalDragCountRef = useRef( 0 );
	const addImagesRef = useRef( handleAddImages );
	addImagesRef.current = handleAddImages;

	function handleExternalDragOver( e ) {
		console.log( '[FJB Drop] handleExternalDragOver — types:', Array.from( e.dataTransfer.types ) );
		e.preventDefault();
		e.stopPropagation();
		e.dataTransfer.dropEffect = 'copy';
	}

	function handleExternalDragEnter( e ) {
		console.log( '[FJB Drop] handleExternalDragEnter — target:', e.target.tagName, e.target.className );
		e.preventDefault();
		externalDragCountRef.current++;
		setExternalDragOver( true );
	}

	function handleExternalDragLeave() {
		console.log( '[FJB Drop] handleExternalDragLeave' );
		externalDragCountRef.current--;
		if ( externalDragCountRef.current <= 0 ) {
			externalDragCountRef.current = 0;
			setExternalDragOver( false );
		}
	}

	function handleExternalDrop( e ) {
		console.log( '[FJB Drop] handleExternalDrop — types:', Array.from( e.dataTransfer.types ) );
		e.preventDefault();
		e.stopPropagation();
		const urls = extractUrlsFromDropEvent( e );
		console.log( '[FJB Drop] extracted URLs:', urls );
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
			console.log( '[FJB Drop] onDropCapture — isInsideBlock:', isInsideBlock( e ), 'target:', e.target.tagName, e.target.className );
			if ( ! isInsideBlock( e ) ) return;
			const urls = extractUrlsFromDropEvent( e );
			console.log( '[FJB Drop] onDropCapture extracted URLs:', urls );
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
