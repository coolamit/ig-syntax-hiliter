=== iG:Syntax Hiliter ===
Contributors: amit
Tags: syntax highlighter, code highlighter, code, source code, php, mysql, html, css, javascript
Requires at least: 3.3
Tested up to: 3.5.1
Stable tag: trunk
License: GPLv2

A plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

== Description ==

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting  (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [GeSHi library](http://qbnz.com/highlighter/) to colourize your code and supports over a 100 programming languages. Most common languages are included with the plugin and it includes drop in support for more languages used by GeSHi.

Github: https://github.com/coolamit/ig-syntax-hiliter

WordPress.org plugin repo: http://plugins.svn.wordpress.org/igsyntax-hiliter/

== Installation ==

###UPGRADING from v4.0 or later###

Just deactivate plugin in WordPress admin, delete the "ig-syntax-hiliter" directory from plugins folder and follow the INSTALLATION process again. That's quite easy!!

###UPGRADING from v3.x###

Just deactivate plugin in WordPress admin, delete the syntax_hilite.php file & "ig_syntax_hilite" directory from plugins folder and follow the installation process below. That's quite easy!!

###UPGRADING from v2.1 or lower###

Just deactivate plugin in WordPress admin, delete the syntax_hilite.php and geshi.php files & "geshi" directory from plugins folder and follow the installation process below. That's quite easy!!

###Installing The Plugin###

Extract all files from the zip file and then upload it to `/wp-content/plugins/`. **Make sure to keep the file/folder structure intact.**

Go to WordPress admin section, click on "Plugins" in the menu bar and then click "Activate" link under "iG:Syntax Hiliter".

**See Also:** ["Installing Plugins" article on the WP Codex](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins)

== Other Notes ==

###Plugin Usage###

Using this syntax highlighter is fairly easy. There is one tag and seven optional attributes. Here's how code is posted for it to be highlighted.

`[sourcecode language="language_name"]
//some code here
[/sourcecode]`

So if you are posting some PHP code then it would be

`[sourcecode language="php"]
//some code here
[/sourcecode]`

or you can use shorthand tags like

`[php]
//some code here
[/php]`


HTML entities need not be escaped, you can post your code as is and the plugin takes care of it all.

For detailed usage instructions and usage of different available attributes and plugin options, please [read the manual](http://plugins.svn.wordpress.org/igsyntax-hiliter/trunk/MANUAL.txt).

== Frequently Asked Questions ==

= My code looks all odd, characters appear as HTML entities. Why is your plugin screwing up my code? =

If you are using the WYSIWYG (rich text) editor to compose your post then that is what's messing things up for you. iG:Syntax Hiliter does not support composing posts and posting code using WYSIWYG at present. If you are not using WYSIWYG editor and still have same issue, please report it.

= I see some code that I can improve. Do you accept pull requests? =

By all means, feel free to submit a pull request.

= I want XYZ feature. Can you implement it? =

Please feel free to suggest a new feature. Its inclusion might be speedier if you can provide the code to make it work.

== Screenshots ==

1. Settings page of the plugin where default options can be set for plugin
2. Example display of syntax highlighted PHP code
3. Example display of plain text view of some PHP code

== ChangeLog ==

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


Changelog of versions prior to v4.0 is available in the [manual](http://plugins.svn.wordpress.org/igsyntax-hiliter/trunk/MANUAL.txt).

== Upgrade Notice ==

= 4.2 =
This version fixes the bug due to which shorthand tags for all included languages and user added languages did not work.




