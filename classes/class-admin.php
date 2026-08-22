<?php
/**
 * The plugin's settings screen and the REST route which saves it.
 *
 * @package iG_Syntax_Hiliter
 *
 * @author Amit Gupta <https://amitgupta.in/>
 */

namespace iG\Syntax_Hiliter;

use iG\Syntax_Hiliter\Traits\Singleton;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Settings screen, and the REST route the screen saves through.
 *
 * Settings save one at a time, through the route, so one schema decides what may be
 * written. The route is registered on every request and not only in wp-admin, because
 * `rest_api_init` runs on requests where `is_admin()` is false. Everything else this
 * class hooks is on an admin only hook.
 */
class Admin extends Base {

	use Singleton;

	/**
	 * Namespace every one of the plugin's REST routes lives under.
	 *
	 * @var string
	 */
	public const string REST_NAMESPACE = 'igsyntax-hiliter/v1';

	/**
	 * Capability required to read or change anything this class exposes.
	 *
	 * @var string
	 */
	protected const string _CAPABILITY = 'manage_options';

	/**
	 * Menu slug of the settings page.
	 *
	 * @var string
	 */
	protected const string _PAGE_SLUG = self::PLUGIN_ID . '-page';

	/**
	 * Hook suffix WordPress gives the settings page.
	 *
	 * @var string
	 */
	public const string PAGE_HOOK = 'settings_page_' . self::_PAGE_SLUG;

	/**
	 * Language the preview snippet is written in.
	 *
	 * @var string
	 */
	protected const string _PREVIEW_LANGUAGE = 'php';

	/**
	 * Lines of the preview snippet drawn as highlighted.
	 *
	 * Written as the expression an author would type; `Snippet` reads it and
	 * `Renderer::compact_line_ranges()` writes it back.
	 *
	 * @var string
	 */
	protected const string _PREVIEW_HIGHLIGHT = '15-19,23';

	/**
	 * The settings schema, once it has been built in this request.
	 *
	 * @var array|null
	 */
	protected static ?array $_settings_schema = null;

	/**
	 * Class constructor, which is where this class hooks itself up to WordPress.
	 *
	 * `parent::__construct()` must stay and must stay first. A trait's constructor
	 * beats an inherited one, so without this method `Singleton`'s empty constructor
	 * wins, `$this->_option` is never set and `Migrate` never runs; and a migration has
	 * to have finished before anything registered here can be reached.
	 */
	protected function __construct() {

		parent::__construct();

		$this->_register_hooks();

	}

	/**
	 * Method to hook this class up to WordPress.
	 *
	 * @return void
	 */
	protected function _register_hooks(): void {

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_migration_message' ] );

		add_filter( 'plugin_action_links', [ $this, 'get_action_links' ], 10, 2 );

	}

	/**
	 * Method to decide whether the current user may use the plugin's REST routes.
	 *
	 * Logged out is `401` and under privileged is `403`, so a caller can tell the two apart.
	 *
	 * @return true|\WP_Error TRUE when the request may proceed, an error otherwise.
	 */
	public static function rest_permission_check(): bool|WP_Error {

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'ig_syntax_hiliter_rest_not_logged_in',
				__( 'You must be logged in to do that.', 'igsyntax-hiliter' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( static::_CAPABILITY ) ) {
			return new WP_Error(
				'ig_syntax_hiliter_rest_forbidden',
				__( 'You are not allowed to change these settings.', 'igsyntax-hiliter' ),
				[ 'status' => 403 ]
			);
		}

		return true;

	}

