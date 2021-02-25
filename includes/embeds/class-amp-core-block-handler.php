<?php
/**
 * Class AMP_Core_Block_Handler
 *
 * @package AMP
 */

use AmpProject\Attribute;
use AmpProject\Dom\Document;

/**
 * Class AMP_Core_Block_Handler
 *
 * @since 1.0
 * @internal
 */
class AMP_Core_Block_Handler extends AMP_Base_Embed_Handler {

	/**
	 * Attribute to store the original width on a video or iframe just before WordPress removes it.
	 *
	 * @see AMP_Core_Block_Handler::preserve_widget_text_element_dimensions()
	 * @see AMP_Core_Block_Handler::process_text_widgets()
	 * @var string
	 */
	const AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME = 'amp-preserved-width';

	/**
	 * Attribute to store the original height on a video or iframe just before WordPress removes it.
	 *
	 * @see AMP_Core_Block_Handler::preserve_widget_text_element_dimensions()
	 * @see AMP_Core_Block_Handler::process_text_widgets()
	 * @var string
	 */
	const AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME = 'amp-preserved-height';

	/**
	 * Count of the category widgets encountered.
	 *
	 * @var int
	 */
	private $category_widget_count = 0;

	/**
	 * Methods to ampify blocks.
	 *
	 * @var array
	 */
	protected $block_ampify_methods = [
		'core/categories' => 'ampify_categories_block',
		'core/archives'   => 'ampify_archives_block',
		'core/video'      => 'ampify_video_block',
		'core/cover'      => 'ampify_cover_block',
	];

	/**
	 * Register embed.
	 */
	public function register_embed() {
		add_filter( 'render_block', [ $this, 'filter_rendered_block' ], 0, 2 );
		add_filter( 'widget_text_content', [ $this, 'preserve_widget_text_element_dimensions' ], PHP_INT_MAX );
	}

	/**
	 * Unregister embed.
	 */
	public function unregister_embed() {
		remove_filter( 'render_block', [ $this, 'filter_rendered_block' ], 0 );
		remove_filter( 'widget_text_content', [ $this, 'preserve_widget_text_element_dimensions' ], PHP_INT_MAX );
	}

	/**
	 * Filters the content of a single block to make it AMP valid.
	 *
	 * @param string $block_content The block content about to be appended.
	 * @param array  $block         The full block, including name and attributes.
	 * @return string Filtered block content.
	 */
	public function filter_rendered_block( $block_content, $block ) {
		if ( ! isset( $block['blockName'] ) ) {
			return $block_content;
		}

		if ( isset( $block['attrs'] ) && 'core/shortcode' !== $block['blockName'] ) {
			$injected_attributes    = '';
			$prop_attribute_mapping = [
				'ampCarousel'  => 'data-amp-carousel',
				'ampLayout'    => 'data-amp-layout',
				'ampLightbox'  => 'data-amp-lightbox',
				'ampNoLoading' => 'data-amp-noloading',
			];
			foreach ( $prop_attribute_mapping as $prop => $attr ) {
				if ( isset( $block['attrs'][ $prop ] ) ) {
					$property_value = $block['attrs'][ $prop ];
					if ( is_bool( $property_value ) ) {
						$property_value = $property_value ? 'true' : 'false';
					}

					$injected_attributes .= sprintf( ' %s="%s"', $attr, esc_attr( $property_value ) );
				}
			}
			if ( $injected_attributes ) {
				$block_content = preg_replace( '/(<\w+)/', '$1' . $injected_attributes, $block_content, 1 );
			}
		}

		if ( isset( $this->block_ampify_methods[ $block['blockName'] ] ) ) {
			$method_name   = $this->block_ampify_methods[ $block['blockName'] ];
			$block_content = $this->{$method_name}( $block_content, $block );
		} elseif ( 'core/image' === $block['blockName'] || 'core/audio' === $block['blockName'] ) {
			/*
			 * While the video block placeholder just outputs an empty video element, the placeholders for image and
			 * audio blocks output empty <img> and <audio> respectively. These will result in AMP validation errors,
			 * so we need to empty out the block content to prevent this from happening. Note that <source> is used
			 * for <img> because eventually the image block could use <picture>.
			 */
			if ( ! preg_match( '/src=|<source/', $block_content ) ) {
				$block_content = '';
			}
		}
		return $block_content;
	}

