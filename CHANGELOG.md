## **iG:Syntax Hiliter v6.0-beta-1**
---------------------------------

### **Changelog**

##### **v6.0-beta-1**

* Minimum requirements are now PHP 8.4 and WordPress 6.9. Below either of those the plugin refuses to load — no fatal error, no half-loaded plugin, just an admin notice in `wp-admin` naming the versions it needs.
* The GeSHi library has been dropped, along with all 37 of its bundled language files. Syntax highlighting now happens in the browser using [Prism.js](https://prismjs.com/) 1.30.0, which is bundled with the plugin (MIT licensed — see `assets/lib/prism/LICENSE`). Prism's assets load only on pages that actually contain a snippet, and only the language files those snippets need. Nothing is highlighted on the server any more.
* NEW: Gutenberg block editor support added. A block, titled **iG:Syntax Hiliter**, has been added. The code is stored in the block's attributes as plain, unescaped text, so neither the editor nor the content filter chain ever gets its hands on it. Transforms to and from `core/code`, `core/preformatted` and `core/paragraph` are included, as is a paste transform for `<pre><code>` HTML.
* NEW: Opening a post that contains this plugin's legacy shortcodes in the block editor converts those snippets to blocks automatically, and saving the post persists that conversion. Everything else in the post is left byte-identical. This exists because WordPress hands a classic post to the block editor as a single Classic (TinyMCE) block containing the whole post, and TinyMCE mangles code — it reads `<?php echo "<div>x</div>"; ?>` as HTML, turns the `<div>` into a real element and drops the `<?php … ?>` altogether. Posts that are never opened in the block editor are never converted, and editing in the classic editor's Code view converts nothing. A snippet whose *code* quotes block markup is handled too: the snippets are lifted out of the post before the block editor's parser reads it, so a post showing `<!-- wp:… -->` as an example is not taken apart on screen.
* NEW: A **Before you deactivate** section on the settings page, whose first tool converts this plugin's blocks back to shortcodes across the whole site. Neither block is visible if the plugin is deactivated — an unregistered dynamic block renders as nothing, whereas a shortcode at least remains on screen as text — so this is the way back out. A code block becomes a `[sourcecode language="…"]` shortcode, which is semantically identical to the original `[php]`-style tag but is not a byte-for-byte round trip to what was originally typed. A Gist block becomes `[github gist="https://gist.github.com/…"]`, which is the address the block was already embedding, so nothing on the page changes. Every block converts, this plugin's own tags in the code included: those are written with doubled brackets, which is how a snippet says one of them is text — see the entry on escaped tags below.
* NEW: A block for embedding a GitHub Gist, titled **iG:Syntax Hiliter Gist**. It renders through the same pipeline `[github]` has always used, so the id handling, the link-instead-of-a-script in an excerpt and the **Enable GitHub Gist embed in comments?** setting all apply to it unchanged. Existing `[github]` shortcodes are not converted and go on working exactly as they are; pasting one into the editor offers the block. The **Before you deactivate** tool converts the block back the other way, because a Gist block vanishes on deactivation just as a code block does.
* NEW: An option to **limit the height of Gist embeds**, on by default. Each file in an embedded Gist is kept inside a box of its own and given a scrollbar when it is taller than that, so a Gist of a few long files no longer takes over the page. A file shorter than the box is untouched and shows no scrollbar. Switch the option off to get the full height back.
* NEW: 35 more themes for the code boxes, from the [prism-themes](https://github.com/PrismJS/prism-themes) collection (MIT licensed — see `assets/lib/prism-themes/LICENSE`). The **theme** dropdown now offers 43 in all: Prism's own eight, plus One Dark, One Light, Nord, Dracula, VS Code Dark+, Darcula, Gruvbox, Material, Night Owl, Synthwave '84 and the rest. Exactly one stylesheet is loaded on a page that has a code box, whichever theme is chosen, so the longer list costs a visitor nothing. One theme of that collection is deliberately not bundled: Hopscotch fetches a font from Google, which would have every page carrying a code box call another server.
* IMPROVED: Code in posts and comments is now lifted out of the content before any content filter runs and put back after the last one has finished, both when displaying and when saving. `wptexturize`, `wpautop`, autoembed, KSES and any other plugin's `the_content` filter now run over content that contains no code at all. What ends up in the database is byte-for-byte what the author typed, including for users who do not have the `unfiltered_html` capability.
* CHANGED: Only the 37 language tags the plugin actually shipped are recognised, plus the aliases `as`, `html` and `js`, plus `[sourcecode]` and `[github]`. A tag the plugin never shipped is left completely alone — not registered, not rendered, not converted — so it cannot collide with another plugin's shortcode.
* REMOVED: Adding languages by dropping GeSHi language files into a `geshi/` directory is no longer supported, because GeSHi is gone. That covers **both** forms of it — the plugin's own `geshi/` directory (added in v3.0, and still described in the v3.0 entry below as it shipped then) and the `geshi/` directory in a theme (added in v4.1). The plugin no longer ships a `geshi/` directory and no longer looks for one in a theme. Snippets using such a tag are no longer recognised and will show up as ordinary post text with the `[tag]` markers visible. Approx 300 languages ship with the plugin now, so there is almost certainly one for what you were adding.
* CHANGED: The `file` label sits **above** the code box, at the left, and is always visible — whatever the **Show Toolbar?** setting says. In v5 it lived in the toolbar, so turning the toolbar off hid the label as well; `file` is an attribute the author asked for and the toolbar setting is not about it. A snippet given no `file` label produces exactly the markup it always did.
* CHANGED: `highlight` used together with `firstline` now refers to the line numbers as displayed, offset by `firstline`. GeSHi used the physical line numbers of the code instead. Rare combination, but it is a real difference.
* BUGFIX: `lang` actually works now. It has been documented as the shorthand for `language` since v4.1, but in v5 both attributes defaulted to `code`, so the alias was never reached and `lang="php"` was silently ignored.
* CHANGED: The `highlight` attribute is capped at 10,000 lines in total — the cap is on the whole attribute, not on each range. Once that many lines have been collected the rest of the attribute is ignored, so `highlight="1-999999999"` marks the first 10,000 lines, and `highlight="1-8000,20000-30000"` reaches the cap 2,000 lines into its second range and stops there. v5 had no guard at all, so `highlight="1-999999999"` would sit there building an enormous array.
* CHANGED: The `<pre>` element carries the language class as well as the `<code>` element inside it. Every Prism theme selects on `pre[class*="language-"]`, so without it a code box gets no styling until the browser has run Prism, and `language-none` boxes never get styled at all.
* CHANGED: Language names map onto Prism's own ids at render time — `html`, `html4strict`, `html5` and `xml` all become `markup`; `mysql` and `postgresql` become `sql`; `oracle11` becomes `plsql`; `jquery` and `js` become `javascript`; `rails` becomes `ruby`; `pcre` becomes `regex`; `actionscript3` and `as` become `actionscript`; `java5` becomes `java`; `apache` becomes `apacheconf`; `vb` and `vbnet` both become `visual-basic`; `code` and `text` become `none`, meaning a styled but deliberately unhighlighted box. In a shortcode the language you typed is what gets stored and the mapping happens when the page is rendered, so it can improve later without touching your posts. A snippet converted to a block is the exception, and stores the mapped id, because the block's language dropdown lists those ids and only those. A name nothing recognises is still stored exactly as you typed it — it is your word, and a name overwritten could never be recovered — and such a snippet renders as an unhighlighted code box.
* CHANGED: Three of those mappings land on a **different language**, not merely a different name for the same one, so the colouring of existing snippets changes. `asp` is highlighted as **ASP.NET** — GeSHi's `asp` was *classic* ASP, which Prism has no component for. `perl6` is highlighted as **Perl** — Perl 6 is a separate language, called Raku since 2019, and Prism has no component for it. `c_mac` is highlighted as plain **C**. The code itself is not altered in any of these cases.
* CHANGED: The `plaintext`, `toolbar` and `strict_mode` attributes are accepted and ignored. They were GeSHi-era ideas with no Prism equivalent. Leaving them in an old post is harmless — they never reach the markup.
* CHANGED: Settings. `Use plugin CSS for styling?` becomes a **theme** dropdown listing the bundled Prism themes (with `none` for people who style code boxes themselves). `Show Plain Text Option?` becomes **copy code to clipboard**. `GeSHi Strict Mode?`, `Languages where GeSHi strict mode is disabled` and `Link keywords/function names to Manual?` are gone, as all three were GeSHi features. A new **Limit the height of Gist embeds** option ships **switched on**. `Show Toolbar?`, `Show line numbers in code?`, `Hilite code in comments?` and `Enable GitHub Gist embed in comments?` carry over unchanged, and your existing values are migrated on update.
* BUGFIX: Repeating the same snippet twice on one page no longer emits the same DOM id twice. v5 cached the rendered markup by content hash, so a repeated code box carried a duplicate `id`.
* NEW: A snippet can quote this plugin's own tags. Double the brackets of a tag inside the code and it is written as text: `[[php]]` shows as `[php]` and `[[/php]]` shows as `[/php]`, so a post *about* this plugin can put a whole `[sourcecode]…[/sourcecode]` example inside a code box. Until now a shortcode ended at the first closing tag in its code and everything after it was lost, which made that post the one post this plugin could not carry. What is stored is what you typed — the brackets come off on the way to a reader and nowhere else — and one level of nesting falls out of the same rule, so `[[[/php]]]` shows a reader `[[/php]]`. WordPress's own escape for a whole shortcode, `[[php]x[/php]]`, is a different thing and is untouched; see the next entry.
* Escaped tags are text, and v6 treats them as text from end to end. `[[php]x[/php]]` — the way a post shows this plugin's own tags without them being rendered — is stored with both pairs of brackets exactly as they were typed, and stays that way however many times the post is edited and saved. On the way to a reader the outer pair comes off and the page shows `[php]x[/php]`, which is precisely what WordPress itself does with an escaped shortcode in `do_shortcode_tag()` and in `strip_shortcodes()` — and `strip_shortcodes()` is a call this plugin now claims its own tags in, so the two agree on the same site. Nothing is highlighted, no code box is built and no Prism asset is loaded for one. In an excerpt an escaped tag is taken out along with the real snippets, because what sits between the brackets is source code and a summary is no place for source code.
* BUGFIX: `[github gist="https://gist.github.com/…"]` now embeds the Gist it names. In v5 the Gist pipeline ran at priority 10 on `the_content`, which put it behind `wptexturize` — registered first at the same priority — and texturize curled the quotes around the URL before the plugin could read it, so `wp_parse_url()` split the value at the entity and every such embed pointed at `https://gist.github.com/.js`, rendering nothing. `[github id="…"]` worked in v5 only because it has no URL to mangle. The embed now runs at priority 9, ahead of `wptexturize` — which also means it runs ahead of anything else hooked to `the_content` at priority 10. Everything else about Gist embeds, including the option to allow them in comments, is unchanged.
* CHANGED: `strip_shortcodes()` now strips this plugin's tags too, wherever it is called. The plugin hooks `strip_shortcodes_tagnames` and adds `[php]`, `[code]`, `[html]`, `[js]`, `[c]`, `[sourcecode]` and the rest of its tags to the list, so `strip_shortcodes( 'a [php]x[/php] b [gallery] c' )` now returns `a  b  c`. This is what fixed automatic excerpts leaking snippet code as prose — the plugin does not register its tags globally, so core would otherwise not know they were shortcodes — but it applies to every caller of `strip_shortcodes()` on the site, not just the excerpt path. Theme and plugin authors relying on those tags surviving a `strip_shortcodes()` call will notice.
* The classic editor's Visual (TinyMCE) tab remains an unsupported place to write code, as it has been since 2004. Code snippets are supported in the block editor and in the classic editor's Code view.

##### **v5.1**

* Minimum required PHP version bumped to 7.4.0. The plugin simply won't load its code on lower versions.
* Refactored plugin code for PHP 7.4.x for better performing code.
* **This is the last release using the GeSHi library.** GeSHi library has not been updated in several years and it looks unlikely that it will continue. Next release of the plugin will use a different syntax highlighting library. All existing shortcodes will continue to work and so the update and transition would be seamless for the most part, except a feature or two that will phase out and a few changes in plugin configuration options.

##### **v5.0**

* Minimum required PHP version bumped to 5.3.0. The plugin simply won't load its code on lower versions.
* Major re-write of plugin for cleaner, modular & better performing code.
* Assets are enqueued only if needed.
* NEW: You can now disable plugin stylesheet which styles code boxes. People who have their own styling don't need it anyway.
* NEW: 2 new options allow more control on GeSHi behaviour.
* BUGFIX: Language name cache was not re-building automatically. It is now fixed.

##### **v4.3**

* BUGFIX: Some language file names got snipped when building language name cache. It has been fixed.

##### **v4.2**

* BUGFIX: Shorthand tags for all languages supported now - props to Karol Kuczmarski for reporting the bug.
* NEW: Added `C++` language file.

##### **v4.1**

* BUGFIX: Github Gist URL XSS security hole fixed.
* BUGFIX: `__DIR__` doesn't work below PHP 5.3 - props to Karol Kuczmarski for reporting it.
* NEW: Added `lang` as shorthand for `language` attribute.
* NEW: Additional GeSHi language files can be put in `geshi` directory in theme, which will prevent their deletion on plugin update.
* IMPROVED: If a code block is repeated with same attributes then it is parsed only once and output is reused. This improves performance of the plugin.

##### **v4.0**

* NEW: Added ability to embed Github Gist in post and comments (configurable).
* NEW: Added ability to highlight one or multiple lines in a code block to show them as different.
* NEW: Added new code box layout.
* NEW: Added ability to escape plugin tags to prevent their processing.
* NEW: New GeSHi core (v 1.0.8.11)
* IMPROVED: Removed quirks from plain text view & its now much more smoother.
* IMPROVED: Handling of how code is prevented from beautification. The rest of the post/comment text is not affected as `wptexturize` is not removed anymore.
* IMPROVED: Simpler and faster options page in `wp-admin`.

##### **v3.5**

* BUGFIX: BB Tags except the ones of iG:Syntax Hiliter are allowed. The language file's existence is checked before parsing the code. If the language file does not exist then the code is not parsed.
* BUGFIX: `C` code highlighting is now fixed.
* BUGFIX: 'Plain Text' has been improved to strip the extra blank lines and spaces in Opera and FireFox.
* NEW: The latest stable GeSHi core (v1.0.7.6).
* NEW: Code highlighting for comments has been implemented. This feature can be Enabled/Disabled from the admin interface for iG:Syntax Hiliter. The tags are same for highlighting the code.
* NEW: A cross-browser Colour Picker (tested in IE6, FireFox1.5 and Opera8.5) is now available to easily set the line colours displayed in the code box.
* NEW: A new type of view implemented for seeing "Plain Text" code. Besides opening the plain text code in a new window, you can have it displayed in the code box itself with an option to display the highlighted HTML code back again. The "Plain Text" view type can be set in the admin interface.
* The language file for Ruby that I created a while back is now bundled with the plugin and its also a part of the default GeSHi package.

##### **v3.1**

* BUGFIX: Critical bug, which broke the plugin when the square brackets(`[` & `]`) were used in the posts in places other than tags, has been fixed.
* BUGFIX: Another bug, which allowed any attribute in the tags besides the `num` and also allowed any attribute value for it, affecting the processing. Now only the `num` attribute is accepted and if you specify the `num` attribute then its value must be a positive number otherwise your code won't be highlighted. The `num` attribute is optional and you can leave it out without any problems.
* BUGFIX: Fixed the unclosed `<select>` tags in the Plugin GUI code.
* GeSHi BUGFIX: Fixed a bug in GeSHi where the first line colour was not used when using FANCY LINE NUMBERS thus resulting in just one colour being used for the alternate lines.
* There's a problem in WordPress due to which the starting delimiters of ASP, PHP were not displayed correctly, as whitespace was inserted between the `<` and the rest of the delimiter. This has been patched so that its displayed correctly, but its not saved in the database, so the database still contains the delimiters as formatted by WordPress.

##### **v3.0**

* Complete re-write of the plugin resulting in reduction of code from 750+ lines to about 400 Lines.
* UPDATE: New GeSHi Core(v1.0.7) which has some bug-fixes, please see GeSHi Website for its changelog.
* NEW: New languages added are C#, Delphi, Smarty & VB.NET.
* NEW: Drag-n-Drop usage of new languages. The plugin now supports all languages that GeSHi(v1.0.7) supports. You just need to drop the language file in the `geshi` directory & use the filename as the tag for the language. So for example, if file is `pascal.php`, then the filename is `pascal` & the tags will be `[pascal]` & `[/pascal]`.
* NEW: Plain-Text View of the code highlighted in the code-box is now possible. This feature can be enabled/disabled easily in the Configuration Interface in WordPress Administration.
* ASP language file structure updated & more keywords added.
* IMPROVED: Language name which is displayed in the Code-Box can now be turned ON or OFF easily.
* IMPROVED: No more need to set the physical-path to the `geshi` directory if you are doing a default installation.
* IMPROVED: NO NEED TO EDIT THE PLUGIN FILE ANYMORE. You can now configure the plugin settings from a GUI located under the OPTIONS menu in your WordPress Administration (WordPress 1.5 & above only).

##### **v2.01**

* BUGFIX: Fixed a bug by removing a `<br />` tag from the function `pFix()` which lead to closing of an unnecessary `<p>` tag making the code not xHTML valid (as per my desires).

##### **v2.0 Final**

* Implemented the new version of GeSHi core, v1.0.2 which has some bug fixes and which uses Ordered Lists for Line Numbering and supports starting of a Line Number from any given number.
* The ASP language file has been updated to the new Language File structure of GeSHi as well as more keywords added & highlighting is more effective now.
* iG:Syntax Hiliter now also supports ActionScript, C, C++, JavaScript, Perl, Python, Visual Basic and XML.
* The whole plugin has been re-written and all the highlighting code is now in a class. You can just use the class anywhere else too for highlighting the code. But to also use the Code Tags to wrap your code and then highlight them, you will need to use all other functions. You can remove the WordPress Filter calls at the end of the plugin & use the rest of the code as you want somewhere else.
* BUGFIX: The issue of multi-line comments not being highlighted properly in v2.0 Preview has been sorted out.

##### **v2.0 Preview**

* Implemented the new version of GeSHi core, v1.0.1 which has some bug fixes including the extra quote(`"`) bug that broke the xHTML validation of the code.
* I've created a new language file for ASP (Active Server Pages) which has been added to this release and will also be a part of the next GeSHi release.
* Line numbering is now done through Ordered Lists(`<ol>`) and the code is xHTML compliant.
* Auto-Formatting disabled for posts that contain the iG:Syntax Hiliter code tags so that your code is good for copy-paste operations.

##### **v1.1**

* Implemented the line numbering of code.
* The code box is now of fixed dimensions without word-wrap and with scrollbars (if required).

##### **v1.0**

* Highlights code between the special tags, all of them differently.
* Uses GeSHi for syntax highlighting.
* Supports HTML, CSS, PHP, JAVA & SQL codes.
