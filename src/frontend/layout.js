/**
 * Flickr Justified Gallery - Layout Engine
 */

const SORT_VIEWS_DESC = 'views_desc';

function normalizeRotation( value ) {
	if ( ! value || ( typeof value !== 'number' && typeof value !== 'string' ) ) {
		return 0;
	}
	const parsed = parseInt( value, 10 );
	if ( isNaN( parsed ) ) return 0;
	const normalized = parsed % 360;
	return normalized < 0 ? normalized + 360 : normalized;
}

function shouldSwapDimensions( rotation ) {
	const normalized = normalizeRotation( rotation );
	return normalized === 90 || normalized === 270;
}

function calculateOptimalRowHeight( aspectRatios, containerWidth, gap ) {
	const totalAspectRatio = aspectRatios.reduce( ( sum, ar ) => sum + ar, 0 );
	const availableWidth = containerWidth - gap * ( aspectRatios.length - 1 );
	return availableWidth / totalAspectRatio;
}

function getAspectRatioForCard( card ) {
	const img = card.querySelector( 'img' );
	const anchor = card.querySelector( 'a' );

	let rotationSource = card.dataset?.rotation;
	if ( rotationSource === undefined && anchor ) {
		rotationSource = anchor.getAttribute( 'data-rotation' );
	}
	if ( rotationSource === undefined && img ) {
		rotationSource = img.getAttribute( 'data-rotation' );
	}

	const rotation = normalizeRotation( rotationSource );
	const swapDimensions = shouldSwapDimensions( rotation );

	if ( img && img.complete && img.naturalWidth > 0 && img.naturalHeight > 0 ) {
		const width = swapDimensions ? img.naturalHeight : img.naturalWidth;
		const height = swapDimensions ? img.naturalWidth : img.naturalHeight;
		return width / height;
	}

	const widthAttr = parseInt(
		img?.getAttribute( 'data-width' ) ||
			anchor?.getAttribute( 'data-width' ) ||
			card.getAttribute( 'data-width' ) ||
			'0',
		10
	);
	const heightAttr = parseInt(
		img?.getAttribute( 'data-height' ) ||
			anchor?.getAttribute( 'data-height' ) ||
			card.getAttribute( 'data-height' ) ||
			'0',
		10
	);

	if ( widthAttr > 0 && heightAttr > 0 ) {
		const width = swapDimensions ? heightAttr : widthAttr;
		const height = swapDimensions ? widthAttr : heightAttr;
		return width / height;
	}

	return 3 / 2;
}

function getImagesPerRow( containerWidth, breakpoints, responsiveSettings ) {
	const sortedBreakpoints = Object.entries( breakpoints ).sort(
		( a, b ) => b[ 1 ] - a[ 1 ]
	);

	for ( const [ key, width ] of sortedBreakpoints ) {
		if ( containerWidth >= width && responsiveSettings[ key ] ) {
			return responsiveSettings[ key ];
		}
	}

	const fallbackKeys = [
		'mobile',
		'mobile_landscape',
		'tablet_portrait',
		'default',
	];
	for ( const key of fallbackKeys ) {
		if ( responsiveSettings[ key ] ) {
			return responsiveSettings[ key ];
		}
	}
	return 1;
}