	/**
	 * Fix rendering of categories block when displayAsDropdown.
	 *
	 * This excludes the disallowed JS scrips, adds <form> tags, and uses on:change for <select>.
	 *
	 * @see render_block_core_categories()
	 *
	 * @param string $block_content Block content.
	 * @return string Rendered.
	 */
	public function ampify_categories_block( $block_content ) {
		static $block_id = 0;
		$block_id++;

		$form_id = "wp-block-categories-dropdown-{$block_id}-form";

		// Remove output of build_dropdown_script_block_core_categories().
		$block_content = preg_replace( '#<script.+?</script>#s', '', $block_content );

		$form = sprintf(
			'<form action="%s" method="get" target="_top" id="%s">',
			esc_url( home_url() ),
			esc_attr( $form_id )
		);

		$block_content = preg_replace(
			'#(<select)(.+</select>)#s',
			$form . '$1' . sprintf( ' on="change:%1$s.submit"', esc_attr( $form_id ) ) . '$2</form>',
			$block_content,
			1
		);

		return $block_content;
	}

	/**
	 * Fix rendering of archives block when displayAsDropdown.
	 *
	 * This replaces disallowed script with the use of on:change for <select>.
	 *
	 * @see render_block_core_archives()
	 *
	 * @param string $block_content Block content.
	 * @return string Rendered.
	 */
	public function ampify_archives_block( $block_content ) {

		// Eliminate use of uniqid(). Core should be using wp_unique_id() here.
		static $block_id = 0;
		$block_id++;
		$block_content = preg_replace( '/(?<="wp-block-archives-)\w+(?=")/', $block_id, $block_content );

		// Replace onchange with on attribute.
		$block_content = preg_replace(
			'/onchange=".+?"/',
			'on="change:AMP.navigateTo(url=event.value)"',
			$block_content
		);

		return $block_content;
	}

	/**
	 * Ampify video block.
	 *
	 * Inject the video attachment's dimensions if available. This prevents having to try to look up the attachment
	 * post by the video URL in `\AMP_Video_Sanitizer::filter_video_dimensions()`.
	 *
	 * @see \AMP_Video_Sanitizer::filter_video_dimensions()
	 *
	 * @param string $block_content The block content about to be appended.
	 * @param array  $block         The full block, including name and attributes.
	 * @return string Filtered block content.
	 */
	public function ampify_video_block( $block_content, $block ) {
		if ( empty( $block['attrs']['id'] ) || 'attachment' !== get_post_type( $block['attrs']['id'] ) ) {
			return $block_content;
		}

		$meta_data = wp_get_attachment_metadata( $block['attrs']['id'] );
		if ( isset( $meta_data['width'], $meta_data['height'] ) ) {
			$block_content = preg_replace(
				'/(?<=<video\s)/',
				sprintf( 'width="%d" height="%d" ', $meta_data['width'], $meta_data['height'] ),
				$block_content
			);
		}

		return $block_content;
	}

