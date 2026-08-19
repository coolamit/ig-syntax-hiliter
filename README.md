## **iG:Syntax Hiliter v6.0-beta-1**
---------------------------------

**iG:Syntax Hiliter** is a WordPress plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

##### **Minimum Requirements**
- WordPress 6.9 or above
- PHP 8.4 or above

**WordPress.org:** [https://wordpress.org/plugins/igsyntax-hiliter/](https://wordpress.org/plugins/igsyntax-hiliter/)

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [Prism.js library](https://prismjs.com/) to colourise your code and supports ~300 programming languages, all of which are bundled with the plugin.

You can write code snippets using the Gutenberg block editor or using this plugin's shortcodes in the classic editor's Code view. Both are supported. The classic editor's Visual (WYSIWYG) tab is not supported and never has been — it mangles code before the plugin ever sees it.

**NOTE :** Highlighting happens in the visitor's browser, not on your server. Prism's files are loaded only on pages that actually contain a code snippet and it loads only the languages that page needs.

### **[Changelog](CHANGELOG.md)**

### **Important changes in 6.0-beta-1**

Version 6.0-beta-1 replaces the GeSHi library, which no longer seems to be maintained, with Prism.js. Most of that you will not notice. A few things do change in ways that can affect posts you already have, so please read this before updating.

#### **Posts you never open in the block editor are not touched**

Every shortcode this plugin has ever shipped keeps rendering straight out of `post_content`. A post written in 2004 that nobody ever edits again will keep rendering correctly, forever. Editing a post in the classic editor's Code view triggers no conversion either — shortcodes stay shortcodes. Code in comments keeps working the same way it always has.

#### **Opening a post that contains legacy shortcodes in the block editor converts its snippets to blocks**

This happens automatically, on load, without asking and it is written to the post the next time you save. Open the post and close it again without saving and nothing has changed.

The reason is that WordPress hands a classic post to the block editor as a single Classic (TinyMCE) block containing the whole post, code and all — and TinyMCE mangles code. It reads `<?php echo "<div>x</div>"; ?>` as HTML: the `<div>` becomes a real element and the `<?php … ?>` is dropped entirely. Because the whole post is one block, editing an unrelated paragraph is enough to re-save every snippet in the post in that damaged form. The conversion moves your code into block attributes, where TinyMCE cannot reach it, before that can happen. It is surgical — only the snippets are replaced and every other byte of the post is left exactly as it was.

A snippet whose *code* quotes block markup is handled too. The snippets are lifted out of the post before the block editor's parser reads it, so a post showing `<!-- wp:… -->` as an example is not taken apart on screen — whoever's block the quoted markup belongs to.

#### **Snippets that have been converted to blocks are not visible if the plugin is deactivated**

This is the trade-off for the block editor support and on this one point blocks are worse than shortcodes. A shortcode left behind by a deactivated plugin at least stays on screen as `[php]…[/php]` text, which you can see and act on. A block whose plugin is gone is not registered at all, renders as nothing and the snippet silently disappears from the post.

The Gist block goes the same way for the same reason, and so does the Gist it names.

To fix this issue, the settings page of the plugin has a **Before you deactivate** section containing a tool that converts this plugin's blocks back into shortcodes across the whole site — published, draft, pending, scheduled and private posts of public post types. Run it before you deactivate or delete the plugin. A code block becomes a `[sourcecode language="…"]` shortcode, so a snippet that started life as `[php]…[/php]` comes back as `[sourcecode language="php"]…[/sourcecode]`. That is the same thing semantically, but it is not a byte-for-byte round trip to what you originally typed. A Gist block becomes `[github gist="https://gist.github.com/…"]`, which is the address the block was already embedding.

A snippet whose code quotes this plugin's own tags converts like any other, and it is worth knowing how. A shortcode ends at the first closing tag in its code, so the tool doubles the brackets of every one of this plugin's tags it finds there — `[[sourcecode language="php"]]` and `[[/sourcecode]]` — which is how a snippet says that a tag is text rather than a tag. A reader sees the tags you typed. With the plugin deactivated you see the doubled brackets in the code sample instead, which is the price of the snippet being on the page at all.

#### **Only the tags the plugin actually shipped are recognised**

Those are:

```
actionscript actionscript3 apache applescript asp bash c c_mac code cpp csharp css
diff groovy html4strict html5 ini java java5 javascript jquery mysql oracle11 pcre
perl perl6 php postgresql python rails ruby sql text vb vbnet xml yaml
```

plus the aliases `as`, `html` and `js`, plus `[sourcecode]` and `[github]`.

A tag this plugin never shipped is left completely alone — not registered, not rendered, not converted. If your post contains `[email]`, it stays `[email]` and goes to whichever plugin owns it. That is deliberate: claiming a wider set of tags would mean taking shortcodes away from other plugins, which is a worse problem than the one it would solve.

This also means that if you added custom language files for GeSHi and were using them with drop-in tags (eg., you added `email` language file and were using `[email]` tag), those are no longer recognized by the plugin and are left behind. You can go back and update those posts if you want and switch those tags to `[sourcecode]` variant or into this plugin's block for the block editor - and they will then be picked by the plugin and have the syntax highlighted on frontend. This is a breaking change in v6.0 as it is almost impossible to determine if a BB tag (for a language not originally shipped with this plugin) is for use with this plugin via drop-in language support or use with some other plugin. But this affects only `[<LANGUAGE_NAME>]` type of tags. So `[email]` will be left behind but `[sourcecode language="email"]` would still be supported and get converted to this plugin's block if you use the block editor and open that post in it for editing.

#### **Some old tags now highlight as a different language**

Prism does not have a component for everything GeSHi had, so a handful of tags are mapped onto the nearest thing Prism does have. In a shortcode the mapping happens when the page is rendered — what is stored in your post is the tag you typed — so it can be improved later without touching your posts. A snippet converted to a block is the exception and stores the mapped name, because that is the name the block's language dropdown offers. A name Prism does not know is stored exactly as you typed it either way — it is your word and a name overwritten could never be recovered — and such a snippet renders as an unhighlighted code box.

Most of it is uncontroversial: `html`, `html4strict`, `html5` and `xml` all become `markup`; `mysql` and `postgresql` become `sql`; `oracle11` becomes `plsql`; `jquery` becomes `javascript`; `rails` becomes `ruby`; `pcre` becomes `regex`; `actionscript3` and `as` become `actionscript`; `java5` becomes `java`; `js` becomes `javascript`; `apache` becomes `apacheconf`; `vb` and `vbnet` both become `visual-basic`; and `code` and `text` become `none`, which is a styled but deliberately unhighlighted box.

Three of them change the language, not just its name, so your code will be coloured by different rules than it was in v5:

- **`asp` is highlighted as ASP.NET.** GeSHi's `asp` was *classic* ASP, which Prism has no component for.
- **`perl6` is highlighted as Perl.** Perl 6 is a different language — it has been called Raku since 2019 — and Prism has no component for it.
- **`c_mac` is highlighted as plain C.**

Your code itself is untouched in every case; only the colouring differs.

#### **Languages added by dropping in GeSHi files are no longer supported**

Since v3.0 you could add a language by putting a GeSHi language file in the plugin's own `geshi/` directory and since v4.1 in a `geshi/` directory in your theme instead, in both cases using its filename as a tag. GeSHi is gone in 6.0-beta-1 and both mechanisms are gone with it. The plugin no longer ships a `geshi/` directory and no longer looks for one anywhere, in the plugin or in a theme. There is no automatic replacement. A snippet using such a tag is no longer recognised, so it will appear as ordinary post text with the `[tag]` markers visible, formatted by WordPress like any other text.

Approx 300 languages ship with the plugin, so there is a good chance one of them is the language you were adding — use it with `[sourcecode language="…"]`.

The changelog entries for v3.0 and v4.1 still describe those drop-in directories and are left as they are — they are the record of what those versions shipped, not of what 6.0-beta-1 does. Neither mechanism works in 6.0-beta-1.

#### **Highlighting is done by the browser now**

Prism colours the code client side. Its files load only on pages that contain a snippet and only the languages that page uses. Visitors with JavaScript turned off get a plain but properly styled code box with the code intact. A language the plugin cannot resolve produces an unhighlighted but styled box rather than an error — nothing breaks and nothing 404s.

#### **`strip_shortcodes()` now strips this plugin's tags too**

Worth knowing if you write themes or plugins. This plugin hooks `strip_shortcodes_tagnames`, so *any* call to `strip_shortcodes()` anywhere on the site — yours, your theme's, another plugin's — now removes `[php]`, `[code]`, `[html]`, `[js]`, `[c]`, `[sourcecode]` and the rest of this plugin's tags along with the shortcodes WordPress knows about.

That is deliberate. The plugin does not register its tags globally any more, so core has no idea they are shortcodes and an automatic excerpt would otherwise print a whole snippet as prose. Naming the tags to core fixes that everywhere at once — but it is wider than the excerpt path. If you were relying on `strip_shortcodes()` leaving `[php]…[/php]` standing in some other bit of content, it no longer will.

#### **The classic editor's Visual tab is still not a place to write code**

It never was. Snippets are supported in the block editor and in the classic editor's Code view. Switching a post to Visual mode can corrupt code inside shortcodes. That is documented behaviour since beginning when TinyMCE WYSIWYG editor was added; it is not a bug.

### **Installation**

#### **Upgrading from v4.0 or later**
Just click `update now` link below the plugin listing on the plugins page in your `wp-admin`. The plugin will handle any settings migration if needed. Easy peasy!!

Do read [Important changes in 6.0-beta-1](#important-changes-in-60) first. Your existing posts keep working, but there are a few things worth knowing before you open an old post in the block editor.

#### **Upgrading from v3.x**
Deactivate plugin in WordPress admin, delete the `syntax_hilite.php` file & `ig_syntax_hilite` directory from plugins folder and follow the installation process below.

#### **Upgrading from v2.1 or lower**
Deactivate plugin in WordPress admin, delete the `syntax_hilite.php` and `geshi.php` files & `geshi` directory from plugins folder and follow the installation process below.

#### **Installing The Plugin**
1. Login to your WordPress `wp-admin` area.
2. Click `Add New` in the `Plugins` menu on left.
3. Enter `iG:Syntax Hiliter` in the search bar on the right on the page that opens and press Enter key.
4. WordPress would show the **iG:Syntax Hiliter** plugin with install button, click that to install the plugin.
5. Click on `Activate Plugin` link on the page that opens after the plugin has been installed successfully.

**See Also:** ["Installing Plugins" in the WP documentation](https://wordpress.org/documentation/article/manage-plugins/#finding-and-installing-plugins-1)


### **Plugin Usage**

In the block editor, add the **iG:Syntax Hiliter** block, paste your code into it and pick a language in the sidebar. The code is stored as plain text in the block's attributes, so nothing in the editor or in WordPress' content filters can get at it — paste whatever you like, entities and all.

To embed a GitHub Gist, add the **iG:Syntax Hiliter Gist** block and paste the address of the Gist into it. The `[github]` shortcode goes on working exactly as it always has, and is not converted into the block. The **Before you deactivate** tool converts the block back the other way, because a Gist block vanishes on deactivation just as a code block does.

In the classic editor's Code view, use the shortcodes. There is one tag and a handful of optional attributes. Here's how code is posted for it to be highlighted.

```
[sourcecode language="language_name"]
//some code here
[/sourcecode]
```

So if you are posting some PHP code then it would be

```
[sourcecode language="php"]
//some code here
[/sourcecode]
```

or you can use shorthand tags like

```
[php]
//some code here
[/php]
```

Its advised to use the full format of the tag for semantics, however its a personal choice and the plugin supports both full format and shorthand.

HTML entities need not be escaped, you can post your code as is and the plugin takes care of it all.

**Important :** Do not forget to close the tags, as your code will not be highlighted if you don't close your tags. Also, *don't nest tags*. Nesting of tags don't work, so don't try it, it'll ruin your output.

**Writing about this plugin :** If the code inside a snippet has to contain one of this plugin's own tags, double its brackets. `[[php]]` shows a reader `[php]` and `[[/php]]` shows `[/php]`, so a whole example fits inside a code box:

```
[sourcecode language="php"]
[[sourcecode language="php"]]
echo 'hello';
[[/sourcecode]]
[/sourcecode]
```

Without this a snippet would end at the first closing tag in its code and everything after it would be lost. What is stored is what you typed — the extra brackets come off on the way to a reader and nowhere else — and the rule applies to itself, so `[[[/php]]]` shows a reader `[[/php]]`. This is *not* the same as WordPress's escape for a whole shortcode, `[[php]x[/php]]`, which prints the shortcode instead of rendering it and still works exactly as it always has.

**Important :** The classic editor's Visual (WYSIWYG) tab will mess up your code as soon as you paste it in. That is TinyMCE, not this plugin, and it happens before the plugin sees anything — so please don't report it as a bug. Write code in the block editor or in the classic editor's Code view.

#### **(Optional) Plugin Attributes**

**language :** Use this to specify the programming language whose code you are posting. Any language Prism knows is accepted. If `language` is not specified, or names something that cannot be resolved, a plain but properly styled code box is rendered instead — and what you typed is kept, exactly as you typed it. `lang` is the shorthand for `language` attribute.

**firstline :** Use this to start line numbering from a number greater than 1.

**highlight :** Use this to tell plugin which lines are to be marked as different for emphasis. It accepts a comma separated list of line numbers and line number ranges like 5-8 which is equal to 5,6,7,8. Line numbers are the ones **as displayed** — so if you also use `firstline`, count from that number rather than from the top of the code. *(This changed in 6.0-beta-1; GeSHi counted physical lines instead.)* The whole attribute is capped at 10,000 lines in total — not 10,000 per range — and once that many have been collected the rest of the attribute is ignored. So `highlight="1-999999999"` marks the first 10,000 lines and stops there, and so does `highlight="1-8000,20000-30000"`, which reaches the cap 2,000 lines into its second range.

```
[sourcecode language="php" highlight="2,4-6,9"]
//line 1 of PHP code
//line 2 of PHP code
//line 3 of PHP code
//line 4 of PHP code
//line 5 of PHP code
//line 6 of PHP code
//line 7 of PHP code
//line 8 of PHP code
//line 9 of PHP code
[/sourcecode]
```

**file :** Use this to show a file name/path. It is printed just above the code box, at the left, and is always visible — whatever the **Show Toolbar?** setting says. In v5 the label lived in the toolbar, so turning the toolbar off hid it too; in 6.0-beta-1 it still shows, because `file` is an attribute you asked for and the toolbar setting is not about it.

Thirty characters of the label go on the page, the end of it rather than the beginning, because the end of a path is the part that names the file. A label longer than that is shown behind an ellipsis and the whole of it is in the tooltip, so hovering the label reads out the full path. A label that fits is written whole and gets no tooltip. The label is also run through `wp_strip_all_tags()` before any of that — it is free text on a public page and is treated as hostile. The cost of that is worth knowing: a name like `vector<int>.cpp`, `Foo<T>.cs` or `List<String>.java` loses its type parameter and shows as `vector.cpp`, `Foo.cs` and `List.java`. All of this is what v5 did as well.

**gutter :** Use this to tell plugin whether to show line numbers in the code box or not. It accepts either `yes` or `no`. This, if specified, will override the global option to show line numbers for that particular code box.

**plaintext, toolbar, strict_mode :** These are accepted and ignored. All three were GeSHi-era ideas with no Prism equivalent, and they were dropped in 6.0-beta-1 rather than faked. They are still parsed so that old posts don't break, and they never reach the markup — but setting them does nothing. Don't use them in new posts.


### **Configuration**

Configuring **iG:Syntax Hiliter** is a piece of cake. Login to your WordPress admin section & under the `Settings` menu you'll see `iG:Syntax Hiliter` in the sub-menu.

When you click the `iG:Syntax Hiliter` configuration page, you are offered some configuration settings which you can set to your liking. Lets go through each of them.

**Theme :** Pick which of the bundled themes is used to style code boxes. There are 43 of them — Prism's own eight, plus 35 from the [prism-themes](https://github.com/PrismJS/prism-themes) collection, which brings One Dark, Nord, Dracula, VS Code Dark+, Gruvbox, Material and others. They are listed by name, with `None` at the top. Whichever you pick, exactly one stylesheet is loaded on a page that has a code box, so a long list costs a visitor nothing. **Okaidia** is the default. A sample code box sits beside the settings and repaints as you change them, so you can see a theme before you go looking for a post to check it on — the font, the toolbar, the copy button, the line numbers and both bracket options show up in it too. Choose `None` if you'd rather style code boxes yourself — nothing of the plugin's own CSS is loaded then. This replaces v5's *Use plugin CSS for styling?* option, and your old setting is carried over (`YES` becomes the default theme, `NO` becomes `None`).

**Font :** Pick the typeface your code boxes are set in. Fifteen monospaced fonts are offered — Azeret Mono, Cascadia Code, Fira Code, Fira Mono, Google Sans Code, IBM Plex Mono, Inconsolata, JetBrains Mono, M PLUS Code Latin, Nova Mono, Roboto Mono, Source Code Pro, Space Mono, Ubuntu Mono and Victor Mono — and the preview beside the settings changes as you pick, so you can read some code in a font before you commit to it. The dropdown is split into **With Ligature** and **Without Ligature**, with `None` above both, because that is the one question worth asking about a font you are going to read code in: Cascadia Code, Fira Code, JetBrains Mono and Victor Mono draw `=>`, `!==` and `&&` as single glyphs, which some people love and some do not, and the eleven in the other group do not. The sample shows you which is which. **`None` is the default and it is the only choice that costs your visitors nothing:** any other loads the font from [Bunny Fonts](https://fonts.bunny.net/), so each reader's browser makes one request to `fonts.bunny.net`. Bunny is a privacy-first font service — it sets no cookies, stores no logs and needs no account — but it is still a request to a server that is not yours, which is why the plugin never makes it unless you ask. With `None`, code boxes keep the font your theme or your own CSS gives them. The font is also used for the code inside the block while you are editing it, minus the ligatures — in an editing box a merged glyph makes it look as though a character has gone missing, so there they stay separate.

**Show Toolbar? :** This option allows you to tell the plugin whether to show the tool-bar (which shows the language name and the copy button) above the code boxes or not. Unlike v5's, this toolbar is not drawn into the box: it fades in when a visitor hovers over the code box or moves keyboard focus into it. Turning it off does not hide the `file` label, which is printed above the box instead — see the `file` attribute above.

**Show copy-to-clipboard button? :** Puts a button on the toolbar that copies the snippet to the clipboard. This is what became of v5's *Show Plain Text Option?*, and your old setting carries over — the intent was always "let people get at the raw code", and copying it is a better way to do that than a second view.

**Show line numbers in code? :** This option allows you to tell the plugin whether to show the line numbers along with code in the code boxes or not. Line numbers along with code look great, are a great help when referring to some code from a code box. This option can be overridden for any code block using `gutter` attribute in the tag, or the equivalent toggle on the block.

**Point out matching brackets? :** Hovering over a bracket, a brace or a parenthesis draws a thin outline around it and around its partner, so you can see at a glance where a block begins and ends. Clicking one keeps the pair outlined until you click somewhere else in the box. **On by default**, because nothing shows until a reader hovers — a page at rest looks exactly as it did.

**Colour brackets by depth? :** Gives every level of nesting its own colour, so a bracket and the one that closes it are painted alike. **Off by default**, because unlike the option above it repaints every code box on your site the moment you switch it on. Four of the bundled themes — One Dark, One Light, Coldark Cold and Coldark Dark — pick these colours themselves and will use their own; everywhere else they are the ones this plugin ships, which may or may not suit the theme you have chosen. The preview beside the settings shows you before you save.

**Limit the height of Gist embeds? :** Keeps each file in an embedded GitHub Gist inside a box of its own and gives it a scrollbar when the file is taller than that, so a Gist of a few long files does not take over the page. **On by default.** A file shorter than the box is untouched and shows no scrollbar. Switch it off to get the full height back.

**Hilite code in comments? :** This option allows you to tell the plugin whether to highlight code posted in comments or not. If this is enabled, code posted in the comments will be highlighted as it is in the posts. Comments are not block content and there is no block editor for them, so this remains a shortcode-only feature.

**Enable GitHub Gist embed in comments? :** This option allows you to tell the plugin whether to embed Github Gist in comments or not. If disabled then a Gist posted in comments would just have a link to its page on Github.

**Before you deactivate :** A section at the bottom of the settings page. Its one tool converts this plugin's blocks back into shortcodes across the entire site — a code block into `[sourcecode language="…"]` and a Gist block into `[github gist="…"]` — so your snippets and your Gists stay visible if the plugin is ever deactivated. It asks for confirmation first, because it rewrites post content and cannot be undone. Every block converts: where the code holds one of this plugin's own tags, the tool doubles its brackets, which is how a snippet says a tag is text. See *Important changes in 6.0-beta-1* above for why you would want it.

*(Gone in 6.0-beta-1: **GeSHi Strict Mode?**, **Languages where GeSHi strict mode is disabled**, **Link keywords/function names to Manual?** — all three were GeSHi features with no Prism equivalent — and **Rebuild Shorthand Tags**, since there is no longer a directory of language files to scan.)*


### **Frequently Asked Questions**

**Q:** *My code looks all odd, characters appear as HTML entities. Why is your plugin screwing up my code?*

**A:** If you are writing your post in the classic editor's Visual (WYSIWYG) tab then that is what is messing things up for you. That tab is TinyMCE, it treats your code as HTML and it does its damage before this plugin ever sees the content. It has never been supported. Use the block editor or the classic editor's Code view. If you are using either of those and still see this, please report it.

**Q:** *I opened an old post in the block editor and my code blocks turned into iG:Syntax Hiliter blocks. Why?*

**A:** Because leaving them alone was worse. See the **Important changes in 6.0-beta-1** section above for the full explanation. Short version: the block editor loads a classic post into one big TinyMCE block, TinyMCE eats code and converting the snippets to blocks first is the only way to stop that. The conversion is only written to the post if you save it and it leaves the rest of your post byte-for-byte as it was.

**Q:** *What happens to my code if I deactivate the plugin?*

**A:** Snippets still stored as shortcodes stay visible as `[sourcecode]…[/sourcecode]` text — ugly, but you can see them and do something about them. Snippets that have been converted to blocks render as nothing at all, because the block is no longer registered, and a Gist block goes the same way. Before deactivating, use the tool in the **Before you deactivate** section of the settings page to turn those blocks back into shortcodes — `[sourcecode]` for a code block and `[github]` for a Gist block. Every block converts, this plugin's own tags in the code included.

**Q:** *I used to add languages by putting GeSHi language files in the plugin's or my theme's `geshi` directory. What now?*

**A:** Both of those mechanisms are gone with GeSHi. Approx 300 languages ship with the plugin now, so start by checking whether yours is one of them — use it with `[sourcecode language="…"]` or pick it in the block.

**Q:** *I see some code that I can improve. Do you accept pull requests?*

**A:** By all means, feel free to submit a pull request.

**Q:** *I want XYZ feature. Can you implement it?*

**A:** Please feel free to suggest a new feature. Its inclusion might be speedier if you can provide the code to make it work.