export function initJustifiedGallery() {
	const grids = document.querySelectorAll(
		'.flickr-justified-grid:not(.justified-initialized)'
	);

	grids.forEach( ( grid ) => {
		const gap = parseInt(
			getComputedStyle( grid ).getPropertyValue( '--gap' ) || '12',
			10
		);

		function processRows() {
			const containerWidth =
				grid.offsetWidth ||
				grid.clientWidth ||
				grid.getBoundingClientRect().width;
			if ( containerWidth === 0 ) {
				return;
			}

			const responsiveSettings = JSON.parse(
				grid.dataset.responsiveSettings || '{}'
			);
			const breakpoints = JSON.parse(
				grid.dataset.breakpoints || '{}'
			);
			const rowHeightMode = grid.dataset.rowHeightMode || 'auto';
			const targetRowHeight = parseInt(
				grid.dataset.rowHeight || '300',
				10
			);
			const maxViewportHeight = parseInt(
				grid.dataset.maxViewportHeight || '80',
				10
			);

			const maxRowHeightVh = Math.max(
				50,
				Math.min(
					window.innerHeight,
					window.innerHeight * ( maxViewportHeight / 100 )
				)
			);

			const allCards = Array.from(
				grid.querySelectorAll( ':scope > .flickr-justified-card' )
			);
			if ( allCards.length === 0 ) {
				return;
			}

			const staging = document.createDocumentFragment();

			const imagesPerRow = getImagesPerRow(
				containerWidth,
				breakpoints,
				responsiveSettings
			);
			const aspectRatios = allCards.map( getAspectRatioForCard );

			if ( allCards.length === 1 ) {
				const row = document.createElement( 'div' );
				row.className = 'flickr-justified-row';

				const aspectRatio = aspectRatios[ 0 ];
				const heightFromWidth = containerWidth / aspectRatio;
				let cardHeight = Math.min( heightFromWidth, maxRowHeightVh );
				let cardWidth = cardHeight * aspectRatio;

				if ( cardWidth > containerWidth ) {
					cardWidth = containerWidth;
					cardHeight = cardWidth / aspectRatio;
				}

				const card = allCards[ 0 ];
				card.style.width = Math.round( cardWidth ) + 'px';
				card.style.height = Math.round( cardHeight ) + 'px';

				const img = card.querySelector( 'img' );
				if ( img ) {
					const rotation = normalizeRotation(
						card.dataset?.rotation || img.dataset?.rotation || 0
					);
					const shouldSwap = shouldSwapDimensions( rotation );
					img.style.width = '100%';
					img.style.height = '100%';
					img.style.objectFit = shouldSwap ? 'contain' : 'cover';
				}

				row.appendChild( card );
				staging.appendChild( row );

				grid.appendChild( staging );
				return;
			}

			function flushRow( cards, ars ) {
				if ( cards.length === 0 ) return;

				let optimalHeight;
				if ( rowHeightMode === 'fixed' ) {
					optimalHeight = targetRowHeight;
				} else {
					optimalHeight = calculateOptimalRowHeight(
						ars,
						containerWidth,
						gap
					);
					optimalHeight = Math.max(
						100,
						Math.min( optimalHeight, maxRowHeightVh )
					);
				}

				const row = document.createElement( 'div' );
				row.className = 'flickr-justified-row';

				cards.forEach( ( cardElement, idx ) => {
					const ar = ars[ idx ];
					const cw = Math.round( optimalHeight * ar );
					const ch = Math.round( optimalHeight );

					cardElement.style.width = cw + 'px';
					cardElement.style.height = ch + 'px';

					const img = cardElement.querySelector( 'img' );
					if ( img ) {
						const rotation = normalizeRotation(
							cardElement.dataset?.rotation ||
								img.dataset?.rotation ||
								0
						);
						const shouldSwap = shouldSwapDimensions( rotation );
						img.style.width = '100%';
						img.style.height = '100%';
						img.style.objectFit = shouldSwap
							? 'contain'
							: 'cover';
					}

					row.appendChild( cardElement );
				} );

				staging.appendChild( row );
			}

			function createFullWidthRow( card, aspectRatio ) {
				const row = document.createElement( 'div' );
				row.className = 'flickr-justified-row';

				const heightFromWidth = containerWidth / aspectRatio;
				let cardHeight = Math.min( heightFromWidth, maxRowHeightVh );
				let cardWidth = cardHeight * aspectRatio;

				if ( cardWidth > containerWidth ) {
					cardWidth = containerWidth;
					cardHeight = cardWidth / aspectRatio;
				}

				card.style.width = Math.round( cardWidth ) + 'px';
				card.style.height = Math.round( cardHeight ) + 'px';

				const img = card.querySelector( 'img' );
				if ( img ) {
					const rotation = normalizeRotation(
						card.dataset?.rotation || img.dataset?.rotation || 0
					);
					const shouldSwap = shouldSwapDimensions( rotation );
					img.style.width = '100%';
					img.style.height = '100%';
					img.style.objectFit = shouldSwap ? 'contain' : 'cover';
				}

				row.appendChild( card );
				staging.appendChild( row );
			}

			let currentRow = [];
			let currentRowAspectRatios = [];

			for ( let i = 0; i < allCards.length; i++ ) {
				const card = allCards[ i ];
				const aspectRatio = aspectRatios[ i ];
				const isFullRow = card.dataset.fullRow === '1';

				if ( isFullRow ) {
					flushRow( currentRow, currentRowAspectRatios );
					currentRow = [];
					currentRowAspectRatios = [];
					createFullWidthRow( card, aspectRatio );
					continue;
				}

				currentRow.push( card );
				currentRowAspectRatios.push( aspectRatio );

				const isLastCard = i === allCards.length - 1;
				const rowFull = currentRow.length >= imagesPerRow;

				if ( rowFull || isLastCard ) {
					flushRow( currentRow, currentRowAspectRatios );
					currentRow = [];
					currentRowAspectRatios = [];
				}
			}

			const loadingIndicator = grid.querySelector(
				'.flickr-loading-more'
			);
			const shouldPreserveIndicator =
				loadingIndicator && ! loadingIndicator.dataset.removeMe;

			// DocumentFragment empties itself when appended — single DOM operation
			grid.appendChild( staging );

			if ( loadingIndicator && shouldPreserveIndicator ) {
				grid.appendChild( loadingIndicator );
			}
		}

		try {
			processRows();
		} catch ( error ) {
			console.error( 'Flickr Gallery: Error during layout:', error );
		}

		grid.classList.add( 'justified-initialized' );

		// Observe container resizes (catches sidebar collapse, CSS transitions, etc.)
		if ( ! grid._flickrResizeObserver ) {
			grid._flickrResizeObserver = resizeObserver;
			resizeObserver.observe( grid );
		}

		const reinitEvent = new CustomEvent( 'flickrGalleryReorganized', {
			detail: { grid },
		} );
		document.dispatchEvent( reinitEvent );

		requestAnimationFrame( () => {
			const photoswipeEvent = new CustomEvent(
				'flickr-gallery-updated',
				{ detail: { gallery: grid } }
			);
			document.dispatchEvent( photoswipeEvent );
		} );
	} );
}

// ResizeObserver replaces the old window resize listener — it catches both
// window resizes and container-level size changes (e.g. sidebar toggle).
let resizeTimeout;
const resizeObserver = new ResizeObserver( () => {
	clearTimeout( resizeTimeout );
	resizeTimeout = setTimeout( () => {
		const grids = document.querySelectorAll(
			'.flickr-justified-grid.justified-initialized'
		);
		grids.forEach( ( grid ) => {
			grid.classList.remove( 'justified-initialized' );
		} );
		initJustifiedGallery();
	}, 250 );
} );

export { normalizeRotation, shouldSwapDimensions, getAspectRatioForCard };