	/**
	 * Ampify cover block.
	 *
	 * This specifically fixes the layout of the block when a background video is assigned.
	 *
	 * @see \AMP_Video_Sanitizer::filter_video_dimensions()
	 *
	 * @param string $block_content The block content about to be appended.
	 * @param array  $block         The full block, including name and attributes.
	 * @return string Filtered block content.
	 */
	public function ampify_cover_block( $block_content, $block ) {

		// @todo Actually, this can be ignored in favor of supporting data-object-fit and data-object-position on all elements in the same way data-amp-object-fit and data-amp-object-position are supproted?

		$block_content = preg_replace_callback(
			'/(<(?:img|video))(\s[^>]+)>/',
			static function ( $matches ) {
				// Skip inner image and video blocks which aren't the background for the cover block.
				if ( ! preg_match( '/ class="[^"]*?wp-block-cover__(?:image|video)-background[^"]*?"/', $matches[2] ) ) {
					return $matches[0];
				}

				$replacement = $matches[1];

				// The background image/video for the cover block by definition needs object-fit="cover" on the resulting amp-ing/amp-video.
				$replacement .= ' object-fit="cover"';

				// Add the fill layout to skip the needlessly obtaining the dimensions.
				$replacement .= ' layout="fill"';

				// Extract the object-position from the style attribute to add to the img/video to be copied onto the amp-img/amp-video.
				// The AMP runtime copies object-position attribute onto the underlying img/video for a given amp-img/amp-video.
				// This is needed since the object-position property directly on an amp-img/amp-video will have no effect since
				// since it is merely a wrapper for the underlying img/video element which actually supports the CSS property.
				// Note that Gutenberg 9.8 also added a data-object-position attribute on the img/video via <https://github.com/WordPress/gutenberg/pull/25171>
				// and specifically <https://github.com/WordPress/gutenberg/commit/c963e4c> which could be used instead
				// of extracting the object-position from the style attribute, but this was not present in older versions.
				// So this is why the object-position is being extracted from the style attribute instead of the
				// data-object-position which may not exist.
				$replacement .= preg_replace(
					'/ style="[^"]*?object-position\s*:\s*([^";]+)[^"]*?"/',
					'$0 object-position="$1"',
					$matches[2]
				);

				// Copy data-object-position as just object-position which AMP supports on amp-img and amp-video.
				// This attribute was introduced in Gutenberg 9.8 via <https://github.com/WordPress/gutenberg/pull/25171>
				// and specifically <https://github.com/WordPress/gutenberg/commit/c963e4c>.
//				$replacement .= preg_replace(
//					'/ data-(object-position="[^"]*?")/',
//					'$0 $1',
//					$matches[2]
//				);


				$replacement .= '>';
				return $replacement;
			},
			$block_content
		);

		// @todo This could eliminate the inline style which only is containing the object-position style.
		// @todo If using older version of Gutenberg in which the object position was exclusively defined in the style attribute, extract the position


		if ( isset( $block['attrs']['backgroundType'] ) && 'video' === $block['attrs']['backgroundType'] ) {
//			$block_content = preg_replace(
//				'/(?<=<video\s)/',
//				'layout="fill" object-fit="cover" ',
//				$block_content
//			);
		}

		// @todo How long has Gutenerg added the object-fit
//		$block_content = preg_replace( '/\sdata-object-fit=/', 'object-fit=', $block_content );

		return $block_content;
	}

	/**
	 * Sanitize widgets that are not added via Gutenberg.
	 *
	 * @param Document $dom  Document.
	 * @param array    $args Args passed to sanitizer.
	 */
	public function sanitize_raw_embeds( Document $dom, $args = [] ) {
		$this->process_categories_widgets( $dom );
		$this->process_archives_widgets( $dom, $args );
		$this->process_text_widgets( $dom );
	}

	/**
	 * Process "Categories" widgets.
	 *
	 * @since 2.0
	 *
	 * @param Document $dom Document.
	 */
	private function process_categories_widgets( Document $dom ) {
		$selects = $dom->xpath->query( '//form/select[ @name = "cat" ]' );
		foreach ( $selects as $select ) {
			if ( ! $select instanceof DOMElement ) {
				continue;
			}
			$form = $select->parentNode;
			if ( ! $form instanceof DOMElement || ! $form->parentNode instanceof DOMElement ) {
				continue;
			}
			$script = $dom->xpath->query( './/script[ contains( text(), "onCatChange" ) ]', $form->parentNode )->item( 0 );
			if ( ! $script instanceof DOMElement ) {
				continue;
			}

			$this->category_widget_count++;
			$id = sprintf( 'amp-wp-widget-categories-%d', $this->category_widget_count );

			$form->setAttribute( 'id', $id );

			AMP_DOM_Utils::add_amp_action( $select, 'change', sprintf( '%s.submit', $id ) );
			$script->parentNode->removeChild( $script );
		}
	}

