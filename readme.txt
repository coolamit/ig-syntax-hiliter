=== iG:Syntax Hiliter ===
Contributors: amit
Tags: syntax highlighter, code highlighter, code, source code, php, mysql, html, css, javascript
Requires at least: 3.3
Tested up to: 3.5.1
Stable tag: trunk
License: GPLv2

A plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

== Description ==

iG:Syntax Hiliter allows you to post source code to your site with syntax highlighting and formatting  (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [GeSHi library](http://qbnz.com/highlighter/) to colourize your code and supports over a 100 programming languages. Most common languages are included with the plugin and it includes drop in support for more languages used by GeSHi.

Github: https://github.com/coolamit/ig-syntax-hiliter

WordPress.org plugin repo: http://plugins.svn.wordpress.org/igsyntax-hiliter/


== Installation ==

###UPGRADING from v3.x###

Just deactivate plugin in WordPress admin, delete the syntax_hilite.php file & "ig-syntax-hiliter" directory from plugins folder and follow the installation process below. That's quite easy!!

###UPGRADING from v2.1 or lower###

Just deactivate plugin in WordPress admin, delete the syntax_hilite.php and geshi.php files & "geshi" directory from plugins folder and follow the installation process below. That's quite easy!!

###Installing The Plugin###

Extract all files from the zip file and then upload it to `/wp-content/plugins/`. **Make sure to keep the file/folder structure intact**

Go to WordPress admin section, click on "Plugins" in the menu bar and then click "Activate" link under "iG:Syntax Hiliter".

**See Also:** ["Installing Plugins" article on the WP Codex](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins)

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

None yet!

== Screenshots ==

1. Settings page of the plugin where default options can be set for plugin.
2. Example display of syntax highlighted PHP code.
3. Example display of plain text view of some PHP code.

== ChangeLog ==

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
* NEW: The latest stable GeSHi core(v1.0.7.6).
* NEW: Code Hiliting for Comments has been implemented. This feature can be Enabled/Disabled from the admin interface for iG:Syntax Hiliter. The tags are same for hiliting the code.
* NEW: A cross-browser Colour Picker(tested in IE6, FireFox1.5 and Opera8.5) is now available to easily set the line colours displayed in the code box.
* NEW: A new type of view implemented for seeing "Plain Text" code. Besides opening the plain text code in a new window, you can have it displayed in the code box itself with an option to display the hilited HTML code back again. The "Plain Text" view type can be set in the admin interface.
* NEW: The language file for Ruby that I created a while back is now bundled with the plugin and its also a part of the default GeSHi package.

= v3.1 =

* BUGFIX:- Critical bug, which broke the plugin when the square brackets([ & ]) were used in the posts in places other than tags, has been fixed.
* BUGFIX:- Another bug, which allowed any attribute in the tags besides the 'num' and also allowed any attribute value for it, affecting the processing. Now only the 'num' attribute is accepted and if you specify the 'num' attribute then its value must be a positive number otherwise your code won't be hilited. The 'num' attribute is optional and you can leave it out without any problems.
* BUGFIX:- Fixed the unclosed <select> tags in the Plugin GUI code.
* GeSHi BUGFIX:- Fixed a bug in GeSHi where the first line colour was not used when using FANCY LINE NUMBERS thus resulting in just one colour being used for the alternate lines.
* There's a problem in WordPress due to which the starting delimiters of ASP, PHP were not displayed correctly, as whitespace was inserted between the '<' and the rest of the delimiter. This has been patched so that its displayed correctly, but its not saved in the database, so the database still contains the delimiters as formatted by WordPress.

= v3.0 =

* NEW LICENSE:-- iG:Syntax Hiliter is now licensed under GNU GPL.
* New GeSHi Core(v1.0.7) which has some bug-fixes, please see GeSHi Website for its changelog.
* New languages added are C#, Delphi, Smarty & VB.NET.
* ASP language file structure updated & more keywords added.
* Drag-n-Drop usage of new languages. The plugin now supports all languages that GeSHi(v1.0.7) supports. You just need to drop the language file in the "geshi" directory & use the filename as the tag for the language(like if file is "pascal.php", then the filename is "pascal" & the tags will be [pascal] & [/pascal]).
* Language name which is displayed in the Code-Box can now be turned ON or OFF easily.
* No more need to set the physical-path to the "geshi" directory if you are doing a default installation.
* Plain-Text View of the code hilited in the code-box is now possible. This feature can be enabled/disabled easily in the Configuration Interface in WordPress Administration.
* NO NEED TO EDIT THE PLUGIN FILE ANYMORE. You can now configure the plugin settings from a GUI located under the OPTIONS menu in your WordPress Administration(WordPress 1.5 & above only).


Changelog of versions prior to v3.0 is available in the [manual](http://plugins.svn.wordpress.org/igsyntax-hiliter/trunk/MANUAL.txt).




