<?php
/**
 * Gatekeeper class for the plugin, which loads the plugin only if the minimum
 * requirements are satisfied.
 *
 * This file is parsed by whatever PHP version the site is running, so it must
 * contain no PHP 7+ syntax: if it fails to parse, the refusal can never run. It
 * is the only class in the plugin that is not namespaced. Everything loaded past
 * this gate may use full PHP 8.4 syntax.
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
	 * Plugin name, for display. Hardcoded because no other class is loaded on refusal.
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
	 * PHP version this Gatekeeper judges. Untyped: typed properties are PHP 7.4 and up.
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
	 * Reads the environment and does nothing else, so a test can build one and ask it
	 * about a PHP or WordPress it is not running on. The WP version is read straight
	 * off `$GLOBALS['wp_version']` so no WordPress API need exist; an unreadable one
	 * is `0`, so an unknown WordPress is refused.
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

	}

	/**
	 * Factory method to initialize the class.
	 *
	 * `new self()` rather than late static binding, because the class is final.
	 *
	 * @return iG_Syntax_Hiliter_Gatekeeper
	 */
	public static function activate() {

		$gatekeeper = new self();

		$gatekeeper->run();

		return $gatekeeper;

	}

	/**
	 * Loads the plugin, or arranges to say why it was not loaded.
	 *
	 * Every side effect the class has is here, so the rest is safe to call from a test.
	 *
	 * @return void
	 */
	public function run() {

		if ( $this->is_environment_supported() ) {

			$this->_load_plugin();

			return;

		}

		add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );

	}

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

	}

	/**
	 * Compares two versions, ignoring any pre release suffix on either.
	 *
	 * @param string $version Version to check.
	 * @param string $minimum Lowest acceptable version.
	 * @return bool Returns TRUE if $version is at least $minimum, else FALSE.
	 */
	private function _is_version_at_least( $version, $minimum ) {

		return version_compare( $this->_normalize_version( $version ), $this->_normalize_version( $minimum ), '>=' );

	}

	/**
	 * Reduces a version to three numeric components.
	 *
	 * WordPress ships versions such as `6.9`, `7.0.3` etc, and PHP
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

		if ( empty( $version ) ) {
			return '0.0.0';
		}

		$parts = array_slice( array_pad( explode( '.', $version ), 3, '0' ), 0, 3 );

		return implode( '.', array_map( 'intval', $parts ) );

	}

	/**
	 * Loads the plugin.
	 *
	 * @return void
	 */
	private function _load_plugin() {

		require_once IG_SYNTAX_HILITER_ROOT . '/autoloader.php';

		\iG\Syntax_Hiliter\Plugin::get_instance();

	}

	/**
	 * Shows an error notice on every admin screen saying the requirements are not
	 * met and what the environment is.
	 *
	 * The versions named are the ones this Gatekeeper judged, not a fresh lookup,
	 * so the message and the refusal can never disagree.
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

	}

} // end of class

// EOF
