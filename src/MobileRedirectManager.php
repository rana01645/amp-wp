<?php
/**
 * Class MobileRedirect.
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP;

use AMP_Options_Manager;

/**
 * Centralized manager to handle mobile redirection of a page.
 *
 * @package AmpProject\AmpWP
 */
final class MobileRedirectManager {

	/**
	 * The name of the cookie that persists the user's preference for viewing the non-AMP version of a page when on mobile.
	 *
	 * @var string
	 */
	const DISABLED_COOKIE_NAME = 'amp_mobile_redirect_disabled';

	/**
	 * Query parameter to indicate that the page in question should not be served as AMP.
	 *
	 * @var string
	 */
	const NO_AMP_QUERY_VAR = 'noamp';

	/**
	 * Get whether mobile redirection is enabled or not.
	 *
	 * @return bool If JS redirection is disabled, only the status of the mobile redirection option is returned.
	 *              Otherwise, returns true if mobile redirection option is enabled and current request is from a mobile device.
	 */
	public static function is_enabled() {
		$option_enabled = AMP_Options_Manager::get_option( Option::MOBILE_REDIRECT );

		return self::should_redirect_via_js() ? $option_enabled : $option_enabled && self::is_mobile();
	}

	/**
	 * Determine if the current request is from a mobile device.
	 *
	 * @return bool True if current request is from a mobile device, otherwise false.
	 */
	public static function is_mobile() {
		/**
		 * Filters whether the current request is from a mobile device. This is provided as a means to short-circuit
		 * the normal determination of a mobile request below.
		 *
		 * @since 1.6
		 *
		 * @param bool $is_mobile Whether the current request is from a mobile device.
		 */
		$pre_is_mobile = apply_filters( 'amp_pre_is_mobile', false );

		if ( true === $pre_is_mobile ) {
			return (bool) $pre_is_mobile;
		}

		$current_user_agent = $_SERVER['HTTP_USER_AGENT'];

		if ( empty( $current_user_agent ) ) {
			return false;
		}

		$is_mobile   = false;
		$user_agents = self::get_user_agents();

		foreach ( $user_agents as $user_agent ) {
			if ( false !== strpos( $current_user_agent, $user_agent ) ) {
				$is_mobile = true;
				break;
			}
		}

		return $is_mobile;
	}

	/**
	 * Determine if mobile redirection should be done via JavaScript.
	 *
	 * @return bool True if mobile redirection should be done, false otherwise.
	 */
	public static function should_redirect_via_js() {
		/**
		 * Filters whether mobile redirection should be done via JavaScript. If false, a server-side solution will be used instead.
		 *
		 * @since 1.6
		 *
		 * @param bool $should_redirect_via_js Whether JS redirection should be used.
		 */
		return (bool) apply_filters( 'amp_redirect_via_js', true );
	}

	/**
	 * Get a list of user agents to use for comparison against the user agent from the current request.
	 *
	 * @return string[] An array of user agents.
	 */
	public static function get_user_agents() {
		// Default list compiled from the user agents listed in `wp_is_mobile()`.
		$default_user_agents = [
			'Mobile',
			'Android',
			'Silk/',
			'Kindle',
			'BlackBerry',
			'Opera Mini',
			'Opera Mobi',
		];

		/**
		 * Filters the list of user agents used to determine if the user agent from the current request is a mobile one.
		 *
		 * @since 1.6
		 *
		 * @param string[] $user_agents List of user agents.
		 */
		return apply_filters( 'amp_mobile_user_agents', $default_user_agents );
	}

	/**
	 * Determine if mobile redirection is disabled for the browser session.
	 *
	 * @return bool True if disabled, false otherwise.
	 */
	public static function redirection_disabled() {
		return isset( $_COOKIE[ self::DISABLED_COOKIE_NAME ] ) && '1' === $_COOKIE[ self::DISABLED_COOKIE_NAME ];
	}

	/**
	 * Output the mobile redirection Javascript code.
	 */
	public static function add_mobile_redirect_script() {
		?>
		<script>
			(function ( ampSlug, disabledCookieName, userAgents ) {
				const mobileRedirectionDisabled = document.cookie
						.split(';')
						.some( ( item ) => `${ disabledCookieName }=1` === item.trim() );

				// Short-circuit if mobile redirection is disabled.
				if ( mobileRedirectionDisabled ) {
					return;
				}

				const currentUserAgent = navigator.userAgent;
				const isMobile = userAgents.some( ( userAgent ) => currentUserAgent.includes( userAgent ) );

				const url = new URL( location.href );

				if ( isMobile && ! url.searchParams.has( ampSlug ) ) {
					window.stop(); // Stop loading the page! This should cancel all loading resources.

					// Replace the current page with the AMP version.
					url.searchParams.append( ampSlug, '1' );
					location.replace( url.href );
				}
			} )(
				<?php echo wp_json_encode( amp_get_slug() ); ?>,
				<?php echo wp_json_encode( self::DISABLED_COOKIE_NAME ); ?>,
				<?php echo wp_json_encode( self::get_user_agents() ); ?>
			)
		</script>
		<?php
	}

	/**
	 * Output the markup for the mobile version switcher.
	 *
	 * @param string $url  URL to canonical version of page.
	 * @param string $text Text for the anchor element.
	 */
	public static function add_mobile_version_switcher_markup( $url, $text ) {
		?>
		<style>
			#version-switch-link {
				display: block;
				width: 100%;
				padding: 15px 0;
				font-size: 16px;
				font-weight: 600;
				color: #eaeaea;
				text-align: center;
				background-color: #444;
				border: 0;
			}
		</style>
		<div>
			<a
				id="version-switch-link"
				rel="noamphtml"
				href="<?php echo esc_url( $url ); ?>"
			>
				<?php echo esc_html( $text ); ?>
			</a>
		</div>
		<?php
	}
}
