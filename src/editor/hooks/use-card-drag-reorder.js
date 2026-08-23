import { useEffect, useRef } from '@wordpress/element';

const DRAG_THRESHOLD = 5;

export default function useCardDragReorder(
	blockBodyRef,
	handleMove,
	handleSelect,
	setDragIndex,
	setDragOverIndex
) {
	const handleMoveRef = useRef( handleMove );
	handleMoveRef.current = handleMove;
	const handleSelectRef = useRef( handleSelect );
	handleSelectRef.current = handleSelect;

	useEffect( () => {
		const blockEl = blockBodyRef.current;
		if ( ! blockEl ) return;

		function findCardFromEvent( e ) {
			const target = e.target;
			if ( ! target || ! target.closest ) return null;
			return target.closest( '.fjb-image-card' );
		}

		function cardIndexFromPoint( x, y ) {
			const cards = blockEl.querySelectorAll( '.fjb-image-card' );
			for ( let i = 0; i < cards.length; i++ ) {
				const rect = cards[ i ].getBoundingClientRect();
				if (
					x >= rect.left &&
					x <= rect.right &&
					y >= rect.top &&
					y <= rect.bottom
				) {
					const idx = parseInt(
						cards[ i ].getAttribute( 'data-index' ),
						10
					);
					return isNaN( idx ) ? null : idx;
				}
			}
			return null;
		}

		function stopGutenbergDrag( e ) {
			const card = findCardFromEvent( e );
			if ( ! card ) return;
			if ( e.target.closest( '.fjb-image-card__overlay' ) ) return;
			e.stopPropagation();
		}

		function stopDragStart( e ) {
			const card = findCardFromEvent( e );
			if ( ! card ) return;
			e.stopPropagation();
			e.preventDefault();
		}

		blockEl.addEventListener( 'mousedown', stopGutenbergDrag, true );
		blockEl.addEventListener( 'touchstart', stopGutenbergDrag, true );
		blockEl.addEventListener( 'dragstart', stopDragStart, true );

		function onPointerDown( e ) {
			if ( e.button !== 0 ) return;
			const card = findCardFromEvent( e );
			if ( ! card ) return;
			if ( e.target.closest( '.fjb-image-card__overlay' ) ) return;
			if ( e.target.closest( '.fjb-add-zone' ) ) return;

			const idx = parseInt(
				card.getAttribute( 'data-index' ),
				10
			);
			if ( isNaN( idx ) ) return;

			// Selection is decided here, from the raw pointer up/down
			// pair, rather than via the card's onClick: the block
			// editor's own pointer handling can swallow the follow-up
			// "click" event before it ever reaches React, so selection
			// can't depend on it. Pointer capture is only attempted
			// once an actual drag is detected — a plain click doesn't
			// need it, and requiring it up front previously meant a
			// failed capture silently dropped the click entirely.
			const pointerId = e.pointerId;
			const startX = e.clientX;
			const startY = e.clientY;
			let didMove = false;
			let captured = false;

			function onMove( ev ) {
				const dx = ev.clientX - startX;
				const dy = ev.clientY - startY;
				if ( ! didMove ) {
					if (
						Math.abs( dx ) < DRAG_THRESHOLD &&
						Math.abs( dy ) < DRAG_THRESHOLD
					)
						return;
					didMove = true;
					try {
						card.setPointerCapture( pointerId );
						captured = true;
					} catch ( err ) {
						captured = false;
					}
					setDragIndex( idx );
				}
				ev.preventDefault();
				const overIdx = cardIndexFromPoint(
					ev.clientX,
					ev.clientY
				);
				if ( overIdx !== null && overIdx !== idx ) {
					setDragOverIndex( overIdx );
				} else {
					setDragOverIndex( null );
				}
			}

			function cleanup() {
				card.removeEventListener( 'pointermove', onMove );
				card.removeEventListener( 'pointerup', onUp );
				card.removeEventListener( 'pointercancel', onUp );
				card.removeEventListener(
					'lostpointercapture',
					onLost
				);
				if ( captured ) {
					try {
						card.releasePointerCapture( pointerId );
					} catch ( err ) {
						// already released
					}
				}
				setDragIndex( null );
				setDragOverIndex( null );
			}

			function onUp( ev ) {
				if ( didMove ) {
					ev.preventDefault();
					const dropIdx = cardIndexFromPoint(
						ev.clientX,
						ev.clientY
					);
					if ( dropIdx !== null && dropIdx !== idx ) {
						handleMoveRef.current( idx, dropIdx );
					}
				} else {
					handleSelectRef.current( idx );
				}
				cleanup();
			}

			function onLost() {
				cleanup();
			}

			card.addEventListener( 'pointermove', onMove );
			card.addEventListener( 'pointerup', onUp );
			card.addEventListener( 'pointercancel', onUp );
			card.addEventListener( 'lostpointercapture', onLost );
		}

		blockEl.addEventListener( 'pointerdown', onPointerDown, true );

		return () => {
			blockEl.removeEventListener(
				'mousedown',
				stopGutenbergDrag,
				true
			);
			blockEl.removeEventListener(
				'touchstart',
				stopGutenbergDrag,
				true
			);
			blockEl.removeEventListener(
				'dragstart',
				stopDragStart,
				true
			);
			blockEl.removeEventListener(
				'pointerdown',
				onPointerDown,
				true
			);
		};
	}, [] );
}