	/**
	 * Method to get the settings the screen shows and the route accepts.
	 *
	 * The only list of writable settings; a name not keyed here is rejected by the
	 * route. `groups` and `requires` ride beside `choices` because `choices` is read
	 * as a flat allowlist. Built once per request.
	 *
	 * @return array Setting name to its type, label, description and permitted values.
	 */
	public static function get_settings_schema(): array {

		if ( is_array( static::$_settings_schema ) ) {
			return static::$_settings_schema;
		}

		$yes_no = [
			'yes' => __( 'Yes', 'igsyntax-hiliter' ),
			'no'  => __( 'No', 'igsyntax-hiliter' ),
		];

		static::$_settings_schema = [
			'theme'             => [
				'type'        => 'choice',
				'label'       => __( 'Theme', 'igsyntax-hiliter' ),
				'description' => __( 'Colour scheme used for code boxes on the front end.', 'igsyntax-hiliter' ),
				'choices'     => static::get_theme_choices(),
			],
			'font'              => [
				'type'        => 'choice',
				'label'       => __( 'Font', 'igsyntax-hiliter' ),
				'description' => __( 'Typeface used for code boxes on the front end. Fonts are fetched from Bunny Fonts, a font service which does not store any visitor data. Each visitor\'s browser makes one request to "fonts.bunny.net". "None" fetches nothing.', 'igsyntax-hiliter' ),
				'choices'     => static::get_font_choices(),
				'groups'      => static::get_font_groups(),
			],
			'toolbar'           => [
				'type'        => 'toggle',
				'label'       => __( 'Show the toolbar', 'igsyntax-hiliter' ),
				'description' => __( 'Shows a small toolbar at top of each code box, which carries the language name and the copy button.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'copy_code'         => [
				'type'        => 'toggle',
				'label'       => __( 'Show the copy button', 'igsyntax-hiliter' ),
				'description' => __( 'Adds a button which copies the code to the clipboard. It requires the toolbar. Switching it ON switches the toolbar ON with it (if its OFF) and switching the toolbar OFF switches this OFF as well.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
				'requires'    => 'toolbar',
			],
			'show_line_numbers' => [
				'type'        => 'toggle',
				'label'       => __( 'Show line numbers', 'igsyntax-hiliter' ),
				'description' => __( 'Global setting for every code box. A single snippet can override it with the "gutter" attribute or the block setting.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'match_braces'      => [
				'type'        => 'toggle',
				'label'       => __( 'Point out matching brackets', 'igsyntax-hiliter' ),
				'description' => __( 'Outlines the partner of a bracket, a brace or a parenthesis when a reader hovers over it, and keeps the pair outlined when they click it.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'rainbow_braces'    => [
				'type'        => 'toggle',
				'label'       => __( 'Colour brackets by depth', 'igsyntax-hiliter' ),
				'description' => __( 'Gives each level of nesting its own colour, so a bracket and its partner share one. This requires "Point out matching brackets" to be enabled. If this is switched ON then "Point out matching brackets" is switched ON as well (if its OFF). Switching OFF "Point out matching brackets" will switch OFF this setting too.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
				'requires'    => 'match_braces',
			],
			'hilite_comments'   => [
				'type'        => 'toggle',
				'label'       => __( 'Highlight code in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Allow code to be highlighted in comments via use of shortcodes.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'gist_in_comments'  => [
				'type'        => 'toggle',
				'label'       => __( 'Allow Gist embeds in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Allow visitors to embed GitHub Gists in comments with the [github] shortcode.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'gist_limit_height' => [
				'type'        => 'toggle',
				'label'       => __( 'Limit the height of Gist embeds', 'igsyntax-hiliter' ),
				'description' => __( 'Keeps each file in an embedded Gist inside a box of its own and gives it a scrollbar when it is taller than that.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
		];

		return static::$_settings_schema;

	}

	/**
	 * Method to get the themes offered by the theme setting.
	 *
	 * Sorted `strnatcasecmp` with "None" in front — the registry's own order is grouped
	 * by directory, which means nothing to a site owner.
	 *
	 * @return array Theme setting value to its label.
	 */
	public static function get_theme_choices(): array {

		$themes = Themes::get_instance()->get_themes();

		uasort( $themes, 'strnatcasecmp' );

		return array_merge(
			[ Themes::THEME_NONE => __( 'None — load no theme stylesheet', 'igsyntax-hiliter' ) ],
			$themes
		);

	}

	/**
	 * Method to get the stylesheet URL of every theme the dropdown offers.
	 *
	 * Walked from the choices, so the preview paints the same list the screen offers;
	 * "None" carries an empty string.
	 *
	 * @return array Theme setting value to the URL of its stylesheet.
	 */
	public static function get_theme_urls(): array {

		$urls = [];

		foreach ( array_keys( static::get_theme_choices() ) as $slug ) {

			$file = Themes::get_instance()->get_theme_file( $slug );

			$urls[ $slug ] = ( empty( $file ) ) ? '' : Helper::get_asset_url( $file );

		}

		return $urls;

	}

	/**
	 * Method to get the fonts offered by the font setting.
	 *
	 * Sorted like the themes. "None" is the default here because a font is fetched from
	 * another host.
	 *
	 * @return array Font setting value to its label.
	 */
	public static function get_font_choices(): array {

		$fonts = Fonts::get_instance()->get_fonts();

		uasort( $fonts, 'strnatcasecmp' );

		return array_merge(
			[ Fonts::FONT_NONE => __( 'None — load no font', 'igsyntax-hiliter' ) ],
			$fonts
		);

	}

	/**
	 * Method to get how the font dropdown is grouped.
	 *
	 * Split on whether the face draws `=>` as one glyph. This rides beside `choices`
	 * and must not replace it — `choices` is read as a flat allowlist in
	 * `validate_option_value()`, `save_option()`, `render_page()` and `get_font_data()`.
	 *
	 * @return array Group label to a numerically indexed list of font slugs.
	 */
	public static function get_font_groups(): array {

		$groups = [
			__( 'With Ligature', 'igsyntax-hiliter' )    => [],
			__( 'Without Ligature', 'igsyntax-hiliter' ) => [],
		];

		$with    = array_key_first( $groups );
		$without = array_key_last( $groups );

		foreach ( array_keys( static::get_font_choices() ) as $slug ) {

			if ( Fonts::FONT_NONE === $slug ) {
				continue;    // not a font, and it belongs above both groups
			}

			$groups[ Fonts::get_instance()->has_ligatures( $slug ) ? $with : $without ][] = $slug;

		}

		return $groups;

	}

	/**
	 * Method to get what the preview needs in order to paint each font.
	 *
	 * Walked from the choices, so the preview paints the same list the screen offers;
	 * "None" carries two empty strings.
	 *
	 * @return array Font setting value to its stylesheet URL and its CSS.
	 */
	public static function get_font_data(): array {

		$fonts = [];

		foreach ( array_keys( static::get_font_choices() ) as $slug ) {

			$fonts[ $slug ] = [
				'url' => Fonts::get_instance()->get_font_url( $slug ),
				'css' => Fonts::get_instance()->get_font_css( $slug ),
			];

		}

		return $fonts;

	}

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

		register_rest_route(
			static::REST_NAMESPACE,
			'/themes',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'refresh_themes' ],
				'permission_callback' => [ static::class, 'rest_permission_check' ],
			]
		);

	}

	/**
	 * Method to read the theme list off the disk again and answer with it.
	 *
	 * The list is cached for a week; this is the way to change it sooner. It answers
	 * with the rebuilt list and its URLs so the screen can repaint without a reload.
	 * `POST` and not `GET`: this writes.
	 *
	 * @return \WP_REST_Response
	 */
	public function refresh_themes(): WP_REST_Response {

		Themes::get_instance()->get_themes( 'yes' );

		// the schema was built for this request before the list changed under it
		static::$_settings_schema = null;

		return new WP_REST_Response(
			[
				'choices' => static::get_theme_choices(),
				'urls'    => static::get_theme_urls(),
			],
			200
		);

	}

	/**
	 * Method to check that a setting name is one this plugin owns.
	 *
	 * @param mixed $value Name as it was sent.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_option_name( mixed $value ): bool|WP_Error {

		if ( is_string( $value ) && array_key_exists( sanitize_key( $value ), static::get_settings_schema() ) ) {
			return true;
		}

		return new WP_Error(
			'ig_syntax_hiliter_unknown_setting',
			__( 'That is not one of this plugin\'s settings.', 'igsyntax-hiliter' ),
			[ 'status' => 400 ]
		);

	}

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
	public static function validate_option_value( mixed $value, WP_REST_Request $request ): bool|WP_Error {

		// The name may not be a string; casting an array raises a warning that is printed
		// ahead of the 400.
		$name   = ( is_scalar( $request['name'] ) ) ? sanitize_key( (string) $request['name'] ) : '';
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

	}

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
	public function save_option( WP_REST_Request $request ): WP_REST_Response|WP_Error {

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

		/*
		 * No message — the screen words its own, because only the browser knows which
		 * control changed. `also` names whatever moved with this setting.
		 */
		return new WP_REST_Response(
			[
				'name'  => $name,
				'value' => (string) $this->_option->get( $name ),
				'also'  => $this->_save_dependent_settings( $name, $value ),
			]
		);

	}

	/**
	 * Method to move the settings which depend on the one just saved.
	 *
	 * `requires` names a setting this one does not work without. Switching on switches
	 * on what it needs; switching off switches off whatever needed it. The other two
	 * moves are deliberately not made. One level deep, no chain. A partner which cannot
	 * be saved is left out of the answer rather than turned into a failure.
	 *
	 * @param string $name  Setting which was just saved.
	 * @param string $value Value it was saved with.
	 *
	 * @return array Setting name to its stored value, for every setting which moved. Empty
	 *               when none did.
	 */
	protected function _save_dependent_settings( string $name, string $value ): array {

		$schema = static::get_settings_schema();
		$moved  = [];

		if ( 'yes' === $value && ! empty( $schema[ $name ]['requires'] ) ) {

			$required = (string) $schema[ $name ]['requires'];

			if ( 'yes' !== (string) $this->_option->get( $required ) && $this->_option->save( $required, 'yes' ) ) {
				$moved[ $required ] = (string) $this->_option->get( $required );
			}
		}

		if ( 'no' !== $value ) {
			return $moved;
		}

		foreach ( $schema as $dependent => $setting ) {

			if ( ( $setting['requires'] ?? '' ) !== $name ) {
				continue;
			}

			if ( 'yes' !== (string) $this->_option->get( $dependent ) ) {
				continue;    // already off, so there is nothing for the screen to put right
			}

			if ( $this->_option->save( $dependent, 'no' ) ) {
				$moved[ $dependent ] = (string) $this->_option->get( $dependent );
			}
		}

		return $moved;

	}

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
			static::_CAPABILITY,
			static::_PAGE_SLUG,
			[ $this, 'render_page' ]
		);

	}

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
			 * An unoffered stored value falls back to the setting's own default, not its first
			 * choice — every yes/no consumer compares against `yes`, so the first choice would
			 * draw an on control for an off setting.
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
				'preview'     => static::get_preview_markup( 'yes' === ( $settings['show_line_numbers']['value'] ?? 'yes' ) ),
			],
			true
		);

	}

