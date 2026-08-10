<?php
/**
 * The plugin's settings screen and the REST route which saves it.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Settings screen, and the REST route the screen saves through.
 *
 * Settings save one at a time, as they always have: changing a control sends that
 * one setting and nothing else. The route is the only way in, so the same schema
 * decides what may be written whether the request came from the screen or from
 * anywhere else.
 *
 * The route has to be registered on every request, not only in wp-admin, because
 * `rest_api_init` runs on requests where `is_admin()` is false and `REST_REQUEST`
 * is not defined until long after this plugin loads. Everything else this class
 * hooks is on an admin only hook and costs nothing elsewhere.
 */
class Admin extends Base {

	/**
	 * Namespace every one of the plugin's REST routes lives under.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'igsyntax-hiliter/v1';

	/**
	 * Capability required to read or change anything this class exposes.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Menu slug of the settings page.
	 *
	 * @var string
	 */
	const PAGE_SLUG = self::PLUGIN_ID . '-page';

	/**
	 * Hook suffix WordPress gives the settings page.
	 *
	 * @var string
	 */
	const PAGE_HOOK = 'settings_page_' . self::PAGE_SLUG;

	/**
	 * Whether the hooks have been registered already.
	 *
	 * @var bool
	 */
	protected bool $_hooked = false;

	/**
	 * Method to hook the settings screen and its route up to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {

		if ( $this->_hooked ) {
			return;
		}

		$this->_hooked = true;

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_migration_message' ] );

		add_filter( 'plugin_action_links', [ $this, 'get_action_links' ], 10, 2 );

	}    //end register_hooks()

	/**
	 * Method to decide whether the current user may use the plugin's REST routes.
	 *
	 * Logged out is answered with `401` and under privileged with `403`, so that a
	 * caller can tell "log in" apart from "you may not do this". Neither answer
	 * reaches a callback, so neither can change a stored setting.
	 *
	 * @return true|\WP_Error TRUE when the request may proceed, an error otherwise.
	 */
	public static function rest_permission_check() {

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ig_syntax_hiliter_rest_not_logged_in',
				__( 'You must be logged in to do that.', 'igsyntax-hiliter' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( static::CAPABILITY ) ) {
			return new WP_Error(
				'ig_syntax_hiliter_rest_forbidden',
				__( 'You are not allowed to change these settings.', 'igsyntax-hiliter' ),
				[ 'status' => 403 ]
			);
		}

		return true;

	}    //end rest_permission_check()

