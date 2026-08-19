=== iG:Syntax Hiliter ===
Contributors: amit
Tags: syntax highlighter, code highlighter, code, source code, php, mysql, html, css, javascript
Requires at least: 6.9
Tested up to: 7.0.4
Requires PHP: 8.4
Stable tag: 6.0-beta-1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

== Description ==

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [Prism.js library](https://prismjs.com/) to colourise your code and supports ~300 programming languages, all of which are bundled with the plugin.

You can write code snippets using the Gutenberg block editor or using this plugin's shortcodes in the classic editor's Code view. Both are supported. The classic editor's Visual (WYSIWYG) tab is not supported and never has been — it mangles code before the plugin ever sees it.

**NOTE :** Highlighting happens in the visitor's browser, not on your server. Prism's files are loaded only on pages that actually contain a code snippet and it loads only the languages that page needs.

= Minimum Requirements =

* WordPress 6.9 or above
* PHP 8.4 or above


Pull requests are welcome on Github.

Github: [https://github.com/coolamit/ig-syntax-hiliter/](https://github.com/coolamit/ig-syntax-hiliter/)

== Important changes in 6.0-beta-1 ==

Version 6.0-beta-1 replaces the GeSHi library, which no longer seems to be maintained, with Prism.js. Most of that you will not notice. A few things do change in ways that can affect posts you already have, so please read this before updating.

= Posts you never open in the block editor are not touched =

Every shortcode this plugin has ever shipped keeps rendering straight out of `post_content`. A post written in 2004 that nobody ever edits again will keep rendering correctly, forever. Editing a post in the classic editor's Code view triggers no conversion either — shortcodes stay shortcodes. Code in comments keeps working the same way it always has.

= Opening a post that contains legacy shortcodes in the block editor converts its snippets to blocks =

This happens automatically, on load, without asking and it is written to the post the next time you save. Open the post and close it again without saving and nothing has changed.

The reason is that WordPress hands a classic post to the block editor as a single Classic (TinyMCE) block containing the whole post, code and all — and TinyMCE mangles code. It reads `<?php echo "<div>x</div>"; ?>` as HTML: the `<div>` becomes a real element and the `<?php … ?>` is dropped entirely. Because the whole post is one block, editing an unrelated paragraph is enough to re-save every snippet in the post in that damaged form. The conversion moves your code into block attributes, where TinyMCE cannot reach it, before that can happen. It is surgical — only the snippets are replaced and every other byte of the post is left exactly as it was.

= Snippets that have been converted to blocks are not visible if the plugin is deactivated =

This is the trade-off for the block editor support and on this one point blocks are worse than shortcodes. A shortcode left behind by a deactivated plugin at least stays on screen as `[php]…[/php]` text, which you can see and act on. A block whose plugin is gone is not registered at all, renders as nothing and the snippet silently disappears from the post.

The Gist block goes the same way for the same reason, and so does the Gist it names.

To fix this issue, the settings page of the plugin has a **Before you deactivate** section containing a tool that converts this plugin's blocks back into shortcodes across the whole site — published, draft, pending, scheduled and private posts of public post types. Run it before you deactivate or delete the plugin. A code block becomes a `[sourcecode language="…"]` shortcode, so a snippet that started life as `[php]…[/php]` comes back as `[sourcecode language="php"]…[/sourcecode]`. That is the same thing semantically, but it is not a byte-for-byte round trip to what you originally typed. A Gist block becomes `[github gist="https://gist.github.com/…"]`, which is the address the block was already embedding.

A snippet whose code quotes this plugin's own tags converts like any other, and it is worth knowing how. A shortcode ends at the first closing tag in its code, so the tool doubles the brackets of every one of this plugin's tags it finds there — `[[sourcecode language="php"]]` and `[[/sourcecode]]` — which is how a snippet says that a tag is text rather than a tag. A reader sees the tags you typed. With the plugin deactivated you see the doubled brackets in the code sample instead, which is the price of the snippet being on the page at all.

= Only the tags the plugin actually shipped are recognised =

Those are:

`actionscript actionscript3 apache applescript asp bash c c_mac code cpp csharp css diff groovy html4strict html5 ini java java5 javascript jquery mysql oracle11 pcre perl perl6 php postgresql python rails ruby sql text vb vbnet xml yaml`

plus the aliases `as`, `html` and `js`, plus `[sourcecode]` and `[github]`.

A tag this plugin never shipped is left completely alone — not registered, not rendered, not converted. If your post contains `[email]`, it stays `[email]` and goes to whichever plugin owns it. That is deliberate: claiming a wider set of tags would mean taking shortcodes away from other plugins, which is a worse problem than the one it would solve.

This also means that if you added custom language files for GeSHi and were using them with drop-in tags (eg., you added `email` language file and were using `[email]` tag), those are no longer recognized by the plugin and are left behind. You can go back and update those posts if you want and switch those tags to `[sourcecode]` variant or into this plugin's block for the block editor - and they will then be picked by the plugin and have the syntax highlighted on frontend. This is a breaking change in v6.0 as it is almost impossible to determine if a BB tag (for a language not originally shipped with this plugin) is for use with this plugin via drop-in language support or use with some other plugin. But this affects only `[<LANGUAGE_NAME>]` type of tags. So `[email]` will be left behind but `[sourcecode language="email"]` would still be supported and get converted to this plugin's block if you use the block editor and open that post in it for editing.

= Some old tags now highlight as a different language =

Prism does not have a component for everything GeSHi had, so a handful of tags are mapped onto the nearest thing Prism does have. In a shortcode the mapping happens when the page is rendered — what is stored in your post is the tag you typed — so it can be improved later without touching your posts. A snippet converted to a block is the exception and stores the mapped name, because that is the name the block's language dropdown offers. A name Prism does not know is stored exactly as you typed it either way — it is your word and a name overwritten could never be recovered — and such a snippet renders as an unhighlighted code box.

Most of it is uncontroversial: `html`, `html4strict`, `html5` and `xml` all become `markup`; `mysql` and `postgresql` become `sql`; `oracle11` becomes `plsql`; `jquery` becomes `javascript`; `rails` becomes `ruby`; `pcre` becomes `regex`; `actionscript3` and `as` become `actionscript`; `java5` becomes `java`; `js` becomes `javascript`; `apache` becomes `apacheconf`; `vb` and `vbnet` both become `visual-basic`; and `code` and `text` become `none`, which is a styled but deliberately unhighlighted box.

Three of them change the language, not just its name, so your code will be coloured by different rules than it was in v5:

* **`asp` is highlighted as ASP.NET.** GeSHi's `asp` was *classic* ASP, which Prism has no component for.
* **`perl6` is highlighted as Perl.** Perl 6 is a different language — it has been called Raku since 2019 — and Prism has no component for it.
* **`c_mac` is highlighted as plain C.**

Your code itself is untouched in every case; only the colouring differs.

= Languages added by dropping in GeSHi files are no longer supported =

Since v3.0 you could add a language by putting a GeSHi language file in the plugin's own `geshi/` directory and since v4.1 in a `geshi/` directory in your theme instead, in both cases using its filename as a tag. GeSHi is gone in 6.0-beta-1 and both mechanisms are gone with it. The plugin no longer ships a `geshi/` directory and no longer looks for one anywhere, in the plugin or in a theme. There is no automatic replacement. A snippet using such a tag is no longer recognised, so it will appear as ordinary post text with the `[tag]` markers visible, formatted by WordPress like any other text.

Approx 300 languages ship with the plugin, so there is a good chance one of them is the language you were adding — use it with `[sourcecode language="…"]`.

The changelog entries below for v3.0 and v4.1 still describe those drop-in directories and are left as they are — they are the record of what those versions shipped, not of what 6.0-beta-1 does. Neither mechanism works in 6.0-beta-1.

= Highlighting is done by the browser now =

Prism colours the code client side. Its files load only on pages that contain a snippet and only the languages that page uses. Visitors with JavaScript turned off get a plain but properly styled code box with the code intact. A language the plugin cannot resolve produces an unhighlighted but styled box rather than an error — nothing breaks and nothing 404s.

= strip_shortcodes() now strips this plugin's tags too =

Worth knowing if you write themes or plugins. This plugin hooks `strip_shortcodes_tagnames`, so *any* call to `strip_shortcodes()` anywhere on the site — yours, your theme's, another plugin's — now removes `[php]`, `[code]`, `[html]`, `[js]`, `[c]`, `[sourcecode]` and the rest of this plugin's tags along with the shortcodes WordPress knows about.

That is deliberate. The plugin does not register its tags globally any more, so core has no idea they are shortcodes and an automatic excerpt would otherwise print a whole snippet as prose. Naming the tags to core fixes that everywhere at once — but it is wider than the excerpt path. If you were relying on `strip_shortcodes()` leaving `[php]…[/php]` standing in some other bit of content, it no longer will.

= The classic editor's Visual tab is still not a place to write code =

It never was. Snippets are supported in the block editor and in the classic editor's Code view. Switching a post to Visual mode can corrupt code inside shortcodes. That is documented behaviour since beginning when TinyMCE WYSIWYG editor was added; it is not a bug.

== Installation ==

###UPGRADING from v4.0 or later###

Just click `update now` link below the plugin listing on the plugins page in your `wp-admin`. That's quite easy!!

Do read the **Important changes in 6.0-beta-1** section above first. Your existing posts keep working, but there are a few things worth knowing before you open an old post in the block editor.

###UPGRADING from v3.x###

Just deactivate plugin in WordPress admin, delete the `syntax_hilite.php` file & `ig_syntax_hilite` directory from plugins folder and follow the installation process below. That's quite easy!!

###UPGRADING from v2.1 or lower###

Just deactivate plugin in WordPress admin, delete the `syntax_hilite.php` and `geshi.php` files & `geshi` directory from plugins folder and follow the installation process below. That's quite easy!!

###Installing The Plugin###

1. Login to your WordPress `wp-admin` area.
2. Click `Add New` in the `Plugins` menu on left.
3. Enter `iG:Syntax Hiliter` in the search bar on the right on the page that opens and press Enter key.
4. WordPress would show the **iG:Syntax Hiliter** plugin with install button, click that to install the plugin.
5. Click on `Activate Plugin` link on the page that opens after the plugin has been installed successfully.

**See Also:** ["Installing Plugins" in the WP documentation](https://wordpress.org/documentation/article/manage-plugins/#finding-and-installing-plugins-1)

== Other Notes ==

[Documentation on plugin usage and configuration](https://github.com/coolamit/ig-syntax-hiliter/blob/master/README.md)

== Frequently Asked Questions ==

= My code looks all odd, characters appear as HTML entities. Why is your plugin screwing up my code? =

If you are writing your post in the classic editor's Visual (WYSIWYG) tab then that is what is messing things up for you. That tab is TinyMCE, it treats your code as HTML and it does its damage before this plugin ever sees the content. It has never been supported. Use the block editor or the classic editor's Code view. If you are using either of those and still see this, please report it.

= I opened an old post in the block editor and my code blocks turned into iG:Syntax Hiliter blocks. Why? =

Because leaving them alone was worse. See the **Important changes in 6.0-beta-1** section above for the full explanation. Short version: the block editor loads a classic post into one big TinyMCE block, TinyMCE eats code and converting the snippets to blocks first is the only way to stop that. The conversion is only written to the post if you save it and it leaves the rest of your post byte-for-byte as it was.

= What happens to my code if I deactivate the plugin? =

Snippets still stored as shortcodes stay visible as `[sourcecode]…[/sourcecode]` text — ugly, but you can see them and do something about them. Snippets that have been converted to blocks render as nothing at all, because the block is no longer registered, and a Gist block goes the same way. Before deactivating, use the tool in the **Before you deactivate** section of the plugin's settings page to turn those blocks back into shortcodes — `[sourcecode]` for a code block and `[github]` for a Gist block. Every block converts, this plugin's own tags in the code included.

= I used to add languages by putting GeSHi language files in the plugin's or my theme's geshi directory. What now? =

Both of those mechanisms are gone with GeSHi. Approx 300 languages ship with the plugin now, so start by checking whether yours is one of them — use it with `[sourcecode language="…"]` or pick it in the block.

= How do I show one of this plugin's own tags inside a code box? =

Double its brackets. `[[php]]` shows a reader `[php]` and `[[/php]]` shows `[/php]`, so a whole example fits inside a snippet:

`[sourcecode language="php"]`
`[[sourcecode language="php"]]`
`echo 'hello';`
`[[/sourcecode]]`
`[/sourcecode]`

Without this the snippet would end at the first closing tag in its code and everything after it would be lost. What is stored is what you typed — the extra brackets come off on the way to a reader and nowhere else — and the rule applies to itself, so `[[[/php]]]` shows a reader `[[/php]]`.

= I see some code that I can improve. Do you accept pull requests? =

By all means, feel free to submit a pull request.

= I want XYZ feature. Can you implement it? =

Please feel free to suggest a new feature. Its inclusion might be speedier if you can provide the code to make it work.

== Screenshots ==

1. Settings page of the plugin where default options can be set for plugin
2. Example display of syntax highlighted PHP code

== ChangeLog ==

= v6.0-beta-1 =

* Minimum requirements are now PHP 8.4 and WordPress 6.9. Below either of those the plugin refuses to load — no fatal error, no half-loaded plugin, just an admin notice naming the versions it needs.
* The GeSHi library has been dropped, along with its 37 bundled language files. Highlighting now happens in the browser with [Prism.js](https://prismjs.com/) 1.30.0, which is bundled with the plugin (MIT licensed). Prism's files load only on pages that contain a snippet and only the languages those snippets need.
* NEW: Gutenberg block editor support has been added. A block for the block editor, titled **iG:Syntax Hiliter**, is now available. Code is stored in the block's attributes as plain text, out of reach of the editor and of every content filter.
* NEW: Opening a post containing this plugin's legacy shortcodes in the block editor converts those snippets to blocks automatically; saving the post persists that. The rest of the post is left byte-identical. This is here because the block editor loads a classic post as one Classic (TinyMCE) block, and TinyMCE destroys code — it reads `<?php echo "<div>x</div>"; ?>` as HTML. Posts never opened in the block editor are never converted, and editing in the classic editor's Code view converts nothing. Snippets are lifted out of the post before the block editor's parser reads it, so a post quoting block markup as an example is no longer taken apart on screen.
* NEW: A **Before you deactivate** section on the settings page with a tool that converts this plugin's blocks back to shortcodes sitewide — a code block to `[sourcecode language="…"]` and a Gist block to `[github gist="…"]`. Either kind of block is invisible if the plugin is deactivated, so this is the way back out. It always writes the `[sourcecode]` form for code — semantically identical to the original `[php]`-style tag, but not a byte-for-byte round trip — and for a Gist it writes the address the block was already embedding. Every block converts: where the code holds one of this plugin's own tags, its brackets are doubled, which is how a snippet says a tag is text.
* NEW: A block for embedding a GitHub Gist, titled **iG:Syntax Hiliter Gist**. It renders through the same pipeline `[github]` has always used, so the id handling, the link instead of a script in an excerpt and the Gist-in-comments setting all apply to it unchanged. Existing `[github]` shortcodes are not converted and go on working. The **Before you deactivate** tool converts the block back the other way, because a Gist block vanishes on deactivation just as a code block does.
* NEW: An option to limit the height of Gist embeds, on by default. Each file in an embedded Gist is kept inside a box of its own and given a scrollbar when it is taller than that. A file shorter than the box is untouched.
* NEW: 35 more themes for the code boxes, from the [prism-themes](https://github.com/PrismJS/prism-themes) collection, which is also MIT licensed. The theme dropdown now offers 43 in all: Prism's own eight, plus One Dark, Nord, Dracula, VS Code Dark+, Gruvbox, Material, Night Owl and the rest, listed by name with **None** at the top. One stylesheet is loaded per page whichever theme is chosen, so the longer list costs a visitor nothing. Hopscotch is the one theme of that collection which is not bundled — it fetches a font from Google, and no page of yours should have to call another server to show a code box.
* NEW: Two options for brackets. **Point out matching brackets** outlines a bracket and its partner when you hover over one, and keeps the pair outlined when you click it — **on by default**, since nothing shows until someone hovers. **Colour brackets by depth** gives each level of nesting its own colour — **off by default**, since it repaints every code box on your site as soon as you switch it on. Four of the bundled themes colour these themselves and will use their own colours; everywhere else they are the plugin's. Nothing is loaded on a page unless one of the two is on, and the preview shows both before you save.
* NEW: A live preview on the settings page. A sample code box sits beside the settings and repaints as you change them, so picking one of 43 themes no longer means saving it, opening your site and coming back. The theme, the font, the toolbar, the copy button, the line numbers and both bracket options all show in it. Nothing in the preview is saved.
* NEW: A **Font** setting, under the theme. Ten monospaced fonts are offered: Azeret Mono, Fira Code, Fira Mono, Google Sans Code, JetBrains Mono, M PLUS Code Latin, Nova Mono, Roboto Mono, Source Code Pro and Ubuntu Mono. Three of them — Azeret Mono, Fira Code and JetBrains Mono — draw `=>`, `!==` and `&&` as single glyphs, and the preview shows you what that looks like before you choose. **None is the default**, and it is the only choice that costs your visitors nothing: any other loads the font from [Bunny Fonts](https://fonts.bunny.net/), so each reader's browser makes one request to `fonts.bunny.net`. Bunny sets no cookies, keeps no logs and needs no account, but it is still a server that is not yours, so the plugin never calls it unless you ask it to. With None, code boxes keep the font your theme or your own CSS gives them, exactly as before. The chosen font is used in the block editor too, for the code inside the block as you type it, without the ligatures — in an editing box a merged glyph reads as though a character has gone missing.
* IMPROVED: Code is lifted out of the content before any content filter runs and put back after the last one, both when displaying and when saving. `wptexturize`, `wpautop`, autoembed, KSES and other plugins' filters now run over content containing no code at all, and what is stored is byte-for-byte what the author typed — including for users without the `unfiltered_html` capability.
* CHANGED: Only the 37 language tags the plugin actually shipped are recognised, plus the aliases `as`, `html`, `js`, plus `[sourcecode]` and `[github]`. Any other tag is left completely alone so it cannot collide with another plugin's shortcode.
* REMOVED: Adding languages by dropping GeSHi language files into a `geshi/` directory — in the plugin (v3.0) or in a theme (v4.1) — is no longer supported. The plugin has no `geshi/` directory any more and does not look for one in a theme either. Such snippets will show up as ordinary post text with the `[tag]` markers visible. Approx 300 languages ship with the plugin now, so there is almost certainly one for what you were adding.
* CHANGED: The `file` label sits above the code box, at the left, and is always visible whatever the toolbar setting says — in v5 it lived in the toolbar, so turning the toolbar off hid it too. A snippet given no `file` label gets no label element at all, but it still gets the wrapper — see the markup change below.
* CHANGED: `highlight` used together with `firstline` now refers to the line numbers as displayed, offset by `firstline`. GeSHi used the physical line numbers of the code.
* BUGFIX: `lang` actually works now. In v5 both `language` and `lang` defaulted to `code`, so the alias was never reached and `lang="php"` was silently ignored.
* CHANGED: The `highlight` attribute is capped at 10,000 lines in total — not per range. Once that many lines have been collected the rest of the attribute is ignored. v5 had no guard, so `highlight="1-999999999"` would build an enormous array.
* CHANGED: The `<pre>` element carries the language class as well as the `<code>` inside it — every Prism theme selects on `pre[class*="language-"]`.
* CHANGED: Every code box is wrapped in `<div class="igsh-code-box">`, which carries the box's `id`. Before, the wrapper appeared only when a `file` label was given and the `id` sat on the `<pre>`. Theme CSS selecting a code box as a direct child, such as `.entry-content > pre`, needs a descendant selector instead. Selectors on `pre[class*="language-"]` still work, and the `<pre>` keeps its classes and `data-` attributes.
* CHANGED: Language names map onto Prism's ids at render time — `html`, `html4strict`, `html5` and `xml` become `markup`; `mysql` and `postgresql` become `sql`; `oracle11` becomes `plsql`; `jquery` and `js` become `javascript`; `rails` becomes `ruby`; `pcre` becomes `regex`; `actionscript3` and `as` become `actionscript`; `java5` becomes `java`; `apache` becomes `apacheconf`; `vb` and `vbnet` become `visual-basic`; `code` and `text` become `none`, a styled but deliberately unhighlighted box. Three of these change the language rather than just its name: `asp` is now highlighted as **ASP.NET** (GeSHi's `asp` was classic ASP, which Prism has no component for), `perl6` as **Perl** (Prism has no Raku component) and `c_mac` as plain **C**. The code itself is untouched in every case, only the colouring differs. A snippet converted to a block stores the mapped name rather than the tag, so that the block's language dropdown shows it.
* CHANGED: The `plaintext`, `toolbar` and `strict_mode` attributes are accepted and ignored — GeSHi-era ideas with no Prism equivalent. Leaving them in an old post is harmless, they never reach the markup.
* CHANGED: Settings. Plugin CSS becomes a Prism **theme** dropdown, defaulting to **Okaidia**; plain text view becomes copy-to-clipboard; GeSHi strict mode, its exception list and "link to manual" are gone; a new **Limit the height of Gist embeds** option ships switched **on**. Toolbar, line numbers, code in comments and Gist in comments carry over and existing values are migrated on update.
* NEW: A snippet can quote this plugin's own tags. Double the brackets of a tag inside the code and it is written as text — `[[php]]` shows as `[php]`, `[[/php]]` shows as `[/php]` — so a post about this plugin can put a whole `[sourcecode]…[/sourcecode]` example inside a code box. Until now a snippet ended at the first closing tag in its code and everything after it was lost. What is stored is what you typed, the extra brackets come off on the way to a reader and nowhere else, and the rule applies to itself, so `[[[/php]]]` shows a reader `[[/php]]`. This is not the same as WordPress's escape for a whole shortcode, which is the next entry and is unchanged.
* Escaped tags stay text. `[[php]x[/php]]` is stored with both pairs of brackets exactly as typed and stays that way across edits; on screen the outer pair comes off and you see `[php]x[/php]`, the same thing WordPress does with any escaped shortcode. No code box is built for one, and an excerpt drops it along with the real snippets.
* BUGFIX: `[github gist="https://gist.github.com/…"]` embeds the Gist it names. In v5 the embed ran at priority 10 on `the_content`, behind `wptexturize`, which curled the quotes around the URL before the plugin could read it — so every one of those embeds pointed at `https://gist.github.com/.js` and showed nothing. `[github id="…"]` escaped that only because it has no URL to mangle. The embed now runs at priority 9, ahead of `wptexturize` — and therefore ahead of anything else you have hooked to `the_content` at priority 10. Everything else about Gist embeds, including the option to allow them in comments, is unchanged.
* CHANGED: `strip_shortcodes()` now removes this plugin's tags as well, anywhere on the site, because the plugin hooks `strip_shortcodes_tagnames`. This is what stops an automatic excerpt printing a snippet as prose, but it is wider than excerpts — any caller of `strip_shortcodes()` is affected.

= v5.1 =

* Minimum required PHP version bumped to 7.4.0. The plugin simply won't load its code on lower versions.
* Refactored plugin code for PHP 7.4.x for better performing code.
* **This is the last release using the GeSHi library.** GeSHi library has not been updated in several years and it looks unlikely that it will continue. Next release of the plugin will use a different syntax highlighting library. All existing shortcodes will continue to work and so the update and transition would be seamless for the most part, except a feature or two that will phase out and a few changes in plugin configuration options.

= v5.0 =

* Minimum required PHP version bumped to 5.3.0. The plugin simply won't load its code on lower versions.
* Major re-write of plugin for cleaner, modular & better performing code.
* Assets are enqueued only if needed.
* NEW: You can now disable plugin stylesheet which styles code boxes. People who have their own styling don't need it anyway.
* NEW: 2 new options allow more control on GeSHi behaviour.
* BUGFIX: Language name cache was not re-building automatically.

= v4.3 =

* BUGFIX: some language file names got snipped when building language name cache

= v4.2 =

* BUGFIX: Shorthand tags for all languages supported now - props to Karol Kuczmarski for spotting it
* NEW: Added C++ language file

= v4.1 =

* BUGFIX: Github Gist URL XSS security hole
* BUGFIX: `__dir__` doesn't work below PHP 5.3 - props to Karol Kuczmarski for spotting it
* NEW: Added "lang" as shorthand for "language" attribute
* NEW: Additional GeSHi language files can be put in "geshi" directory in theme, which will prevent their deletion on plugin upgrade
* IMPROVED: If a code block is repeated with same attributes then its parsed only once and output is reused

= v4.0 =

* NEW: Ability to embed Github Gist in post and comments (configurable)
* NEW: Ability to highlight one or multiple lines in a code block to show them as different
* NEW: New code box layout
* NEW: Ability to escape plugin tags to prevent their processing
* NEW: New GeSHi core (v 1.0.8.11)
* IMPROVED: Removed quirks from plain text view & its now much more smoother
* IMPROVED: Handling of how code is prevented from beautification. The rest of the post/comment text is not affected as wptexturize is not removed anymore.
* IMPROVED: Simpler and faster options page in wp-admin

= v3.5 =

* BUGFIX: BB Tags except the ones of iG:Syntax Hiliter are allowed. The language file's existence is checked before parsing the code. If the language file does not exist then the code is not parsed.
* BUGFIX: 'C' code hiliting is now fixed.
* BUGFIX: 'Plain Text' has been improved to strip the extra blank lines and spaces in Opera and FireFox.
* The latest stable GeSHi core(v1.0.7.6).
* NEW: Code Hiliting for Comments has been implemented. This feature can be Enabled/Disabled from the admin interface for iG:Syntax Hiliter. The tags are same for hiliting the code.
* NEW: A cross-browser Colour Picker(tested in IE6, FireFox1.5 and Opera8.5) is now available to easily set the line colours displayed in the code box.
* NEW: A new type of view implemented for seeing "Plain Text" code. Besides opening the plain text code in a new window, you can have it displayed in the code box itself with an option to display the hilited HTML code back again. The "Plain Text" view type can be set in the admin interface.
* The language file for Ruby that I created a while back is now bundled with the plugin and its also a part of the default GeSHi package.

= v3.1 =

* BUGFIX: Critical bug, which broke the plugin when the square brackets([ & ]) were used in the posts in places other than tags, has been fixed.
* BUGFIX: Another bug, which allowed any attribute in the tags besides the 'num' and also allowed any attribute value for it, affecting the processing. Now only the 'num' attribute is accepted and if you specify the 'num' attribute then its value must be a positive number otherwise your code won't be hilited. The 'num' attribute is optional and you can leave it out without any problems.
* BUGFIX: Fixed the unclosed <select> tags in the Plugin GUI code.
* GeSHi BUGFIX: Fixed a bug in GeSHi where the first line colour was not used when using FANCY LINE NUMBERS thus resulting in just one colour being used for the alternate lines.
* There's a problem in WordPress due to which the starting delimiters of ASP, PHP were not displayed correctly, as whitespace was inserted between the '<' and the rest of the delimiter. This has been patched so that its displayed correctly, but its not saved in the database, so the database still contains the delimiters as formatted by WordPress.

= v3.0 =

* Complete re-write of the plugin resulting in reduction of code from 750+ lines to about 400 Lines.
* New GeSHi Core(v1.0.7) which has some bug-fixes, please see GeSHi Website for its changelog.
* New languages added are C#, Delphi, Smarty & VB.NET.
* ASP language file structure updated & more keywords added.
* Drag-n-Drop usage of new languages. The plugin now supports all languages that GeSHi(v1.0.7) supports. You just need to drop the language file in the "geshi" directory & use the filename as the tag for the language(like if file is "pascal.php", then the filename is "pascal" & the tags will be [pascal] & [/pascal]).
* Language name which is displayed in the Code-Box can now be turned ON or OFF easily.
* No more need to set the physical-path to the "geshi" directory if you are doing a default installation.
* Plain-Text View of the code hilited in the code-box is now possible. This feature can be enabled/disabled easily in the Configuration Interface in WordPress Administration.
* NO NEED TO EDIT THE PLUGIN FILE ANYMORE. You can now configure the plugin settings from a GUI located under the OPTIONS menu in your WordPress Administration(WordPress 1.5 & above only).

= v2.01 =

* BUGFIX: Fixed a bug by removing a <br /> tag from the function pFix() which lead to closing of an unnecessary <p> tag making the code not xHTML valid(as per my desires).

= v2.0 Final =

* Implemented the new version of GeSHi core, v1.0.2 which has some bug fixes & which uses OL(Ordered Lists) for Line Numbering and supports starting of a Line Number from any given number.
* The ASP(Active Server Pages) language file has been updated to the new Language File structure of GeSHi as well as more keywords added & hiliting is more effective now.
* iG:Syntax Hiliter now also supports ActionScript, C, C++, JavaScript, Perl, Python, Visual Basic & XML.
* The whole plugin has been re-written & all the hiliting code is now in a class. You can just use the class anywhere else too for hiliting the code. But to also use the Code Tags to wrap your code & then hilite them, you will need to use all other functions. You can remove the WordPress Filter calls at the end of the plugin & use the rest of the code as you want somewhere else.
* BUGFIX: The issue of multi-line comments not being hilited properly in v2.0 Preview has been sorted out.

= v2.0 Preview =

* Implemented the new version of GeSHi core, v1.0.1 which has some bug fixes including the extra quote(") bug that broke the xHTML validation of the code.
* I've created a new language file for ASP(Active Server Pages) which has been added to this release & will also be a part of the next GeSHi release.
* Line numbering is now done through Ordered Lists(<OL>) & the code is xHTML compliant.
* Auto-Formatting disabled for posts that contain the iG:Syntax Hiliter code tags so that your code is good for copy-paste operations.

= v1.1 =

* Implemented the line numbering of code.
* The code box is now of fixed dimensions without word-wrap & with scrollbars(if required).

= v1.0 =

* Hilites code between the special tags, all of them differently.
* Uses GeSHi for syntax hiliting.
* Supports HTML, CSS, PHP, JAVA & SQL codes.


== Upgrade Notice ==

= 6.0-beta-1 =
GeSHi is replaced by Prism.js and Gutenberg support added. Requires PHP 8.4 and WordPress 6.9. Old posts keep working, but opening one in the block editor converts its snippets to blocks — read "Important changes in 6.0-beta-1" before updating.

= 5.1 =
Major refactor of plugin code for compatibility with PHP 7.4.0 and above.




