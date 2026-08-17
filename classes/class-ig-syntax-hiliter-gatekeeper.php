<?php
/**
 * Gatekeeper class for the plugin, which loads the plugin only if the minimum
 * requirements are satisfied.
 *
 * IMPORTANT: this file is parsed by whatever PHP version the site is running.
 * If it fails to parse, the site fatals before the version check can run and a
 * graceful refusal becomes impossible. It therefore contains no PHP 8.4 only
 * syntax — no typed properties, no return types, no arrow functions, no named
 * arguments, no union or intersection types, no constructor promotion and no
 * `match`. It is also, deliberately, the only class in this plugin that is not
 * namespaced. Everything loaded past this gate may use full PHP 8.4 syntax.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 *
 * @since 2015-07-26
 */

/**
 * Refuses to load the plugin unless the environment meets its minimum PHP and
 * WordPress versions, and says so once, clearly, in wp-admin.
 */
final class iG_Syntax_Hiliter_Gatekeeper {

	/**
	 * Plugin name, for display. Hardcoded because none of the plugin's classes
	 * are loaded when the Gatekeeper refuses passage.
	 *
	 * @var string
	 */
	const PLUGIN_NAME = 'iG:Syntax Hiliter';

	/**
	 * Lowest PHP version the plugin runs on.
	 *
	 * @var string
	 */
	const MIN_PHP_VERSION_REQUIRED = '8.4';

	/**
	 * Lowest WordPress version the plugin runs on.
	 *
	 * @var string
	 */
	const MIN_WP_VERSION_REQUIRED = '6.9';

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( self::is_environment_supported() ) {

			$this->_load_plugin();

		} else {
			add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );
		}

	}    //end __construct()

	/**
	 * Factory method to initialize the class.
	 *
	 * @return iG_Syntax_Hiliter_Gatekeeper
	 */
	public static function activate() {

		$class = get_called_class();

		return new $class();

	}    //end activate()

	/**
	 * Checks the environment the plugin has actually been loaded into.
	 *
	 * @return bool Returns TRUE if the minimum requirements are met, else FALSE.
	 */
	public static function is_environment_supported() {

		return self::meets_requirements( self::get_php_version(), self::get_wp_version() );

	}    //end is_environment_supported()

	/**
	 * Checks a pair of versions against the plugin's minimum requirements.
	 *
	 * Kept free of any environment lookup of its own so that the comparison can
	 * be exercised directly by the test suite, which cannot change the PHP or
	 * WordPress version it is running on.
	 *
	 * @param string $php_version PHP version to check.
	 * @param string $wp_version  WordPress version to check.
	 * @return bool Returns TRUE if both versions are supported, else FALSE.
	 */
	public static function meets_requirements( $php_version, $wp_version ) {

		if ( ! self::is_version_at_least( $php_version, self::MIN_PHP_VERSION_REQUIRED ) ) {
			return false;
		}

		if ( ! self::is_version_at_least( $wp_version, self::MIN_WP_VERSION_REQUIRED ) ) {
			return false;
		}

		return true;

	}    //end meets_requirements()

	/**
	 * Compares two versions, ignoring any pre release suffix on either.
	 *
	 * @param string $version Version to check.
	 * @param string $minimum Lowest acceptable version.
	 * @return bool Returns TRUE if $version is at least $minimum, else FALSE.
	 */
	public static function is_version_at_least( $version, $minimum ) {

		return version_compare( self::normalize_version( $version ), self::normalize_version( $minimum ), '>=' );

	}    //end is_version_at_least()

	/**
	 * Reduces a version to three numeric components.
	 *
	 * WordPress ships versions such as `6.9`, `7.0.3` and `6.9-beta1`, and PHP
	 * ships versions such as `8.5.0RC1`. version_compare() ranks `6.9` below
	 * `6.9.0` and any pre release below its own release, so both shapes are
	 * flattened before they are compared: the first non numeric character and
	 * everything after it is dropped, and the result is padded to three parts.
	 *
	 * @param string $version Version to normalize.
	 * @return string Version as three dot separated integers.
	 */
	public static function normalize_version( $version ) {

		$version = preg_replace( '/[^0-9.].*$/', '', trim( strval( $version ) ) );
		$version = trim( strval( $version ), '.' );

		if ( '' === $version ) {
			return '0.0.0';
		}

		$parts = array_slice( array_pad( explode( '.', $version ), 3, '0' ), 0, 3 );

		return implode( '.', array_map( 'intval', $parts ) );

	}    //end normalize_version()

	/**
	 * Returns the PHP version in use.
	 *
	 * @return string
	 */
	public static function get_php_version() {

		return strval( phpversion() );

	}    //end get_php_version()

	/**
	 * Returns the WordPress version in use.
	 *
	 * Read straight off the global that WordPress sets in `wp-includes/version.php`,
	 * so that no WordPress API needs to exist for the check to work.
	 *
	 * @return string
	 */
	public static function get_wp_version() {

		if ( isset( $GLOBALS['wp_version'] ) ) {
			return strval( $GLOBALS['wp_version'] );
		}

		return '0';

	}    //end get_wp_version()

	/**
	 * Loads the plugin.
	 *
	 * @return void
	 */
	private function _load_plugin() {

		//load up the autoloader
		require_once IG_SYNTAX_HILITER_ROOT . '/autoloader.php';

		//hand off to the orchestrator
		\iG\Syntax_Hiliter\Plugin::get_instance();

	}    //end _load_plugin()

	/**
	 * Called on the 'admin_notices' hook, this shows an error message on all
	 * admin screens (on purpose) telling the administrator that the plugin's
	 * minimum requirements are not met, and what the environment actually is.
	 *
	 * @return void
	 */
	public function show_admin_notice() {

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: plugin name, 2: required PHP version, 3: required WordPress version, 4: PHP version in use, 5: WordPress version in use */
					__( '%1$s has not been loaded. It needs PHP %2$s or newer and WordPress %3$s or newer, but this site is running PHP %4$s and WordPress %5$s.', 'igsyntax-hiliter' ),
					self::PLUGIN_NAME,
					self::MIN_PHP_VERSION_REQUIRED,
					self::MIN_WP_VERSION_REQUIRED,
					self::get_php_version(),
					self::get_wp_version()
				)
			)
		);

	}    //end show_admin_notice()

}    //end of class

//EOF