	/**
	 * Method to get the settings the screen shows and the route accepts.
	 *
	 * This is the only list of writable settings there is. A name which is not a key
	 * here is rejected by the route, so the route can never be used to write an
	 * arbitrary option.
	 *
	 * @return array Setting name to its type, label, description and permitted values.
	 */
	public static function get_settings_schema(): array {

		$yes_no = [
			'yes' => __( 'Yes', 'igsyntax-hiliter' ),
			'no'  => __( 'No', 'igsyntax-hiliter' ),
		];

		return [
			'theme'                => [
				'type'        => 'choice',
				'label'       => __( 'Theme', 'igsyntax-hiliter' ),
				'description' => __( 'Colour scheme used for code boxes on the front end.', 'igsyntax-hiliter' ),
				'choices'     => static::get_theme_choices(),
			],
			'toolbar'              => [
				'type'        => 'toggle',
				'label'       => __( 'Show the toolbar', 'igsyntax-hiliter' ),
				'description' => __( 'Puts a small toolbar above each code box, which carries the file label and the copy button.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'copy_code'            => [
				'type'        => 'toggle',
				'label'       => __( 'Show the copy button', 'igsyntax-hiliter' ),
				'description' => __( 'Adds a button which copies the code to the clipboard. It lives in the toolbar, so it needs the toolbar switched on.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'show_line_numbers'    => [
				'type'        => 'toggle',
				'label'       => __( 'Show line numbers', 'igsyntax-hiliter' ),
				'description' => __( 'The default for every code box. A single snippet can override it with the gutter attribute or the block setting.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'normalize_whitespace' => [
				'type'        => 'toggle',
				'label'       => __( 'Normalize whitespace', 'igsyntax-hiliter' ),
				'description' => __( 'Trims blank lines and strips the indentation shared by every line of a snippet. Off by default, because that indentation is often deliberate.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'hilite_comments'      => [
				'type'        => 'toggle',
				'label'       => __( 'Highlight code in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Runs the same highlighting over code posted in comments.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'gist_in_comments'     => [
				'type'        => 'toggle',
				'label'       => __( 'Allow Gist embeds in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Lets a commenter embed a GitHub Gist with the github shortcode. Off by default, because it lets a commenter load a third party script.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
		];

	}    //end get_settings_schema()

	/**
	 * Method to get the themes offered by the theme setting.
	 *
	 * The bundled themes are those whose stylesheet is actually readable on disk, so
	 * a theme which is not shipped is never offered. "None" is last, because picking
	 * it means the code boxes are styled by the site's own CSS and nothing else.
	 *
	 * @return array Theme setting value to its label.
	 */
	public static function get_theme_choices(): array {

		$choices = Asset_Manager::get_themes();

		$choices[ Asset_Manager::THEME_NONE ] = __( 'None — load no theme stylesheet', 'igsyntax-hiliter' );

		return $choices;

	}    //end get_theme_choices()

	/**
	 * Method to register the plugin's REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {

		register_rest_route(
			static::REST_NAMESPACE,
			'/option',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_option' ],
				'permission_callback' => [ static::class, 'rest_permission_check' ],
				'args'                => [
					'name'  => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => array_keys( static::get_settings_schema() ),
						'description'       => __( 'Name of the setting to save.', 'igsyntax-hiliter' ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => [ static::class, 'validate_option_name' ],
					],
					'value' => [
						'type'              => 'string',
						'required'          => true,
						'description'       => __( 'Value to save the setting as.', 'igsyntax-hiliter' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ static::class, 'validate_option_value' ],
					],
				],
			]
		);

	}    //end register_rest_routes()

	/**
	 * Method to check that a setting name is one this plugin owns.
	 *
	 * @param mixed $value Name as it was sent.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_option_name( $value ) {

		if ( is_string( $value ) && array_key_exists( sanitize_key( $value ), static::get_settings_schema() ) ) {
			return true;
		}

		return new WP_Error(
			'ig_syntax_hiliter_unknown_setting',
			__( 'That is not one of this plugin\'s settings.', 'igsyntax-hiliter' ),
			[ 'status' => 400 ]
		);

	}    //end validate_option_name()

	/**
	 * Method to check that a value is one the named setting accepts.
	 *
	 * Validation runs before sanitization, so the name is read raw here and cleaned
	 * up before it is looked up.
	 *
	 * @param mixed            $value   Value as it was sent.
	 * @param \WP_REST_Request $request Request being validated.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_option_value( $value, $request ) {

		//the name is whatever was sent, which is not necessarily a string: casting an array
		//raises a warning, and a warning printed ahead of the response body is what the
		//caller reads instead of the 400 this returns
		$name   = ( $request instanceof WP_REST_Request && is_scalar( $request['name'] ) ) ? sanitize_key( (string) $request['name'] ) : '';
		$schema = static::get_settings_schema();

		if ( ! isset( $schema[ $name ] ) ) {
			return new WP_Error(
				'ig_syntax_hiliter_unknown_setting',
				__( 'That is not one of this plugin\'s settings.', 'igsyntax-hiliter' ),
				[ 'status' => 400 ]
			);
		}

		if ( is_string( $value ) && isset( $schema[ $name ]['choices'][ $value ] ) ) {
			return true;
		}

		return new WP_Error(
			'ig_syntax_hiliter_invalid_setting_value',
			sprintf(
				/* translators: %s: name of the setting. */
				__( 'That is not a value the %s setting accepts.', 'igsyntax-hiliter' ),
				$name
			),
			[ 'status' => 400 ]
		);

	}    //end validate_option_value()

	/**
	 * Method to save one setting.
	 *
	 * The schema is checked again here rather than trusted from validation, and
	 * success is reported only once the value is in the database.
	 *
	 * @param \WP_REST_Request $request Request being served.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_option( WP_REST_Request $request ) {

		$name   = sanitize_key( (string) $request['name'] );
		$value  = (string) $request['value'];
		$schema = static::get_settings_schema();

		if ( ! isset( $schema[ $name ]['choices'][ $value ] ) ) {
			return new WP_Error(
				'ig_syntax_hiliter_invalid_setting',
				__( 'That setting or that value is not one this plugin knows.', 'igsyntax-hiliter' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->_option->save( $name, $value ) ) {
			return new WP_Error(
				'ig_syntax_hiliter_setting_not_saved',
				__( 'The setting could not be saved.', 'igsyntax-hiliter' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'name'    => $name,
				'value'   => (string) $this->_option->get( $name ),
				'message' => __( 'Setting saved.', 'igsyntax-hiliter' ),
			]
		);

	}    //end save_option()

	/**
	 * Method to add the settings page to the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {

		add_options_page(
			sprintf(
				/* translators: %s: plugin name. */
				__( '%s Options', 'igsyntax-hiliter' ),
				static::PLUGIN_NAME
			),
			static::PLUGIN_NAME,
			static::CAPABILITY,
			static::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

	}    //end add_menu()

	/**
	 * Method to render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {

		$options  = $this->_option->get_all();
		$settings = [];

		foreach ( static::get_settings_schema() as $name => $setting ) {

			$value = $options[ $name ] ?? '';
			$value = ( is_scalar( $value ) ) ? (string) $value : '';

			/*
			 * A stored value the setting does not offer falls back to that setting's own
			 * default, and not to its first choice. Every consumer of a yes/no setting
			 * compares against `yes`, so an unrecognised value behaves as off; falling
			 * back to the first choice would draw the control as on, and a control which
			 * already looks right is one nobody puts right.
			 */
			$setting['name']  = $name;
			$setting['value'] = ( isset( $setting['choices'][ $value ] ) ) ? $value : $this->_option->get_default( $name );

			$settings[ $name ] = $setting;

		}

		Helper::render_template(
			sprintf( '%s/templates/plugin-options-page.php', untrailingslashit( IG_SYNTAX_HILITER_ROOT ) ),
			[
				'plugin_name' => static::PLUGIN_NAME,
				'settings'    => $settings,
				'dropin_path' => static::get_dropin_display_path(),
			],
			true
		);

	}    //end render_page()

