import { useState, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { parseUrlsFromText } from '../utils/url-helpers';

export default function AddImagesZone( { onAdd } ) {
	const [ inputValue, setInputValue ] = useState( '' );
	const inputRef = useRef( null );

	function handleAdd() {
		const urls = parseUrlsFromText( inputValue );
		if ( urls.length > 0 ) {
			onAdd( urls );
			setInputValue( '' );
		}
	}

	return (
		<div className="fjb-add-zone">
			<textarea
				ref={ inputRef }
				className="fjb-add-zone__input"
				onFocus={ () => {
					console.log( '[FJB Debug] AddImagesZone textarea focused' );
				} }
				placeholder={ __(
					'+ Paste Flickr or image URLs here (one per line)',
					'flickr-justified-block'
				) }
				value={ inputValue }
				rows={ 2 }
				onChange={ ( e ) => {
					setInputValue( e.target.value );
				} }
				onKeyDown={ ( e ) => {
					if ( e.key === 'Enter' && ! e.shiftKey ) {
						e.preventDefault();
						handleAdd();
					}
				} }
				onPaste={ () => {
					setTimeout( () => {
						const val = inputRef.current
							? inputRef.current.value
							: '';
						const urls = parseUrlsFromText( val );
						if ( urls.length > 1 ) {
							onAdd( urls );
							setInputValue( '' );
						}
					}, 50 );
				} }
			/>
			<Button
				variant="secondary"
				className="fjb-add-zone__btn"
				onClick={ handleAdd }
				disabled={ ! inputValue.trim() }
			>
				{ __( 'Add', 'flickr-justified-block' ) }
			</Button>
		</div>
	);
}
