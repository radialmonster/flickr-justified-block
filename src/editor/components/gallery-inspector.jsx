import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	TextControl,
	Button,
} from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { isAlbumUrl } from '../utils/url-helpers';

export default function GalleryInspector( {
	attributes,
	setAttributes,
	selectedIndex,
	imagesList,
	onRemove,
	onToggleFullRow,
	onUpdateUrl,
} ) {
	const {
		imageSize,
		responsiveSettings,
		rowHeightMode,
		rowHeight,
		maxViewportHeight,
		singleImageAlignment,
		maxPhotos,
		sortOrder,
		gap,
	} = attributes;

	const selectedImage =
		selectedIndex !== null && imagesList[ selectedIndex ]
			? imagesList[ selectedIndex ]
			: null;
	const selectedIsAlbum = selectedImage
		? isAlbumUrl( selectedImage.url )
		: false;

	const sizeOptions = [
		{
			label: __( 'Medium', 'flickr-justified-block' ),
			value: 'medium',
		},
		{
			label: __( 'Large', 'flickr-justified-block' ),
			value: 'large',
		},
		{
			label: __( 'Large 1600px', 'flickr-justified-block' ),
			value: 'large1600',
		},
		{
			label: __( 'Large 2048px', 'flickr-justified-block' ),
			value: 'large2048',
		},
		{
			label: __( 'Original', 'flickr-justified-block' ),
			value: 'original',
		},
	];

	const breakpointLabels = {
		mobile: __( 'Mobile Portrait', 'flickr-justified-block' ),
		mobile_landscape: __(
			'Mobile Landscape',
			'flickr-justified-block'
		),
		tablet_portrait: __(
			'Tablet Portrait',
			'flickr-justified-block'
		),
		tablet_landscape: __(
			'Tablet Landscape',
			'flickr-justified-block'
		),
		desktop: __( 'Desktop/Laptop', 'flickr-justified-block' ),
		large_desktop: __( 'Large Desktop', 'flickr-justified-block' ),
		extra_large: __(
			'Ultra-Wide Screens',
			'flickr-justified-block'
		),
	};

	return (
		<InspectorControls>
			{ selectedImage ? (
				<PanelBody
					title={
						__( 'Selected Image', 'flickr-justified-block' ) +
						' #' +
						( selectedIndex + 1 )
					}
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'URL', 'flickr-justified-block' ) }
						value={ selectedImage.url }
						onChange={ ( value ) => {
							onUpdateUrl( selectedIndex, value );
						} }
					/>
					{ ! selectedIsAlbum ? (
						<ToggleControl
							label={ __(
								'Full width row',
								'flickr-justified-block'
							) }
							help={
								selectedImage.fullRow
									? __(
											'This image will display on its own row, filling the full width.',
											'flickr-justified-block'
									  )
									: __(
											'This image will share a row with other images.',
											'flickr-justified-block'
									  )
							}
							checked={ selectedImage.fullRow }
							onChange={ () => {
								onToggleFullRow( selectedIndex );
							} }
						/>
					) : (
						<p
							style={ {
								fontSize: '12px',
								color: '#666',
								fontStyle: 'italic',
							} }
						>
							{ __(
								'Full width row is not available for albums. Album photos are expanded into individual images on the frontend.',
								'flickr-justified-block'
							) }
						</p>
					) }
					<Button
						variant="secondary"
						isDestructive={ true }
						onClick={ () => {
							onRemove( selectedIndex );
						} }
					>
						{ __(
							'Remove Image',
							'flickr-justified-block'
						) }
					</Button>
				</PanelBody>
			) : null }

			<PanelBody
				title={ __(
					'Gallery Settings',
					'flickr-justified-block'
				) }
				initialOpen={ ! selectedImage }
			>
				<SelectControl
					label={ __(
						'Gallery Image Size',
						'flickr-justified-block'
					) }
					help={ __(
						'Choose the size for images displayed in the gallery grid. Larger sizes provide better quality but slower loading.',
						'flickr-justified-block'
					) }
					value={ imageSize }
					options={ sizeOptions }
					onChange={ ( value ) => {
						setAttributes( { imageSize: value } );
					} }
				/>
				<TextControl
					label={ __(
						'Show how many images',
						'flickr-justified-block'
					) }
					help={ __(
						'Enter 0 to show all images. Use a positive number to limit how many images display for this block.',
						'flickr-justified-block'
					) }
					type="number"
					min={ 0 }
					value={
						typeof maxPhotos === 'number' ? maxPhotos : 0
					}
					onChange={ ( value ) => {
						const parsed = parseInt( value, 10 );
						setAttributes( {
							maxPhotos:
								isNaN( parsed ) || parsed < 0
									? 0
									: parsed,
						} );
					} }
				/>
				<SelectControl
					label={ __(
						'Sort images',
						'flickr-justified-block'
					) }
					help={ __(
						'Choose how to order the images that appear in this gallery.',
						'flickr-justified-block'
					) }
					value={ sortOrder || 'input' }
					options={ [
						{
							label: __(
								'As entered',
								'flickr-justified-block'
							),
							value: 'input',
						},
						{
							label: __(
								'Views (high to low)',
								'flickr-justified-block'
							),
							value: 'views_desc',
						},
					] }
					onChange={ ( value ) => {
						setAttributes( {
							sortOrder: value || 'input',
						} );
					} }
				/>
				<p
					style={ {
						fontSize: '12px',
						color: '#666',
						margin: '16px 0 12px',
					} }
				>
					{ __(
						'Images use built-in PhotoSwipe lightbox optimized for high-resolution displays. The plugin automatically selects the best available size from Flickr.',
						'flickr-justified-block'
					) }
				</p>
				<RangeControl
					label={ __(
						'Grid gap (px)',
						'flickr-justified-block'
					) }
					help={ __(
						'Space between images in the justified gallery.',
						'flickr-justified-block'
					) }
					min={ 0 }
					max={ 64 }
					step={ 1 }
					value={ gap ?? 12 }
					onChange={ ( value ) => {
						setAttributes( { gap: value ?? 12 } );
					} }
				/>
				<SelectControl
					label={ __(
						'Row height mode',
						'flickr-justified-block'
					) }
					help={ __(
						'Auto adjusts row height to fill container width perfectly. Fixed uses a specific pixel height.',
						'flickr-justified-block'
					) }
					value={ rowHeightMode || 'auto' }
					options={ [
						{
							label: __(
								'Auto (fill width)',
								'flickr-justified-block'
							),
							value: 'auto',
						},
						{
							label: __(
								'Fixed height',
								'flickr-justified-block'
							),
							value: 'fixed',
						},
					] }
					onChange={ ( value ) => {
						setAttributes( {
							rowHeightMode: value || 'auto',
						} );
					} }
				/>
				{ rowHeightMode === 'fixed' && (
					<RangeControl
						label={ __(
							'Row height (px)',
							'flickr-justified-block'
						) }
						help={ __(
							'Fixed height for all gallery rows. Images will scale to fit this height.',
							'flickr-justified-block'
						) }
						min={ 120 }
						max={ 500 }
						step={ 10 }
						value={ rowHeight ?? 280 }
						onChange={ ( value ) => {
							setAttributes( {
								rowHeight: value ?? 280,
							} );
						} }
					/>
				) }
				<RangeControl
					label={ __(
						'Max viewport height (%)',
						'flickr-justified-block'
					) }
					help={ __(
						'Limit image height to a percentage of the browser window height. Prevents very large images from exceeding screen size.',
						'flickr-justified-block'
					) }
					min={ 30 }
					max={ 100 }
					step={ 5 }
					value={ maxViewportHeight ?? 80 }
					onChange={ ( value ) => {
						setAttributes( {
							maxViewportHeight: value ?? 80,
						} );
					} }
				/>
				<SelectControl
					label={ __(
						'Single image alignment',
						'flickr-justified-block'
					) }
					help={ __(
						'Horizontal alignment when there is only one image in the entire gallery.',
						'flickr-justified-block'
					) }
					value={ singleImageAlignment || 'center' }
					options={ [
						{
							label: __(
								'Left',
								'flickr-justified-block'
							),
							value: 'left',
						},
						{
							label: __(
								'Center',
								'flickr-justified-block'
							),
							value: 'center',
						},
						{
							label: __(
								'Right',
								'flickr-justified-block'
							),
							value: 'right',
						},
					] }
					onChange={ ( value ) => {
						setAttributes( {
							singleImageAlignment: value || 'center',
						} );
					} }
				/>
			</PanelBody>
			<PanelBody
				title={ __(
					'Responsive Settings',
					'flickr-justified-block'
				) }
				initialOpen={ false }
			>
				<p
					style={ {
						fontSize: '13px',
						color: '#666',
						marginBottom: '16px',
					} }
				>
					{ __(
						'Configure how many images per row to display at different screen sizes. Breakpoint sizes are configured in Settings - Flickr Justified.',
						'flickr-justified-block'
					) }
				</p>

				{ Object.keys( breakpointLabels ).map(
					( breakpointKey ) => (
						<RangeControl
							key={ breakpointKey }
							label={ breakpointLabels[ breakpointKey ] }
							min={ 1 }
							max={ 8 }
							step={ 1 }
							value={
								( responsiveSettings &&
									responsiveSettings[
										breakpointKey
									] ) ||
								1
							}
							onChange={ ( value ) => {
								const newResponsiveSettings = {
									...responsiveSettings,
								};
								newResponsiveSettings[ breakpointKey ] =
									value || 1;
								setAttributes( {
									responsiveSettings:
										newResponsiveSettings,
								} );
							} }
						/>
					)
				) }
			</PanelBody>
		</InspectorControls>
	);
}