	/**
	 * Process "Archives" widgets.
	 *
	 * @since 2.0
	 *
	 * @param Document $dom  Select node retrieved from the widget.
	 * @param array    $args Args passed to sanitizer.
	 */
	private function process_archives_widgets( Document $dom, $args = [] ) {
		$selects = $dom->xpath->query( '//select[ @name = "archive-dropdown" and starts-with( @id, "archives-dropdown-" ) ]' );
		foreach ( $selects as $select ) {
			if ( ! $select instanceof DOMElement ) {
				continue;
			}

			$script = $dom->xpath->query( './/script[ contains( text(), "onSelectChange" ) ]', $select->parentNode )->item( 0 );
			if ( $script ) {
				$script->parentNode->removeChild( $script );
			} elseif ( $select->hasAttribute( 'onchange' ) ) {
				// Special condition for WordPress<=5.1.
				$select->removeAttribute( 'onchange' );
			} else {
				continue;
			}

			AMP_DOM_Utils::add_amp_action( $select, 'change', 'AMP.navigateTo(url=event.value)' );

			// When AMP-to-AMP linking is enabled, ensure links go to the AMP version.
			if ( ! empty( $args['amp_to_amp_linking_enabled'] ) ) {
				foreach ( $dom->xpath->query( '//option[ @value != "" ]', $select ) as $option ) {
					/**
					 * Option element.
					 *
					 * @var DOMElement $option
					 */
					$option->setAttribute( 'value', amp_add_paired_endpoint( $option->getAttribute( 'value' ) ) );
				}
			}
		}
	}

	/**
	 * Preserve dimensions of elements in a Text widget to later restore to circumvent WordPress core stripping them out.
	 *
	 * Core strips out the dimensions to prevent the element being made too wide for the sidebar. This is not a concern
	 * in AMP because of responsive sizing. So this logic is here to undo what core is doing.
	 *
	 * @since 2.0
	 * @see WP_Widget_Text::inject_video_max_width_style()
	 * @see AMP_Core_Block_Handler::process_text_widgets()
	 *
	 * @param string $content Content.
	 * @return string Content.
	 */
	public function preserve_widget_text_element_dimensions( $content ) {
		$content = preg_replace_callback(
			'#<(video|iframe|object|embed)\s[^>]*>#si',
			static function ( $matches ) {
				$html = $matches[0];
				$html = preg_replace( '/(?=\sheight="(\d+)")/', ' ' . self::AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME . '="$1" ', $html );
				$html = preg_replace( '/(?=\swidth="(\d+)")/', ' ' . self::AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME . '="$1" ', $html );
				return $html;
			},
			$content
		);

		return $content;
	}

	/**
	 * Process "Text" widgets.
	 *
	 * @since 2.0
	 * @see AMP_Core_Block_Handler::preserve_widget_text_element_dimensions()
	 *
	 * @param Document $dom Select node retrieved from the widget.
	 */
	private function process_text_widgets( Document $dom ) {
		foreach ( $dom->xpath->query( '//div[ @class = "textwidget" ]' ) as $text_widget ) {
			// Restore the width/height attributes which were preserved in preserve_widget_text_element_dimensions.
			foreach ( $dom->xpath->query( sprintf( './/*[ @%s or @%s ]', self::AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME, self::AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME ), $text_widget ) as $element ) {
				if ( $element->hasAttribute( self::AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME ) ) {
					$element->setAttribute( Attribute::WIDTH, $element->getAttribute( self::AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME ) );
					$element->removeAttribute( self::AMP_PRESERVED_WIDTH_ATTRIBUTE_NAME );
				}
				if ( $element->hasAttribute( self::AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME ) ) {
					$element->setAttribute( Attribute::HEIGHT, $element->getAttribute( self::AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME ) );
					$element->removeAttribute( self::AMP_PRESERVED_HEIGHT_ATTRIBUTE_NAME );
				}
			}

			/*
			 * Remove inline width style which is added to video shortcode but which overruns the container.
			 * Normally this width gets overridden by wp-mediaelement.css to be max-width: 100%, but since
			 * MediaElement.js is not used in AMP this stylesheet is not included. In any case, videos in AMP are
			 * responsive so this is built-in. Note also the style rule for .wp-video in amp-default.css.
			 */
			foreach ( $dom->xpath->query( './/div[ @class = "wp-video" and @style ]', $text_widget ) as $element ) {
				$element->removeAttribute( 'style' );
			}
		}
	}
}
