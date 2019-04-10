/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { Fragment } from '@wordpress/element';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ALLOWED_CHILD_BLOCKS, ALLOWED_TOP_LEVEL_BLOCKS, ALLOWED_MOVABLE_BLOCKS } from '../constants';
import { withParentBlock, StoryBlockMover } from './';

export default createHigherOrderComponent(
	( BlockEdit ) => {
		return withParentBlock( ( props ) => {
			const { attributes, name, parentBlock } = props;

			if ( ! parentBlock ||
				! ALLOWED_CHILD_BLOCKS.includes( name ) ||
				! ALLOWED_TOP_LEVEL_BLOCKS.includes( parentBlock.name )
			) {
				return <BlockEdit { ...props } />;
			}

			const { ampShowImageCaption } = attributes;
			const isImageBlock = 'core/image' === name;
			const isMovableBLock = ALLOWED_MOVABLE_BLOCKS.includes( name );

			return (
				<Fragment>
					{ isMovableBLock && (
						<StoryBlockMover
							clientIds={ props.clientId }
							blockElementId={ `block-${ props.clientId }` }
							isFirst={ props.isFirst }
							isLast={ props.isLast }
							isFocused={ props.isFocused }
							isDraggable={ ! props.isPartOfMultiSelection }
						/>
					) }
					<BlockEdit { ...props } />
					{ isImageBlock && (
						<InspectorControls>
							<PanelBody
								title={ __( 'Story Settings', 'amp' ) }
							>
								<ToggleControl
									key="position"
									label={ __( 'Show or hide the caption', 'amp' ) }
									checked={ ampShowImageCaption }
									onChange={
										function() {
											props.setAttributes( { ampShowImageCaption: ! attributes.ampShowImageCaption } );
											if ( ! attributes.ampShowImageCaption ) {
												props.setAttributes( { caption: '' } );
											}
										}
									}
									help={ __( 'Toggle on to show image caption. If you turn this off the current caption text will be deleted.', 'amp' ) }
								/>
							</PanelBody>
						</InspectorControls>
					) }
				</Fragment>
			);
		} );
	},
	'withAmpStorySettings'
);