	/**
	 * Method to get the code box the settings page previews a theme with.
	 *
	 * Rendered by the plugin's own renderer from a real snippet, so the preview is
	 * produced by the code the front end runs. Inert in wp-admin: it only signals the
	 * asset manager, which decides during `wp_footer`. The snippet is source code and is
	 * deliberately not translated. Line highlighting has no setting on this page, so the
	 * preview is the only way to see it.
	 *
	 * @param bool $show_line_numbers Whether the box is drawn with line numbers.
	 *
	 * @return string Markup for the code box.
	 */
	public static function get_preview_markup( bool $show_line_numbers = true ): string {

		$code = <<<'PREVIEW'
<?php
/**
 * Say hello, politely.
 */

namespace My_Plugin\Inc;

use Some_Plugin\Some_Feature;
use Some_Plugin\Some_Collection;

class Foo extends Some_Feature {

    use Some_Collection;

    public function __construct() {

        $this->_check_availability();

    }

    private function _check_availability(): void {

        if ( ! empty( $GLOBALS['some_val'] ) && 2 === $GLOBALS['some_val'] ) {
            // do something
            return;
        }

        // do something else
        return;

    }

}

add_action( 'init', fn() => new Foo );
PREVIEW;

		return Renderer::get_instance()->render_snippet(
			new Snippet(
				$code,
				[
					'language'  => static::_PREVIEW_LANGUAGE,
					'highlight' => static::_PREVIEW_HIGHLIGHT,
				],
				$show_line_numbers
			)
		);

	}

