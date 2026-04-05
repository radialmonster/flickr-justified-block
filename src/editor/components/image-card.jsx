import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { isAlbumUrl, isFlickrPhotoUrl } from '../utils/url-helpers';

export default function ImageCard( {
	image,
	index,
	totalCount,
	isSelected,
	onRemove,
	onToggleFullRow,
	onMove,
	dragOverIndex,
	dragIndex,
} ) {
	const [ imageData, setImageData ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const cardRef = useRef( null );

	const url = image.url;
	const urlIsAlbum = isAlbumUrl( url );
	const showFullRow = ! urlIsAlbum && image.fullRow;

	useEffect( () => {
		if ( ! url || ! url.trim() ) {
			setImageData( null );
			setError( null );
			return;
		}

		const trimmedUrl = url.trim();
		let isCancelled = false;

		const isImageUrl =
			/\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i.test( trimmedUrl );
		if ( isImageUrl ) {
			if ( ! isCancelled ) {
				setImageData( {
					success: true,
					image_url: trimmedUrl,
					is_flickr: false,
				} );
				setError( null );
			}
			return;
		}

		const isFlickrPhoto = isFlickrPhotoUrl( trimmedUrl );
		const isFlickrSet = isAlbumUrl( trimmedUrl );
		const isFlickrUrl = isFlickrPhoto || isFlickrSet;

		if ( ! isFlickrUrl ) {
			if ( ! isCancelled ) {
				setImageData( null );
				setError( 'Not a supported image URL' );
			}
			return;
		}

		if ( ! isCancelled ) {
			setLoading( true );
			setError( null );
		}

		apiFetch( {
			path: '/flickr-justified/v1/preview-image',
			method: 'POST',
			data: { url: trimmedUrl },
		} )
			.then( ( response ) => {
				if ( ! isCancelled ) {
					if ( response.success ) {
						setImageData( response );
						setError( null );
					} else {
						setImageData( null );
						setError( 'Failed to load image' );
					}
				}
			} )
			.catch( ( err ) => {
				if ( ! isCancelled ) {
					setImageData( null );
					setError( 'Error: ' + ( err.message || 'Unknown' ) );
				}
			} )
			.finally( () => {
				if ( ! isCancelled ) setLoading( false );
			} );

		return () => {
			isCancelled = true;
		};
	}, [ url ] );

	const isDragging = dragIndex === index;
	const isDropTarget = dragOverIndex === index;

	let cardClasses = 'fjb-image-card';
	if ( isSelected ) cardClasses += ' fjb-image-card--selected';
	if ( showFullRow ) cardClasses += ' fjb-image-card--full-row';
	if ( isDragging ) cardClasses += ' fjb-image-card--dragging';
	if ( isDropTarget ) cardClasses += ' fjb-image-card--drop-target';

	const cardStyle = {};
	if ( showFullRow ) {
		cardStyle.gridColumn = '1 / -1';
	}

	let cardContent;

	if ( loading ) {
		cardContent = (
			<div className="fjb-image-card__loading">
				<span className="fjb-image-card__spinner" />
				<span>{ __( 'Loading...', 'flickr-justified-block' ) }</span>
			</div>
		);
	} else if ( imageData && imageData.success && imageData.is_set ) {
		cardContent = (
			<div className="fjb-image-card__album">
				<div className="fjb-image-card__album-icon">
					{ '\uD83D\uDCF8' }
				</div>
				{ imageData.album_title ? (
					<div className="fjb-image-card__album-title">
						{ imageData.album_title }
					</div>
				) : null }
				<div className="fjb-image-card__album-label">
					{ /\/with\/\d+/i.test( url.trim() )
						? __(
								'Flickr Album (with photo)',
								'flickr-justified-block'
						  )
						: __( 'Flickr Album', 'flickr-justified-block' ) }
				</div>
			</div>
		);
	} else if ( imageData && imageData.success && imageData.image_url ) {
		cardContent = (
			<img
				src={ imageData.image_url }
				alt=""
				className="fjb-image-card__img"
				draggable={ false }
			/>
		);
	} else if ( error ) {
		cardContent = (
			<div className="fjb-image-card__error">
				<span>{ error }</span>
			</div>
		);
	} else {
		cardContent = (
			<div className="fjb-image-card__url-fallback">
				<span>{ url.trim() }</span>
			</div>
		);
	}

	return (
		<div
			ref={ cardRef }
			className={ cardClasses }
			style={ cardStyle }
			draggable={ false }
			data-index={ index }
		>
			<span className="fjb-image-card__badge">
				{ String( index + 1 ) }
			</span>

			{ showFullRow ? (
				<span
					className="fjb-image-card__fullrow-badge"
					title={ __(
						'Full width row',
						'flickr-justified-block'
					) }
				>
					{ '\u2194' }
				</span>
			) : null }

			{ cardContent }

			<div className="fjb-image-card__overlay">
				<button
					className="fjb-image-card__btn fjb-image-card__btn--move"
					onClick={ ( e ) => {
						e.stopPropagation();
						if ( index > 0 ) onMove( index, index - 1 );
					} }
					title={ __( 'Move up', 'flickr-justified-block' ) }
					type="button"
					disabled={ index === 0 }
				>
					{ '\u2191' }
				</button>
				<button
					className="fjb-image-card__btn fjb-image-card__btn--move"
					onClick={ ( e ) => {
						e.stopPropagation();
						if ( index < totalCount - 1 )
							onMove( index, index + 1 );
					} }
					title={ __( 'Move down', 'flickr-justified-block' ) }
					type="button"
					disabled={ index === totalCount - 1 }
				>
					{ '\u2193' }
				</button>
				{ ! urlIsAlbum ? (
					<button
						className={
							'fjb-image-card__btn fjb-image-card__btn--fullrow' +
							( image.fullRow
								? ' fjb-image-card__btn--active'
								: '' )
						}
						onClick={ ( e ) => {
							e.stopPropagation();
							onToggleFullRow( index );
						} }
						title={
							image.fullRow
								? __(
										'Remove from own row',
										'flickr-justified-block'
								  )
								: __(
										'Put on own row',
										'flickr-justified-block'
								  )
						}
						type="button"
					>
						{ '\u2194' }
					</button>
				) : null }
				<button
					className="fjb-image-card__btn fjb-image-card__btn--remove"
					onClick={ ( e ) => {
						e.stopPropagation();
						onRemove( index );
					} }
					title={ __(
						'Remove image',
						'flickr-justified-block'
					) }
					type="button"
				>
					{ '\u2715' }
				</button>
			</div>
		</div>
	);
}
