## **iG:Syntax Hiliter v6.0.0**
---------------------------------

**iG:Syntax Hiliter** is a WordPress plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

##### **Minimum Requirements**
- WordPress 6.9 or above
- PHP 8.4 or above

**WordPress.org:** [https://wordpress.org/plugins/igsyntax-hiliter/](https://wordpress.org/plugins/igsyntax-hiliter/)

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting  (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [Prism.js library](https://prismjs.com/) to colourise your code and supports close to 300 programming languages, all of which are bundled with the plugin. More can be added by dropping in Prism component files.

You can write code snippets using the block editor, or using this plugin's shortcodes in the classic editor's Text view. Both are supported. The classic editor's Visual (WYSIWYG) tab is not, and never has been — it mangles code before the plugin ever sees it.

**NOTE :** Highlighting happens in the visitor's browser, not on your server. Prism's files are loaded only on pages that actually contain a code snippet, and only the languages that page needs.

### **[Changelog](CHANGELOG.md)**

### **Important changes in 6.0**

Version 6.0 replaces the GeSHi library, which has not been maintained in over a decade, with Prism.js. Most of that you will not notice. A few things do change in ways that can affect posts you already have, so please read this before updating.

#### **Posts you never open in the block editor are not touched**

Every shortcode this plugin has ever shipped keeps rendering straight out of `post_content`. A post written in 2004 that nobody ever edits again will keep rendering correctly, forever. Editing a post in the classic editor's Text view triggers no conversion either — shortcodes stay shortcodes. Code in comments keeps working the same way it always has.

#### **Opening a post that contains legacy shortcodes in the block editor converts its snippets to blocks**

This happens automatically, on load, without asking, and it is written to the post the next time you save. Open the post and close it again without saving and nothing has changed.

The reason is that WordPress hands a classic post to the block editor as a single Classic (TinyMCE) block containing the whole post, code and all — and TinyMCE mangles code. It reads `<?php echo "<div>x</div>"; ?>` as HTML: the `<div>` becomes a real element and the `<?php … ?>` is dropped entirely. Because the whole post is one block, editing an unrelated paragraph is enough to re-save every snippet in the post in that damaged form. The conversion moves your code into block attributes, where TinyMCE cannot reach it, before that can happen. It is surgical — only the snippets are replaced, and every other byte of the post is left exactly as it was.

#### **Snippets that have been converted to blocks are not visible if the plugin is deactivated**

This is a genuine trade-off, and on this one point blocks are worse than shortcodes. A shortcode left behind by a deactivated plugin at least stays on screen as `[php]…[/php]` text, which you can see and act on. A block whose plugin is gone is not registered at all, renders as nothing, and the snippet silently disappears from the post.

So the settings page has an **Uninstall** section containing a tool that converts this plugin's blocks back into `[sourcecode language="…"]` shortcodes across the whole site — published, draft, pending, scheduled and private posts of public post types. Run it before you deactivate or delete the plugin. It always writes the `[sourcecode]` form, so a snippet that started life as `[php]…[/php]` comes back as `[sourcecode language="php"]…[/sourcecode]`. That is the same thing semantically, but it is not a byte-for-byte round trip to what you originally typed.

One kind of snippet cannot be converted, and you should know about it: if the code inside a block itself contains the literal text `[/sourcecode]`, there is no shortcode that can hold it — the shortcode would end at that point and the rest of your code would be thrown away. The tool leaves those blocks exactly as it found them rather than truncating them, which means they stay blocks, and they stay invisible if the plugin is deactivated. If you have such a snippet, deal with it by hand before you deactivate.

#### **Only the tags the plugin actually shipped are recognised**

Those are:

```
actionscript actionscript3 apache applescript asp bash c c_mac code cpp csharp css
diff groovy html4strict html5 ini java java5 javascript jquery mysql oracle11 pcre
perl perl6 php postgresql python rails ruby sql text vb vbnet xml yaml
```

plus the aliases `as`, `html` and `js`, plus `[sourcecode]` and `[github]`.

A tag this plugin never shipped is left completely alone — not registered, not rendered, not converted. If your post contains `[email]`, it stays `[email]` and goes to whichever plugin owns it. That is deliberate: claiming a wider set of tags would mean taking shortcodes away from other plugins, which is a worse problem than the one it would solve.

#### **Some old tags now highlight as a different language**

Prism does not have a component for everything GeSHi had, so a handful of tags are mapped onto the nearest thing Prism does have. The mapping happens when the page is rendered — what is stored in your post is the tag you typed — so it can be improved later without touching your posts.

Most of it is uncontroversial: `html`, `html4strict`, `html5` and `xml` all become `markup`; `mysql` and `postgresql` become `sql`; `oracle11` becomes `plsql`; `jquery` becomes `javascript`; `rails` becomes `ruby`; `pcre` becomes `regex`; `actionscript3` and `as` become `actionscript`; `java5` becomes `java`; `js` becomes `javascript`; `apache` becomes `apacheconf`; `vb` and `vbnet` both become `visual-basic`; and `code` and `text` become `none`, which is a styled but deliberately unhighlighted box.

Three of them change the language, not just its name, so your code will be coloured by different rules than it was in v5:

- **`asp` is highlighted as ASP.NET.** GeSHi's `asp` was *classic* ASP, which Prism has no component for.
- **`perl6` is highlighted as Perl.** Perl 6 is a different language — it has been called Raku since 2019 — and Prism has no component for it.
- **`c_mac` is highlighted as plain C.**

Your code itself is untouched in every case; only the colouring differs.

#### **Languages added by dropping in GeSHi files are no longer supported**

Since v3.0 you could add a language by putting a GeSHi language file in the plugin's own `geshi/` directory, and since v4.1 in a `geshi/` directory in your theme instead, in both cases using its filename as a tag. GeSHi is gone in 6.0 and both mechanisms go with it. The plugin no longer ships a `geshi/` directory and no longer looks for one anywhere, in the plugin or in a theme. There is no automatic replacement. A snippet using such a tag is no longer recognised, so it will appear as ordinary post text with the `[tag]` markers visible, formatted by WordPress like any other text.

There are two ways to deal with that, neither as convenient as dropping in a file used to be:

- Put a Prism component file for the language in `wp-content/uploads/igsyntax-hiliter/components/`. That directory is outside the plugin, so it survives plugin updates. The language then works in `[sourcecode language="…"]` and shows up in the block's language dropdown.
- If you need the tag itself back — `[yourlang]…[/yourlang]` — re-register it with the `ig_syntax_hiliter/shortcode_tags` filter.

The changelog entries for v3.0 and v4.1 still describe the drop-in directories, and are left as they are — they are the record of what those versions shipped, not of what 6.0 does. Neither mechanism works in 6.0.

#### **Highlighting is done by the browser now**

Prism colours the code client side. Its files load only on pages that contain a snippet, and only the languages that page uses. Visitors with JavaScript turned off get a plain but properly styled code box with the code intact. A language the plugin cannot resolve produces an unhighlighted-but-styled box rather than an error — nothing breaks and nothing 404s.

#### **`strip_shortcodes()` now strips this plugin's tags too**

Worth knowing if you write themes or plugins. This plugin hooks `strip_shortcodes_tagnames`, so *any* call to `strip_shortcodes()` anywhere on the site — yours, your theme's, another plugin's — now removes `[php]`, `[code]`, `[html]`, `[js]`, `[c]`, `[sourcecode]` and the rest of this plugin's tags along with the shortcodes WordPress knows about.

That is deliberate. The plugin does not register its tags globally any more, so core has no idea they are shortcodes, and an automatic excerpt would otherwise print a whole snippet as prose. Naming the tags to core fixes that everywhere at once — but it is wider than the excerpt path. If you were relying on `strip_shortcodes()` leaving `[php]…[/php]` standing in some other bit of content, it no longer will.

#### **The classic editor's Visual tab is still not a place to write code**

It never was. Snippets are supported in the block editor and in the classic editor's Text view. Switching a post to Visual mode can corrupt code inside shortcodes. That is documented behaviour, not a bug.

### **Installation**

#### **Upgrading from v4.0 or later**
Just click `update now` link below the plugin listing on the plugins page in your `wp-admin`. The plugin will handle any settings migration if needed. Easy peasy!!

Do read [Important changes in 6.0](#important-changes-in-60) first. Your existing posts keep working, but there are a few things worth knowing before you open an old post in the block editor.

#### **Upgrading from v3.x**
Deactivate plugin in WordPress admin, delete the `syntax_hilite.php` file & `ig_syntax_hilite` directory from plugins folder and follow the installation process below.

#### **Upgrading from v2.1 or lower**
Deactivate plugin in WordPress admin, delete the `syntax_hilite.php` and `geshi.php` files & `geshi` directory from plugins folder and follow the installation process below.

#### **Installing The Plugin**
1. Login to your WordPress `wp-admin` area.
2. Click `Add New` in the `Plugins` menu on left.
3. Enter `iG:Syntax Hiliter` in the search bar on the right of the page that opens and press Enter key.
4. WordPress would show the **iG:Syntax Hiliter** plugin with install button, click that to install the plugin.
5. Click on `Activate Plugin` link on the page that opens after the plugin has been installed successfully.

**See Also:** ["Finding and Installing Plugins" article on the WordPress Support](https://wordpress.org/support/article/managing-plugins/#finding-and-installing-plugins)


### **Plugin Usage**

In the block editor, add the **iG:Syntax Hiliter** block, paste your code into it and pick a language in the sidebar. The code is stored as plain text in the block's attributes, so nothing in the editor or in WordPress' content filters can get at it — paste whatever you like, entities and all.

In the classic editor's Text view, use the shortcodes. There is one tag and a handful of optional attributes. Here's how code is posted for it to be highlighted.

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

**Important :** The classic editor's Visual (WYSIWYG) tab will mess up your code as soon as you paste it in. That is TinyMCE, not this plugin, and it happens before the plugin sees anything — so please don't report it as a bug. Write code in the block editor or in the classic editor's Text view.

#### **(Optional) Plugin Attributes**

**language :** Use this to specify the programming language whose code you are posting. Any language Prism knows is accepted, as are languages you have dropped into `wp-content/uploads/igsyntax-hiliter/components/`. If `language` is not specified, or names something that cannot be resolved, a plain but properly styled code box is rendered instead. `lang` is the shorthand for `language` attribute.

**firstline :** Use this to start line numbering from a number greater than 1.

**highlight :** Use this to tell plugin which lines are to be marked as different for emphasis. It accepts a comma separated list of line numbers and line number ranges like 5-8 which is equal to 5,6,7,8. Line numbers are the ones **as displayed** — so if you also use `firstline`, count from that number rather than from the top of the code. *(This changed in 6.0; GeSHi counted physical lines instead.)* A single range is capped at 10,000 lines — anything beyond that is dropped, so `highlight="1-999999999"` marks the first 10,000 lines and stops there.

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

**file :** Use this to show a file name/path. This is displayed in the tool-bar shown above code box. The whole path goes into the page as you typed it; if it is too long for the toolbar the browser trims what it shows with an ellipsis, but the full path is still there in the page source. v5 cut the label down to 30 characters on the server before it ever reached the page, so if you were leaning on that to keep a long path out of your HTML, it no longer applies.

**gutter :** Use this to tell plugin whether to show line numbers in the code box or not. It accepts either `yes` or `no`. This, if specified, will override the global option to show line numbers for that particular code box.

**plaintext, toolbar, strict_mode :** These are accepted and ignored. All three were GeSHi-era ideas with no Prism equivalent, and they were dropped in 6.0 rather than faked. They are still parsed so that old posts don't break, and they never reach the markup — but setting them does nothing. Don't use them in new posts.


### **Configuration**

Configuring **iG:Syntax Hiliter** is a piece of cake. Login to your WordPress admin section & under the `Settings` menu you'll see `iG:Syntax Hiliter` in the sub-menu.

When you click the `iG:Syntax Hiliter` configuration page, you are offered some configuration settings which you can set to your liking. Lets go through each of them.

**Theme :** Pick which of the bundled Prism themes is used to style code boxes. Choose `None` if you'd rather style code boxes yourself — nothing of the plugin's own CSS is loaded then. This replaces v5's *Use plugin CSS for styling?* option, and your old setting is carried over (`YES` becomes the default theme, `NO` becomes `None`).

**Show Toolbar? :** This option allows you to tell the plugin whether to show the tool-bar (which shows the file name and the copy button) above the code boxes or not. The language name is no longer shown there — v5 printed it, 6.0 does not.

**Show copy-to-clipboard button? :** Puts a button on the toolbar that copies the snippet to the clipboard. This is what became of v5's *Show Plain Text Option?*, and your old setting carries over — the intent was always "let people get at the raw code", and copying it is a better way to do that than a second view.

**Show line numbers in code? :** This option allows you to tell the plugin whether to show the line numbers along with code in the code boxes or not. Line numbers along with code look great, are a great help when referring to some code from a code box. This option can be overridden for any code block using `gutter` attribute in the tag, or the equivalent toggle on the block.

**Normalize whitespace? :** Trims leading and trailing blank lines and evens out indentation before highlighting. **Off by default**, deliberately — if your snippet's indentation is meaningful, this will change it. Turn it on only if you know your snippets need it.

**Hilite code in comments? :** This option allows you to tell the plugin whether to highlight code posted in comments or not. If this is enabled, code posted in the comments will be highlighted as it is in the posts. Comments are not block content and there is no block editor for them, so this remains a shortcode-only feature.

**Enable GitHub Gist embed in comments? :** This option allows you to tell the plugin whether to embed Github Gist in comments or not. If disabled then a Gist posted in comments would just have a link to its page on Github.

**Uninstall :** A section at the bottom of the settings page. Its one tool converts this plugin's blocks back into `[sourcecode language="…"]` shortcodes across the entire site, so your snippets stay visible if the plugin is ever deactivated. It asks for confirmation first, because it rewrites post content and cannot be undone. Blocks whose code contains a literal `[/sourcecode]` are left alone, since they cannot be written as a shortcode without losing part of the code. See *Important changes in 6.0* above for why you would want it.

*(Gone in 6.0: **GeSHi Strict Mode?**, **Languages where GeSHi strict mode is disabled**, **Link keywords/function names to Manual?** — all three were GeSHi features with no Prism equivalent — and **Rebuild Shorthand Tags**, since there is no longer a directory of language files to scan.)*


### **Extending the plugin**

**Adding a language.** Drop a Prism component file — `prism-yourlang.js` or `prism-yourlang.min.js` — into `wp-content/uploads/igsyntax-hiliter/components/`. The directory is not created for you; make it yourself. Because it lives in uploads it survives plugin updates. The language then works with `[sourcecode language="yourlang"]` and appears in the block's language dropdown.

**Filters.**

- `ig_syntax_hiliter/languages` — filters the finished language registry, so you can add, remove or rename languages programmatically.
- `ig_syntax_hiliter/prism_components_url` — filters the URL Prism's autoloader fetches language files from, if you want to serve them from somewhere other than the plugin.
- `ig_syntax_hiliter/shortcode_tags` — filters the list of shortcode tags the plugin claims. (Claims, not registers — the plugin deliberately never calls `add_shortcode()` for these tags, it matches them itself.) Use this to bring back a tag the plugin no longer ships (see *Important changes in 6.0*), or to stop it claiming one you want for something else.
- `ig_syntax_hiliter/revert_batch_size` — filters how many posts the revert tool in the **Uninstall** section works through per request. Defaults to 20, and is clamped to between 1 and 200. Lower it on a host that times out, raise it to get through a large site in fewer requests.


### **Frequently Asked Questions**

**Q:** *My code looks all odd, characters appear as HTML entities. Why is your plugin screwing up my code?*

**A:** If you are writing your post in the classic editor's Visual (WYSIWYG) tab then that is what is messing things up for you. That tab is TinyMCE, it treats your code as HTML, and it does its damage before this plugin ever sees the content. It has never been supported. Use the block editor, or the classic editor's Text view. If you are using either of those and still see this, please report it.

**Q:** *I opened an old post in the block editor and my code blocks turned into iG:Syntax Hiliter blocks. Why?*

**A:** Because leaving them alone was worse. See *Important changes in 6.0* above for the full explanation. Short version: the block editor loads a classic post into one big TinyMCE block, TinyMCE eats code, and converting the snippets to blocks first is the only way to stop that. The conversion is only written to the post if you save it, and it leaves the rest of your post byte-for-byte as it was.

**Q:** *What happens to my code if I deactivate the plugin?*

**A:** Snippets still stored as shortcodes stay visible as `[php]…[/php]` text — ugly, but you can see them and do something about them. Snippets that have been converted to blocks render as nothing at all, because the block is no longer registered. Before deactivating, use the tool in the **Uninstall** section of the settings page to turn those blocks back into `[sourcecode]` shortcodes. A block whose code contains a literal `[/sourcecode]` cannot be converted and is left alone, so that one still needs sorting out by hand.

**Q:** *I used to add languages by putting GeSHi language files in the plugin's or my theme's `geshi` directory. What now?*

**A:** Both of those mechanisms are gone with GeSHi. Put a Prism component file for the language in `wp-content/uploads/igsyntax-hiliter/components/` instead — it survives plugin updates — and use it with `[sourcecode language="…"]` or the block. If you need the old `[yourlang]` tag itself to keep working, re-register it with the `ig_syntax_hiliter/shortcode_tags` filter.

**Q:** *I see some code that I can improve. Do you accept pull requests?*

**A:** By all means, feel free to submit a pull request.

**Q:** *I want XYZ feature. Can you implement it?*

**A:** Please feel free to suggest a new feature. Its inclusion might be speedier if you can provide the code to make it work.
