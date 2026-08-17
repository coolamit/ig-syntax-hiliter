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
	 * PHP version this Gatekeeper judges. Untyped: typed properties are PHP 7.4
	 * and up, and this file is parsed by whatever PHP the site is running.
	 *
	 * @var string
	 */
	private $_php_version;

	/**
	 * WordPress version this Gatekeeper judges.
	 *
	 * @var string
	 */
	private $_wp_version;

	/**
	 * Constructor.
	 *
	 * Reads the environment and does nothing else. **It must stay that way.** The
	 * work is in run(), which is what lets a test build one of these and ask it
	 * questions — including about a PHP or a WordPress the test suite is not
	 * running on, which is the whole point of the class and cannot be arranged any
	 * other way.
	 *
	 * The WordPress version is read straight off the global that WordPress sets in
	 * `wp-includes/version.php`, so that no WordPress API needs to exist for the
	 * check to work. An unreadable one is `0`, so an unknown WordPress is refused
	 * rather than waved through.
	 *
	 * @param string $php_version Optional PHP version to judge instead of this one.
	 * @param string $wp_version  Optional WordPress version to judge instead of this one.
	 */
	public function __construct( $php_version = null, $wp_version = null ) {

		$this->_php_version = ( null === $php_version ) ? strval( phpversion() ) : strval( $php_version );

		if ( null !== $wp_version ) {
			$this->_wp_version = strval( $wp_version );
		} elseif ( isset( $GLOBALS['wp_version'] ) ) {
			$this->_wp_version = strval( $GLOBALS['wp_version'] );
		} else {
			$this->_wp_version = '0';
		}

	}    //end __construct()

	/**
	 * Factory method to initialize the class.
	 *
	 * The one static in this class, and the only one wanted: everything else is
	 * asked of an object. `new self()` rather than late static binding, because
	 * the class is final and so there is no other class it could ever resolve to.
	 *
	 * @return iG_Syntax_Hiliter_Gatekeeper
	 */
	public static function activate() {

		$gatekeeper = new self();

		$gatekeeper->run();

		return $gatekeeper;

	}    //end activate()

	/**
	 * Loads the plugin, or arranges to say why it was not loaded.
	 *
	 * Everything in this class which changes anything is here, and nothing else in
	 * it changes anything. That is what makes the rest of it safe to call from a
	 * test.
	 *
	 * @return void
	 */
	public function run() {

		if ( $this->is_environment_supported() ) {

			$this->_load_plugin();

			return;

		}

		add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );

	}    //end run()

	/**
	 * Checks the versions this Gatekeeper was built with against the plugin's
	 * minimum requirements.
	 *
	 * @return bool Returns TRUE if the minimum requirements are met, else FALSE.
	 */
	public function is_environment_supported() {

		if ( ! $this->_is_version_at_least( $this->_php_version, self::MIN_PHP_VERSION_REQUIRED ) ) {
			return false;
		}

		if ( ! $this->_is_version_at_least( $this->_wp_version, self::MIN_WP_VERSION_REQUIRED ) ) {
			return false;
		}

		return true;

	}    //end is_environment_supported()

	/**
	 * Compares two versions, ignoring any pre release suffix on either.
	 *
	 * @param string $version Version to check.
	 * @param string $minimum Lowest acceptable version.
	 * @return bool Returns TRUE if $version is at least $minimum, else FALSE.
	 */
	private function _is_version_at_least( $version, $minimum ) {

		return version_compare( $this->_normalize_version( $version ), $this->_normalize_version( $minimum ), '>=' );

	}    //end _is_version_at_least()

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
	private function _normalize_version( $version ) {

		$version = preg_replace( '/[^0-9.].*$/', '', trim( strval( $version ) ) );
		$version = trim( strval( $version ), '.' );

		if ( '' === $version ) {
			return '0.0.0';
		}

		$parts = array_slice( array_pad( explode( '.', $version ), 3, '0' ), 0, 3 );

		return implode( '.', array_map( 'intval', $parts ) );

	}    //end _normalize_version()

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
	 * The versions named are the ones this Gatekeeper judged, not a fresh lookup,
	 * so the message and the refusal it explains can never be about different
	 * things.
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
					$this->_php_version,
					$this->_wp_version
				)
			)
		);

	}    //end show_admin_notice()

}    //end of class

//EOF