	/**
	 * Method to load the settings page assets.
	 *
	 * Nothing here depends on jQuery.
	 *
	 * @param string $hook Hook suffix of the admin page being loaded.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {

		if ( static::PAGE_HOOK !== $hook ) {
			return;
		}

		$handle  = sprintf( '%s-admin', static::PLUGIN_ID );
		$notices = sprintf( '%s-notices', static::PLUGIN_ID );
		$api     = sprintf( '%s-admin-api', static::PLUGIN_ID );
		$revert  = sprintf( '%s-revert', static::PLUGIN_ID );
		$version = Helper::get_version();

		// The api script is the shared transport and page lock, and the other two depend on it;
		// the notice stack is a dependency of the settings script only, so the order holds.
		wp_enqueue_style( $notices, Helper::get_asset_url( 'build/css/notices.css' ), [], $version );

		wp_enqueue_style( $handle, Helper::get_asset_url( 'build/css/admin.css' ), [ $notices ], $version );

		wp_enqueue_script( $api, Helper::get_asset_url( 'build/js/admin-api.js' ), [], $version, true );

		wp_enqueue_script( $notices, Helper::get_asset_url( 'build/js/notices.js' ), [], $version, true );

		wp_enqueue_script( $handle, Helper::get_asset_url( 'build/js/admin.js' ), [ $notices, $api ], $version, true );

		wp_enqueue_script( $revert, Helper::get_asset_url( 'build/js/revert.js' ), [ $api ], $version, true );

		// The engine, its plugins and the theme stylesheet, for the preview box.
		Asset_Manager::get_instance()->enqueue_for_preview(
			(string) $this->_option->get( 'theme' ),
			(string) $this->_option->get( 'font' )
		);

		// On the api handle: it prints first and both consumers depend on it.
		wp_add_inline_script(
			$api,
			sprintf(
				'window.igSyntaxHiliterAdmin = %s;',
				// Angle brackets are escaped so that no translated string can close the script
				// tag this sits in.
				wp_json_encode( $this->_get_script_data(), JSON_HEX_TAG | JSON_HEX_AMP )
			),
			'before'
		);

	}

	/**
	 * Method to build the data the settings page script needs.
	 *
	 * @return array
	 */
	protected function _get_script_data(): array {

		return [
			'restUrl'      => trailingslashit( rest_url( static::REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),

			// The theme stylesheets and the tag showing one: what the preview needs to repaint.
			'themes'       => static::get_theme_urls(),
			'themeStyleId' => Asset_Manager::get_theme_style_id(),

			// Fonts need a rule as well as a stylesheet.
			'fonts'        => static::get_font_data(),
			'fontStyleId'  => Asset_Manager::get_font_style_id(),
			'i18n'         => [

				// Messages name their setting, read off the control by the script.
				/* translators: %s: name of the setting being saved. */
				'saving'            => __( '%s — saving…', 'igsyntax-hiliter' ),

				/* translators: %s: name of the setting that was switched on. */
				'savedOn'           => __( '%s — enabled.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that was switched off. */
				'savedOff'          => __( '%s — disabled.', 'igsyntax-hiliter' ),
				/* translators: 1: name of the setting, 2: value it now holds. */
				'savedChoice'       => __( '%1$s changed to %2$s.', 'igsyntax-hiliter' ),

				/* translators: %s: setting switched on alongside the one the reader changed. */
				'savedAlsoOn'       => __( '%s was switched on with it.', 'igsyntax-hiliter' ),
				/* translators: %s: setting switched off alongside the one the reader changed. */
				'savedAlsoOff'      => __( '%s was switched off with it.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that was saved. */
				'saved'             => __( '%s — saved.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that could not be saved. */
				'saveFailed'        => __( '%s — could not be saved, so it has been put back the way it was.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that could not be saved. */
				'saveTimedOut'      => __( '%s — your site did not answer in time, so it has been put back the way it was on screen. It may have been saved anyway — reload this page to see where it stands.', 'igsyntax-hiliter' ),
				'reloadNeeded'      => __( 'This page has been open too long. Reload it and try again.', 'igsyntax-hiliter' ),

				'themesRefreshing'  => __( 'Rereading the themes on disk…', 'igsyntax-hiliter' ),
				/* translators: %d: number of themes now offered. */
				'themesRefreshed'   => __( 'Themes reread. %d are available.', 'igsyntax-hiliter' ),
				'themesRefreshFail' => __( 'The themes could not be reread, so the list is unchanged.', 'igsyntax-hiliter' ),
				'revertConfirm'     => __(
					"This will convert every iG:Syntax Hiliter block on this site back into a shortcode — a code block into [sourcecode] and a Gist block into [github] — in published, draft, pending, scheduled and private content.\n\nIt rewrites your content and it cannot be undone.\n\nContinue?",
					'igsyntax-hiliter'
				),
				'revertNone'        => __( 'There are no blocks to convert.', 'igsyntax-hiliter' ),
				'revertRunning'     => __( 'Converting… do not close this page.', 'igsyntax-hiliter' ),

				// Counts are written in by JavaScript so `_n()` cannot be reached — every string
				// reads the same for one or many.
				/* translators: %d: number of posts converted. */
				'revertDone'        => __( 'Finished. Posts converted: %d.', 'igsyntax-hiliter' ),
				/* translators: %d: number of posts in which nothing was rewritten. */
				'revertDoneLeft'    => __( 'Posts left unchanged: %d — such a post holds no block of this plugin\'s, or holds only blocks that were left alone.', 'igsyntax-hiliter' ),
				/* translators: %d: number of blocks the tool deliberately declined to rewrite. */
				'revertDoneBlocks'  => __( 'Blocks left alone: %d — that is a count of blocks, not posts, and each one sits in a post already counted above. The code in such a block could not be read, so it was left exactly as it was rather than written out damaged. It stays a block, and stays invisible once this plugin is deactivated, so deal with it by hand before you deactivate.', 'igsyntax-hiliter' ),
				/* translators: %d: number of posts the tool could not convert. */
				'revertDoneFailed'  => __( 'Posts that could not be converted: %d — each still holds a block, which disappears from the post once this plugin is deactivated.', 'igsyntax-hiliter' ),
				'revertDonePartial' => __( 'Some counts were missing from the answer, so this report is short of something. Check your posts for snippets which are still blocks before you deactivate.', 'igsyntax-hiliter' ),
				'revertFailed'      => __( 'The conversion stopped because a request failed. Nothing already converted has been lost — run it again to carry on.', 'igsyntax-hiliter' ),
			],
		];

	}

	/**
	 * Method to tell the site owner, once, that their settings were migrated.
	 *
	 * Shown on whichever admin page comes first after the migration, not only this
	 * plugin's — a site owner who never opens the settings page would otherwise never be told.
	 *
	 * @return void
	 */
	public function maybe_show_migration_message(): void {

		$old_version = get_option( static::PLUGIN_ID . '-migrated-from', '' );
		$old_version = ( is_scalar( $old_version ) ) ? trim( (string) $old_version ) : '';

		if ( empty( $old_version ) ) {
			return;
		}

		delete_option( static::PLUGIN_ID . '-migrated-from' );    // shown once, then gone

		if ( ! version_compare( $old_version, Helper::get_version(), '<' ) ) {
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

	}

	/**
	 * Method to add a settings link to the plugin's row on the plugins screen.
	 *
	 * Both params are `mixed` because a previous `plugin_action_links` callback may return
	 * a non-array; the body casts rather than fataling.
	 *
	 * @param mixed $links Action links for the plugin being listed.
	 * @param mixed $file  Plugin file the links belong to.
	 *
	 * @return array
	 */
	public function get_action_links( mixed $links, mixed $file ): array {

		$links = ( is_array( $links ) ) ? $links : [];

		if ( IG_SYNTAX_HILITER_BASENAME !== $file ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( admin_url( sprintf( 'options-general.php?page=%s', static::_PAGE_SLUG ) ) ),
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

	}

} // end of class

// EOF
