import { useState, useRef } from '@wordpress/element';
import { useBlockProps } from '@wordpress/block-editor';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { generateId, urlsToImages, parseUrlsFromText } from './utils/url-helpers';
import useImageMigration from './hooks/use-image-migration';
import useCardDragReorder from './hooks/use-card-drag-reorder';
import useExternalDrop from './hooks/use-external-drop';
import ImageCard from './components/image-card';
import AddImagesZone from './components/add-images-zone';
import GalleryInspector from './components/gallery-inspector';

export default function FlickrJustifiedEdit( props ) {
	const { attributes, setAttributes, isSelected, clientId } = props;
	const { urls, images } = attributes;

	const { selectBlock } = useDispatch( 'core/block-editor' );

	console.log( '[FJB Debug] Edit render — isSelected:', isSelected, 'clientId:', clientId );

	useImageMigration( urls, images, setAttributes );


	const imagesList =
		images && images.length > 0
			? images.map( ( img ) => {
					if ( img.id ) return img;
					return {
						id: generateId(),
						url: img.url,
						fullRow: !! img.fullRow,
					};
			  } )
			: [];

	const [ selectedIndex, setSelectedIndex ] = useState( null );
	const [ dragIndex, setDragIndex ] = useState( null );
	const [ dragOverIndex, setDragOverIndex ] = useState( null );
	const blockBodyRef = useRef( null );

	const blockProps = useBlockProps( {
		className: 'flickr-justified-block-editor',
		ref: blockBodyRef,
	} );

	function handleAddImages( newUrls ) {
		const existingUrls = {};
		imagesList.forEach( ( img ) => {
			existingUrls[ img.url ] = true;
		} );
		const dedupedUrls = newUrls.filter( ( u ) => ! existingUrls[ u ] );
		if ( dedupedUrls.length === 0 ) return;
		const newImages = imagesList.concat( urlsToImages( dedupedUrls ) );
		setAttributes( { images: newImages } );
	}

	function handleRemove( idx ) {
		const newImages = imagesList.filter( ( _, i ) => i !== idx );
		setAttributes( { images: newImages } );
		if ( selectedIndex === idx ) setSelectedIndex( null );
		else if ( selectedIndex !== null && selectedIndex > idx )
			setSelectedIndex( selectedIndex - 1 );
	}

	function handleToggleFullRow( idx ) {
		const newImages = imagesList.map( ( img, i ) => {
			if ( i === idx )
				return {
					id: img.id,
					url: img.url,
					fullRow: ! img.fullRow,
				};
			return img;
		} );
		setAttributes( { images: newImages } );
	}

	function handleSelect( idx ) {
		console.log( '[FJB Debug] handleSelect called — idx:', idx, 'prev selectedIndex:', selectedIndex );
		setSelectedIndex( selectedIndex === idx ? null : idx );
	}

	function handleMove( fromIdx, toIdx ) {
		const newImages = imagesList.slice();
		const item = newImages.splice( fromIdx, 1 )[ 0 ];
		newImages.splice( toIdx, 0, item );
		setAttributes( { images: newImages } );
		if ( selectedIndex === fromIdx ) setSelectedIndex( toIdx );
		else if ( selectedIndex !== null ) {
			let newSelected = selectedIndex;
			if ( fromIdx < selectedIndex && toIdx >= selectedIndex )
				newSelected--;
			else if ( fromIdx > selectedIndex && toIdx <= selectedIndex )
				newSelected++;
			setSelectedIndex( newSelected );
		}
	}

	function handleUpdateUrl( idx, newUrl ) {
		const newImages = imagesList.map( ( img, i ) => {
			if ( i === idx )
				return { id: img.id, url: newUrl, fullRow: img.fullRow };
			return img;
		} );
		setAttributes( { images: newImages } );
	}

	useCardDragReorder(
		blockBodyRef,
		handleMove,
		handleSelect,
		setDragIndex,
		setDragOverIndex
	);

	const {
		externalDragOver,
		handleExternalDragOver,
		handleExternalDragEnter,
		handleExternalDragLeave,
		handleExternalDrop,
	} = useExternalDrop( blockBodyRef, handleAddImages );

	return (
		<div { ...blockProps }
			onClick={ ( e ) => {
				console.log( '[FJB Debug] Block root clicked — isSelected:', isSelected, 'target:', e.target.tagName, 'className:', e.target.className );
				if ( ! isSelected ) {
					console.log( '[FJB Debug] Programmatically selecting block via onClick', clientId );
					selectBlock( clientId );
				}
			} }
		>
			<GalleryInspector
				attributes={ attributes }
				setAttributes={ setAttributes }
				selectedIndex={ selectedIndex }
				imagesList={ imagesList }
				onRemove={ handleRemove }
				onToggleFullRow={ handleToggleFullRow }
				onUpdateUrl={ handleUpdateUrl }
			/>

			{ imagesList.length > 0 ? (
					<div
						className={
							'fjb-card-grid' +
							( externalDragOver
								? ' fjb-card-grid--drop-active'
								: '' )
						}
						onDragOver={ handleExternalDragOver }
						onDragEnter={ handleExternalDragEnter }
						onDragLeave={ handleExternalDragLeave }
						onDrop={ handleExternalDrop }
					>
						{ imagesList.map( ( image, index ) => (
							<ImageCard
								key={ image.id }
								image={ image }
								index={ index }
								totalCount={ imagesList.length }
								isSelected={ selectedIndex === index }
								onSelect={ handleSelect }
								onRemove={ handleRemove }
								onToggleFullRow={ handleToggleFullRow }
								onMove={ handleMove }
								dragOverIndex={ dragOverIndex }
								dragIndex={ dragIndex }
							/>
						) ) }
						<AddImagesZone onAdd={ handleAddImages } />
					</div>
				) : (
					<div
						className={
							'fjb-empty-state' +
							( externalDragOver
								? ' fjb-empty-state--drop-active'
								: '' )
						}
						onDragOver={ handleExternalDragOver }
						onDragEnter={ handleExternalDragEnter }
						onDragLeave={ handleExternalDragLeave }
						onDrop={ handleExternalDrop }
					>
						<p className="fjb-empty-state__title">
							{ __(
								'Flickr Justified Block',
								'flickr-justified-block'
							) }
						</p>
						<p className="fjb-empty-state__subtitle">
							{ __(
								'Paste Flickr or image URLs below to create your gallery.',
								'flickr-justified-block'
							) }
						</p>
						<AddImagesZone onAdd={ handleAddImages } />
					</div>
				) }
		</div>
	);
}
