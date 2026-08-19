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
	 * The settings schema, once it has been built in this request.
	 *
	 * @var array|null
	 */
	protected static ?array $_settings_schema = null;

	/**
	 * Class constructor, which is where this class hooks itself up to WordPress.
	 *
	 * **The `parent::__construct()` call is not boilerplate and must not go.**
	 *
	 * PHP resolves a constructor in a fixed order: one declared in the class beats
	 * one a trait brings in, and a trait's beats one inherited from a parent. The
	 * `Singleton` trait above declares an empty constructor — so without this method,
	 * that empty one wins over `Base::__construct()`, `$this->_option` is never set
	 * and `Migrate` never runs, because `Base`'s constructor is the only thing which
	 * triggers a pending migration and this is the only class which reaches it.
	 *
	 * It would fail silently, and it would fail on upgrade.
	 *
	 * It also has to come first, ahead of the hooks: a migration has to have finished
	 * before anything this class registers can be reached.
	 */
	protected function __construct() {

		parent::__construct();

		$this->_register_hooks();

	}    //end __construct()

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

	}    //end _register_hooks()

	/**
	 * Method to decide whether the current user may use the plugin's REST routes.
	 *
	 * Logged out is answered with `401` and under privileged with `403`, so that a
	 * caller can tell "log in" apart from "you may not do this". Neither answer
	 * reaches a callback, so neither can change a stored setting.
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

	}    //end rest_permission_check()

	/**
	 * Method to get the settings the screen shows and the route accepts.
	 *
	 * This is the only list of writable settings there is. A name which is not a key
	 * here is rejected by the route, so the route can never be used to write an
	 * arbitrary option.
	 *
	 * Built once per request. `register_rest_routes()` reads it for the route's `enum`
	 * and so pays for it on every `rest_api_init` the site serves — the editor's
	 * requests included — and a single `POST /option` reached it four times. Each
	 * build is twenty `__()` calls, both choice lists and the font grouping.
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
				'description' => __( 'Typeface used for code boxes on the front end. Anything other than None is fetched from Bunny Fonts, a font service which stores no visitor data, so each reader\'s browser makes one request to fonts.bunny.net. None fetches nothing.', 'igsyntax-hiliter' ),
				'choices'     => static::get_font_choices(),
				'groups'      => static::get_font_groups(),
			],
			'toolbar'           => [
				'type'        => 'toggle',
				'label'       => __( 'Show the toolbar', 'igsyntax-hiliter' ),
				'description' => __( 'Puts a small toolbar above each code box, which carries the language name and the copy button.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'copy_code'         => [
				'type'        => 'toggle',
				'label'       => __( 'Show the copy button', 'igsyntax-hiliter' ),
				'description' => __( 'Adds a button which copies the code to the clipboard. It lives in the toolbar, so it needs the toolbar switched on.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'show_line_numbers' => [
				'type'        => 'toggle',
				'label'       => __( 'Show line numbers', 'igsyntax-hiliter' ),
				'description' => __( 'The default for every code box. A single snippet can override it with the gutter attribute or the block setting.', 'igsyntax-hiliter' ),
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
				'description' => __( 'Gives each level of nesting its own colour, so a bracket and its partner share one. Four of the bundled themes colour these themselves; everywhere else the colours are the ones this plugin ships.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'hilite_comments'   => [
				'type'        => 'toggle',
				'label'       => __( 'Highlight code in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Runs the same highlighting over code posted in comments.', 'igsyntax-hiliter' ),
				'choices'     => $yes_no,
			],
			'gist_in_comments'  => [
				'type'        => 'toggle',
				'label'       => __( 'Allow Gist embeds in comments', 'igsyntax-hiliter' ),
				'description' => __( 'Lets a commenter embed a GitHub Gist with the github shortcode. Off by default, because it lets a commenter load a third party script.', 'igsyntax-hiliter' ),
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

	}    //end get_settings_schema()

	/**
	 * Method to get the themes offered by the theme setting.
	 *
	 * The bundled themes are those whose stylesheet is actually readable on disk, so
	 * a theme which is not shipped is never offered.
	 *
	 * Sorted by name, and "None" put in front of the lot. There are 43 themes to read
	 * through, which is enough that the order has to be one a reader can predict: the
	 * registry hands them over grouped by the directory they came from, and that is a
	 * fact about this plugin's file layout rather than anything a site owner knows.
	 * The comparison is case insensitive so that `a11y Dark` sorts among the A's, and
	 * natural so that a digit in a name is read as a number. "None" is not a theme —
	 * it means the code boxes are styled by the site's own CSS and nothing else — so
	 * it goes at the top rather than at the end of a list it is not part of.
	 *
	 * The order here is a decision of this screen. `Themes::get_themes()` is
	 * the registry, and `Validate` builds the setting's allowlist from it, where the
	 * order means nothing at all.
	 *
	 * @return array Theme setting value to its label.
	 */
	public static function get_theme_choices(): array {

		$themes = Themes::get_themes();

		uasort( $themes, 'strnatcasecmp' );

		return array_merge(
			[ Themes::THEME_NONE => __( 'None — load no theme stylesheet', 'igsyntax-hiliter' ) ],
			$themes
		);

	}    //end get_theme_choices()

	/**
	 * Method to get the stylesheet URL of every theme the dropdown offers.
	 *
	 * Built by walking the choices rather than the registry, so that the list the
	 * preview can paint and the list the screen offers are the same list. "None" is
	 * in it, carrying an empty string: it is a choice like any other and the script
	 * has to be able to look it up and find that there is nothing to load.
	 *
	 * @return array Theme setting value to the URL of its stylesheet.
	 */
	public static function get_theme_urls(): array {

		$urls = [];

		foreach ( array_keys( static::get_theme_choices() ) as $slug ) {

			$file = Themes::get_theme_file( $slug );

			$urls[ $slug ] = ( empty( $file ) ) ? '' : Helper::get_asset_url( $file );

		}

		return $urls;

	}    //end get_theme_urls()

	/**
	 * Method to get the fonts offered by the font setting.
	 *
	 * Sorted by name with "None" in front, for the reasons `get_theme_choices()` gives
	 * and by the same comparison. "None" is the default here, which the themes' "None"
	 * is not: a font is fetched from another host, and a plugin which reached out to one
	 * on a site owner's behalf without being asked would be making that call for them.
	 *
	 * @return array Font setting value to its label.
	 */
	public static function get_font_choices(): array {

		$fonts = Fonts::get_fonts();

		uasort( $fonts, 'strnatcasecmp' );

		return array_merge(
			[ Fonts::FONT_NONE => __( 'None — load no font', 'igsyntax-hiliter' ) ],
			$fonts
		);

	}    //end get_font_choices()

	/**
	 * Method to get how the font dropdown is grouped.
	 *
	 * Fifteen families is more than a reader can hold in one list, and the question
	 * they are actually asking is whether the font draws `=>` as one glyph or two. So
	 * the dropdown is split on that, and `None` sits above both groups because it is
	 * not a font.
	 *
	 * **This rides beside `choices` and does not replace it, which is the whole design
	 * of the change.** `choices` is read as an allowlist in `validate_option_value()`
	 * and again in `save_option()`, both `isset( $choices[ $value ] )`; nesting it by
	 * group would fail both closed and would answer 400 on every font save. Two more
	 * readers would break more quietly still — `render_page()` falls back to the
	 * default when a stored value is not a key of `choices`, so a site running Fira
	 * Code would be shown `None` while the database held Fira Code, and
	 * `get_font_data()` walks `array_keys( get_font_choices() )`, so the preview would
	 * stop repainting. A structure read in five places is not a display structure,
	 * whatever it looks like where it is declared.
	 *
	 * The order inside each group is the order of `choices`, which is sorted by title,
	 * so the two lists agree by construction rather than by being sorted twice.
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
				continue;    //not a font, and it belongs above both groups
			}

			$groups[ Fonts::has_ligatures( $slug ) ? $with : $without ][] = $slug;

		}

		return $groups;

	}    //end get_font_groups()

	/**
	 * Method to get what the preview needs in order to paint each font.
	 *
	 * The stylesheet to fetch and the rule which applies it, for every font the
	 * dropdown offers. Walked from the choices rather than from the registry, for the
	 * reason `get_theme_urls()` is: the list the preview can paint and the list the
	 * screen offers must be the same list. "None" is in it carrying two empty strings,
	 * because it is a choice like any other and the script has to be able to look it up
	 * and find that there is nothing to do.
	 *
	 * @return array Font setting value to its stylesheet URL and its CSS.
	 */
	public static function get_font_data(): array {

		$fonts = [];

		foreach ( array_keys( static::get_font_choices() ) as $slug ) {

			$fonts[ $slug ] = [
				'url' => Fonts::get_font_url( $slug ),
				'css' => Fonts::get_font_css( $slug ),
			];

		}

		return $fonts;

	}    //end get_font_data()

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

	}    //end register_rest_routes()

	/**
	 * Method to read the theme list off the disk again and answer with it.
	 *
	 * The list is cached for a week, because it is a directory listing which can only
	 * change when the plugin's files change. This is the way to change it sooner —
	 * for a theme dropped in by hand, or one lost to a bad upload.
	 *
	 * It answers with the rebuilt list rather than with "done", because the whole
	 * point of the button is the case where what is on disk is not what was cached,
	 * and a message saying the cache was rebuilt would tell a site owner nothing about
	 * whether their theme is now there. The URLs go with it so that the live preview
	 * can paint a theme which has only just appeared.
	 *
	 * `POST` and not `GET`: this writes.
	 *
	 * @return \WP_REST_Response
	 */
	public function refresh_themes(): WP_REST_Response {

		Themes::get_themes( 'yes' );

		//the schema was built for this request before the list changed under it
		static::$_settings_schema = null;

		return new WP_REST_Response(
			[
				'choices' => static::get_theme_choices(),
				'urls'    => static::get_theme_urls(),
			],
			200
		);

	}    //end refresh_themes()

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
	public static function validate_option_value( mixed $value, WP_REST_Request $request ): bool|WP_Error {

		//the name is whatever was sent, which is not necessarily a string: casting an array
		//raises a warning, and a warning printed ahead of the response body is what the
		//caller reads instead of the 400 this returns
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
		 * No message. The settings page words its own, because only the browser
		 * knows which control was changed and so which label the reader needs to see
		 * named. A second sentence here saying the same thing in different words is
		 * a string nothing reads and nobody notices going stale.
		 */
		return new WP_REST_Response(
			[
				'name'  => $name,
				'value' => (string) $this->_option->get( $name ),
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
			static::_CAPABILITY,
			static::_PAGE_SLUG,
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
				'preview'     => static::get_preview_markup( 'yes' === ( $settings['show_line_numbers']['value'] ?? 'yes' ) ),
			],
			true
		);

	}    //end render_page()

	/**
	 * Method to get the code box the settings page previews a theme with.
	 *
	 * Rendered by the plugin's own renderer, from a snippet like any other, so that
	 * what a site owner is shown is produced by the same code the front end runs. A
	 * preview built out of markup written here would be a second answer to "what does
	 * a code box look like", and the two would drift.
	 *
	 * Calling the renderer in wp-admin is inert: it signals the asset manager that a
	 * snippet was rendered, and the asset manager decides during `wp_footer`, which
	 * no admin page fires.
	 *
	 * The snippet itself is source code and is deliberately not translated. It is
	 * chosen to put a comment, a string, a keyword, a number and a function name in
	 * front of the reader, because those are what a theme colours differently — and
	 * `=>`, `&&`, `===` and `->`, because those are what the four fonts carrying code
	 * ligatures draw differently from every other font on the list.
	 *
	 * **The box scrolls in both directions and that is expected.** The snippet is
	 * longer than the column is tall and one line of it is wider than the column is
	 * wide, which is Amit's call: a preview showing a real class is worth more than one
	 * which fits. The themes ask for type sizes half again apart, so no snippet can fit
	 * every one of them anyway.
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
			new Snippet( $code, static::_PREVIEW_LANGUAGE, $show_line_numbers )
		);

	}    //end get_preview_markup()

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
		$version = Helper::get_version();

		/*
		 * The notice stack is a script and a stylesheet of its own, knowing nothing
		 * about this screen — it is handed a string and a tone. This page is its
		 * only caller today; it is separate so that the next thing needing to say
		 * something to a site owner does not grow a second copy of it.
		 *
		 * Both are declared as dependencies rather than merely enqueued first, so
		 * the order holds however else the page is put together.
		 */
		wp_enqueue_style( $notices, Helper::get_asset_url( 'build/css/notices.css' ), [], $version );

		wp_enqueue_style( $handle, Helper::get_asset_url( 'build/css/admin.css' ), [ $notices ], $version );

		wp_enqueue_script( $notices, Helper::get_asset_url( 'build/js/notices.js' ), [], $version, true );

		wp_enqueue_script( $handle, Helper::get_asset_url( 'build/js/admin.js' ), [ $notices ], $version, true );

		/*
		 * The engine, its plugins and the theme stylesheet, for the preview box. The
		 * screen asks for a preview and is told nothing about what one is made of.
		 */
		Asset_Manager::get_instance()->enqueue_for_preview(
			(string) $this->_option->get( 'theme' ),
			(string) $this->_option->get( 'font' )
		);

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
			'restUrl'      => trailingslashit( rest_url( static::REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),

			/*
			 * Where every theme's stylesheet is, and which tag on the page is showing
			 * one. Between them they are the whole of what the preview needs to repaint
			 * without a reload. The list is built from the same choices the dropdown is
			 * drawn from, so a theme can never be offered without a stylesheet to go
			 * with it.
			 */
			'themes'       => static::get_theme_urls(),
			'themeStyleId' => Asset_Manager::get_theme_style_id(),

			/*
			 * The same two things for the fonts, and one more: a font needs a rule as
			 * well as a stylesheet, because fetching a family does not put it on
			 * anything. Both strings are built by the asset manager, so the preview and
			 * the front end cannot end up applying a font two different ways.
			 */
			'fonts'        => static::get_font_data(),
			'fontStyleId'  => Asset_Manager::get_font_style_id(),
			'i18n'         => [

				/*
				 * The first four name the setting they are about. More than one message
				 * can be on screen at once now, and a "Setting saved." sitting above
				 * another "Setting saved." says nothing about which setting either of
				 * them saved. The name is the label this screen already prints, read
				 * off the control by the script rather than sent over a second time, so
				 * that one translated string is what the reader sees in both places.
				 */
				/* translators: %s: name of the setting being saved. */
				'saving'            => __( '%s — saving…', 'igsyntax-hiliter' ),

				/*
				 * A saved setting says what it was saved to, and a toggle and a choice
				 * do not read the same way: every toggle label on this screen is a verb
				 * phrase — "Show the toolbar", "Limit the height of Gist embeds" — so
				 * the em dash form reads naturally for those, while "Theme" wants
				 * "changed to". One template forced onto both would be clumsy for one of
				 * them.
				 *
				 * `saved` is the fallback for a choice whose value has no name to give,
				 * because "Theme changed to ." would be worse than saying less.
				 */
				/* translators: %s: name of the setting that was switched on. */
				'savedOn'           => __( '%s — enabled.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that was switched off. */
				'savedOff'          => __( '%s — disabled.', 'igsyntax-hiliter' ),
				/* translators: 1: name of the setting, 2: value it now holds. */
				'savedChoice'       => __( '%1$s changed to %2$s.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that was saved. */
				'saved'             => __( '%s — saved.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that could not be saved. */
				'saveFailed'        => __( '%s — could not be saved, so it has been put back the way it was.', 'igsyntax-hiliter' ),
				/* translators: %s: name of the setting that could not be saved. */
				'saveTimedOut'      => __( '%s — your site did not answer in time, so it has been put back the way it was on screen. It may have been saved anyway — reload this page to see where it stands.', 'igsyntax-hiliter' ),
				'reloadNeeded'      => __( 'This page has been open too long. Reload it and try again.', 'igsyntax-hiliter' ),

				/*
				 * The theme list is a reading of what is on disk, cached for a week, and
				 * these three are the refresh button. The middle one reports the count
				 * because that is the only thing a site owner can check the answer
				 * against — the list either has the theme they are looking for in it or
				 * it does not, and the number is what says something changed at all.
				 */
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
				'revertDoneBlocks'  => __( 'Blocks left alone: %d — that is a count of blocks, not posts, and each one sits in a post already counted above. The code in such a block could not be read, so it was left exactly as it was rather than written out damaged. It stays a block, and stays invisible once this plugin is deactivated, so deal with it by hand before you deactivate.', 'igsyntax-hiliter' ),
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
	 * The migration itself runs on `init`, on every request, so by the time any admin
	 * page is drawn it has already happened. This notice is deliberately shown on
	 * whichever admin page comes first after that, and not on this plugin's settings
	 * page alone: a site owner who upgrades and never opens the settings page would
	 * otherwise never be told, and would have no way of knowing their settings had
	 * been rewritten.
	 *
	 * @return void
	 */
	public function maybe_show_migration_message(): void {

		$old_version = get_option( static::PLUGIN_ID . '-migrated-from', '' );
		$old_version = ( is_scalar( $old_version ) ) ? trim( (string) $old_version ) : '';

		if ( empty( $old_version ) ) {
			return;
		}

		delete_option( static::PLUGIN_ID . '-migrated-from' );    //shown once, then gone

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

	}    //end maybe_show_migration_message()

	/**
	 * Method to add a settings link to the plugin's row on the plugins screen.
	 *
	 * Both parameters are `mixed` because this is a filter callback: it is handed
	 * whatever the previous callback on `plugin_action_links` returned, and a plugin
	 * returning something other than an array is a thing which happens. The body
	 * casts rather than fataling.
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

	}    //end get_action_links()

}    //end of class


//EOF