	/**
	 * Method to get the drop-in language directory as a path worth showing a person.
	 *
	 * The directory is never created by the plugin; the page only says where it goes.
	 *
	 * @return string Path relative to wp-content, with a trailing slash.
	 */
	public static function get_dropin_display_path(): string {

		$uploads = wp_upload_dir( null, false );
		$basedir = ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) ? $uploads['basedir'] : '';

		if ( '' !== $basedir && defined( 'WP_CONTENT_DIR' ) && str_starts_with( $basedir, WP_CONTENT_DIR ) ) {
			$basedir = 'wp-content' . substr( $basedir, strlen( WP_CONTENT_DIR ) );
		}

		$basedir = ( '' === $basedir ) ? 'wp-content/uploads' : $basedir;

		return trailingslashit(
			sprintf( '%s/%s', untrailingslashit( $basedir ), Language_Registry::DROPIN_DIR )
		);

	}    //end get_dropin_display_path()

	/**
	 * Method to load the settings page assets.
	 *
	 * Nothing here depends on jQuery.
	 *
	 * @param string $hook Hook suffix of the admin page being loaded.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {

		if ( static::PAGE_HOOK !== $hook ) {
			return;
		}

		$handle  = sprintf( '%s-admin', static::PLUGIN_ID );
		$version = (string) IG_SYNTAX_HILITER_VERSION;

		wp_enqueue_style( $handle, Helper::get_asset_url( 'build/css/admin.css' ), [], $version );

		wp_enqueue_script( $handle, Helper::get_asset_url( 'build/js/admin.js' ), [], $version, true );

		wp_add_inline_script(
			$handle,
			sprintf(
				'window.igSyntaxHiliterAdmin = %s;',
				// Angle brackets are escaped so that no translated string can close the script tag this sits in.
				wp_json_encode( $this->_get_script_data(), JSON_HEX_TAG | JSON_HEX_AMP )
			),
			'before'
		);

	}    //end enqueue_assets()

	/**
	 * Method to build the data the settings page script needs.
	 *
	 * @return array
	 */
	protected function _get_script_data(): array {

		return [
			'restUrl' => trailingslashit( rest_url( static::REST_NAMESPACE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => [
				'saving'            => __( 'Saving…', 'igsyntax-hiliter' ),
				'saved'             => __( 'Setting saved.', 'igsyntax-hiliter' ),
				'saveFailed'        => __( 'That setting could not be saved, so it has been put back the way it was.', 'igsyntax-hiliter' ),
				'reloadNeeded'      => __( 'This page has been open too long. Reload it and try again.', 'igsyntax-hiliter' ),
				'revertConfirm'     => __(
					"This will convert every iG:Syntax Hiliter block on this site back into a [sourcecode] shortcode, in published, draft, pending, scheduled and private content.\n\nIt rewrites your content and it cannot be undone.\n\nContinue?",
					'igsyntax-hiliter'
				),
				'revertNone'        => __( 'There are no blocks to convert.', 'igsyntax-hiliter' ),
				'revertRunning'     => __( 'Converting… do not close this page.', 'igsyntax-hiliter' ),

				/*
				 * The next five strings are the closing report between them. The first
				 * is always shown; each of the next four is appended only when what it
				 * reports happened, so a clean run reads as one short sentence. Each
				 * count names what it counts and the block count says where those blocks
				 * sit, because a block is reported inside a post which one of the post
				 * counts has already counted: the two units overlap and adding them
				 * together counts the same snippet twice. The two clauses naming code
				 * which vanishes on deactivation say so, and say what to do about it,
				 * because this report is read by somebody on their way out.
				 *
				 * The counts are only known in the browser, so they are written in by
				 * JavaScript and `_n()` cannot be reached. Every string is therefore
				 * worded to read the same whether its count is one or many.
				 */
				/* translators: %d: number of posts converted. */
				'revertDone'        => __( 'Finished. Posts converted: %d.', 'igsyntax-hiliter' ),
				/* translators: %d: number of posts in which nothing was rewritten. */
				'revertDoneLeft'    => __( 'Posts left unchanged: %d — such a post holds no block of this plugin\'s, or holds only blocks that were left alone.', 'igsyntax-hiliter' ),
				/* translators: %d: number of blocks the tool deliberately declined to rewrite. */
				'revertDoneBlocks'  => __( 'Blocks left alone: %d — that is a count of blocks, not posts, and each one sits in a post already counted above. Such a block holds the shortcode\'s own closing tag in its code, so writing it as a shortcode would have cut the code short. It stays a block, and stays invisible once this plugin is deactivated, so deal with it by hand before you deactivate.', 'igsyntax-hiliter' ),
				/* translators: %d: number of posts the tool could not convert. */
				'revertDoneFailed'  => __( 'Posts that could not be converted: %d — each still holds a block, which disappears from the post once this plugin is deactivated.', 'igsyntax-hiliter' ),
				'revertDonePartial' => __( 'Some counts were missing from the answer, so this report is short of something. Check your posts for snippets which are still blocks before you deactivate.', 'igsyntax-hiliter' ),
				'revertFailed'      => __( 'The conversion stopped because a request failed. Nothing already converted has been lost — run it again to carry on.', 'igsyntax-hiliter' ),
			],
		];

	}    //end _get_script_data()

	/**
	 * Method to tell the site owner, once, that their settings were migrated.
	 *
	 * @return void
	 */
	public function maybe_show_migration_message(): void {

		$screen = get_current_screen();

		if ( is_null( $screen ) || static::PAGE_HOOK !== $screen->id ) {
			return;    //not our settings page, bail out
		}

		$old_version = get_option( static::PLUGIN_ID . '-migrated-from', '' );
		$old_version = ( is_scalar( $old_version ) ) ? trim( (string) $old_version ) : '';

		if ( '' === $old_version ) {
			return;
		}

		delete_option( static::PLUGIN_ID . '-migrated-from' );    //shown once, then gone

		if ( ! version_compare( $old_version, (string) IG_SYNTAX_HILITER_VERSION, '<' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: version number the settings came from. */
					__( 'Settings migrated successfully from v%s.', 'igsyntax-hiliter' ),
					$old_version
				)
			)
		);

	}    //end maybe_show_migration_message()

	/**
	 * Method to add a settings link to the plugin's row on the plugins screen.
	 *
	 * @param array  $links Action links for the plugin being listed.
	 * @param string $file  Plugin file the links belong to.
	 *
	 * @return array
	 */
	public function get_action_links( $links, $file ): array {

		$links = ( is_array( $links ) ) ? $links : [];

		if ( IG_SYNTAX_HILITER_BASENAME !== $file ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( admin_url( sprintf( 'options-general.php?page=%s', static::PAGE_SLUG ) ) ),
				esc_attr(
					sprintf(
						/* translators: %s: plugin name. */
						__( 'Configure %s', 'igsyntax-hiliter' ),
						static::PLUGIN_NAME
					)
				),
				esc_html__( 'Settings', 'igsyntax-hiliter' )
			)
		);

		return $links;

	}    //end get_action_links()

}    //end of class


//EOF
