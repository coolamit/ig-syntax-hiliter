## **iG:Syntax Hiliter v5.0**
---------------------------------

**iG:Syntax Hiliter** is a WordPress plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

##### **Minimum Requirements**
- WordPress 4.1 or above
- PHP 5.3 or above

**WordPress.org:** [https://wordpress.org/plugins/igsyntax-hiliter/](https://wordpress.org/plugins/igsyntax-hiliter/)

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting  (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [GeSHi library](http://qbnz.com/highlighter/) to colourize your code and supports over a 100 programming languages. Most common languages are included with the plugin and it includes drop in support for more languages used by GeSHi.

**NOTE :** For fast results and less load on your server, you should have a cache plugin installed. That way the plugin won't have to parse the code blocks on a post every time its loaded in browser.


### **Installation**

#### **Upgrading from v4.0 or later**
Just click `update now` link below the plugin listing on the plugins page in your `wp-admin`. That's quite easy!!

#### **Upgrading from v3.x**
Just deactivate plugin in WordPress admin, delete the `syntax_hilite.php` file & `ig_syntax_hilite` directory from plugins folder and follow the installation process below. That's quite easy!!

#### **Upgrading from v2.1 or lower**
Just deactivate plugin in WordPress admin, delete the `syntax_hilite.php` and `geshi.php` files & `geshi` directory from plugins folder and follow the installation process below. That's quite easy!!

#### **Installing The Plugin**
1. Login to your WordPress `wp-admin` area.
2. Click `Add New` in the `Plugins` menu on left.
3. Enter `iG:Syntax Hiliter` in the search bar on the right on the page that opens and press Enter key.
4. WordPress would show the **iG:Syntax Hiliter** plugin with install button, click that to install the plugin.
5. Click on `Activate Plugin` link on the page that opens after the plugin has been installed successfully.

**See Also:** ["Installing Plugins" article on the WP Codex](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins)


### **Plugin Usage**

Using this syntax highlighter is fairly easy. There is one tag and 8 optional attributes. Here's how code is posted for it to be highlighted.

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

**Important :** Do not forget to close the tags, as your code will not be hilited if you don't close your tags. Also, *don't nest tags*. Nesting of tags don't work, so don't try it, it'll ruin your output.

Also a WYSIWYG editor (like the one bundled with WordPress) will probably mess up your code when you paste it in the editor. So if you are having that issue, then please don't report it as a bug. WYSIWYG editors are just not supported at present.

#### **(Optional) Plugin Attributes**

**language :** Use this to specify the programming language whose code you are posting. This language has to be present in `geshi` directory inside plugin directory. If `language` attribute is not specified or if a non-existent language is specified in it then a generic code box is rendered. `lang` is the shorthand for `language` attribute.

**firstline :** Use this to start line numbering from a number greater than 1.

**highlight :** Use this to tell plugin which lines are to be marked as different for emphasis. Line numbers are actual line numbers of code and have no relation to the ones starting as per `firstline` attribute. It accepts a comma separated list of line numbers and line number ranges like 5-8 which is equal to 5,6,7,8

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

**file :** Use this to show a file name/path. This is displayed in the tool-bar shown above code box.

**gutter :** Use this to tell plugin whether to show line numbers in the code box or not. It accepts either `yes` or `no`. This, if specified, will override the global option to show line numbers for that particular code box.

**plaintext :** Use this to tell plugin whether to show plain text option for the code box or not. It accepts either `yes` or `no`. This, if specified, will override the global option to show plain text option for that particular code box.

**toolbar :** Use this to tell plugin whether to show tool-bar for the code box or not. It accepts either `yes` or `no`. This, if specified, will override the global option to show toolbar for that particular code box.

**strict_mode :** Use this to tell the plugin to use GeSHi Strict Mode for a particular code box or not. This attribute accepts `always` or `never` or `maybe` as value. If you don't know what this means then its better to ignore this attribute and let it remain default.


### **Configuration**

Configuring **iG:Syntax Hiliter** is a piece of cake. Login to your WordPress admin section & under the `Settings` menu you'll see `iG:Syntax Hiliter` in the sub-menu.

When you click the `iG:Syntax Hiliter` configuration page, you are offered 10 configuration settings which you can set to your liking. Lets go through each of them.

**Use plugin CSS for styling? :** This option allows you to tell the plugin whether it should use its own CSS for styling the code box (not the hilited code, just code box) or not. If you want to use your own styling for the code box, tool-bar etc then you can set it to `NO`. By default its set to `YES`.

**GeSHi Strict Mode? :** This option allows you to tell the plugin the [strict mode](http://qbnz.com/highlighter/geshi-doc.html#using-strict-mode) setting to use with GeSHi. Strict mode can be set to be always on or off or you can set it to `MAYBE` to have GeSHi decide on its own using the language file of the language whose code you're hiliting. *If you don't have any clue about this then leave it at default setting.* By default its set to `MAYBE`. This option can be overridden for any code block using `strict_mode` attribute in the tag.

**Languages where GeSHi strict mode is disabled :** This option lets you specify a comma separated list of languages where the GeSHi strict mode should always be disabled. Strict mode is disabled for PHP by default.

**Show Toolbar? :** This option allows you to tell the plugin whether to show the tool-bar (which shows plain text option, file name, language name) above the code boxes or not. This option can be overridden for any code block using `toolbar` attribute in the tag.

**Show Plain Text Option? :** This option allows you to tell the plugin whether to show the *Plain Text* view option on the code boxes or not. This option can be overridden for any code block using `plaintext` attribute in the tag.

**Show line numbers in code? :** This option allows you to tell the plugin whether to show the line numbers along with code in the code boxes or not. Line numbers along with code look great, are a great help when referring to some code from a code box. This option can be overridden for any code block using `gutter` attribute in the tag.

**Hilite code in comments? :** This option allows you to tell the plugin whether to hilite code posted in comments or not. If this is enabled, code posted in the comments will be hilited as it is in the posts.

**Link keywords/function names to Manual? :** This option allows you to tell the plugin whether to link keywords, function names etc to that language's online manual or not. This works only if this feature is enabled for that particular language in GeSHi language file.

**Enable GitHub Gist embed in comments? :** This option allows you to tell the plugin whether to embed Github Gist in comments or not. If disabled then a Gist posted in comments would just have a link to its page on Github.

**Rebuild Shorthand Tags :** Language files in the plugin's directory and current theme (parent & child) directory are scanned and their names are cached to allow shorthand tag usage for all languages. This cache is rebuilt automatically every week. But if you wish to rebuild it manually you can do so by clicking this button.


### **Frequently Asked Questions**

**Q:** *My code looks all odd, characters appear as HTML entities. Why is your plugin screwing up my code?*

**A:** If you are using the WYSIWYG (rich text) editor to compose your post then that is what's messing things up for you. iG:Syntax Hiliter does not support composing posts and posting code using WYSIWYG at present. If you are not using WYSIWYG editor and still have same issue, please report it.

**Q:** *I see some code that I can improve. Do you accept pull requests?*

**A:** By all means, feel free to submit a pull request.

**Q:** *I want XYZ feature. Can you implement it?*

**A:** Please feel free to suggest a new feature. Its inclusion might be speedier if you can provide the code to make it work.


### **Changelog**

##### **v5.0**

* Minimum required PHP version bumped to 5.3.0. The plugin simply won't load its code on lower versions.
* Major re-write of plugin for cleaner, modular & better performing code.
* NEW: You can now disable plugin stylesheet which styles code boxes. People who have their own styling don't need it anyway.
* NEW: 2 new options allow more control on GeSHi behaviour.
* BUGFIX: Language name cache was not re-building automatically.

##### **v4.3**

* BUGFIX: some language file names got snipped when building language name cache

##### **v4.2**

* BUGFIX: Shorthand tags for all languages supported now - props to Karol Kuczmarski for spotting it
* NEW: Added C++ language file

##### **v4.1**

* BUGFIX: Github Gist URL XSS security hole
* BUGFIX: `__dir__` doesn't work below PHP 5.3 - props to Karol Kuczmarski for spotting it
* NEW: Added "lang" as shorthand for "language" attribute
* NEW: Additional GeSHi language files can be put in "geshi" directory in theme, which will prevent their deletion on plugin upgrade
* IMPROVED: If a code block is repeated with same attributes then its parsed only once and output is reused

##### **v4.0**

* NEW: Ability to embed Github Gist in post and comments (configurable)
* NEW: Ability to highlight one or multiple lines in a code block to show them as different
* NEW: New code box layout
* NEW: Ability to escape plugin tags to prevent their processing
* NEW: New GeSHi core (v 1.0.8.11)
* IMPROVED: Removed quirks from plain text view & its now much more smoother
* IMPROVED: Handling of how code is prevented from beautification. The rest of the post/comment text is not affected as wptexturize is not removed anymore.
* IMPROVED: Simpler and faster options page in wp-admin

##### **v3.5**

* BUGFIX: BB Tags except the ones of iG:Syntax Hiliter are allowed. The language file's existence is checked before parsing the code. If the language file does not exist then the code is not parsed.
* BUGFIX: 'C' code hiliting is now fixed.
* BUGFIX: 'Plain Text' has been improved to strip the extra blank lines and spaces in Opera and FireFox.
* The latest stable GeSHi core(v1.0.7.6).
* NEW: Code Hiliting for Comments has been implemented. This feature can be Enabled/Disabled from the admin interface for iG:Syntax Hiliter. The tags are same for hiliting the code.
* NEW: A cross-browser Colour Picker(tested in IE6, FireFox1.5 and Opera8.5) is now available to easily set the line colours displayed in the code box.
* NEW: A new type of view implemented for seeing "Plain Text" code. Besides opening the plain text code in a new window, you can have it displayed in the code box itself with an option to display the hilited HTML code back again. The "Plain Text" view type can be set in the admin interface.
* The language file for Ruby that I created a while back is now bundled with the plugin and its also a part of the default GeSHi package.

##### **v3.1**

* BUGFIX: Critical bug, which broke the plugin when the square brackets([ & ]) were used in the posts in places other than tags, has been fixed.
* BUGFIX: Another bug, which allowed any attribute in the tags besides the 'num' and also allowed any attribute value for it, affecting the processing. Now only the 'num' attribute is accepted and if you specify the 'num' attribute then its value must be a positive number otherwise your code won't be hilited. The 'num' attribute is optional and you can leave it out without any problems.
* BUGFIX: Fixed the unclosed <select> tags in the Plugin GUI code.
* GeSHi BUGFIX: Fixed a bug in GeSHi where the first line colour was not used when using FANCY LINE NUMBERS thus resulting in just one colour being used for the alternate lines.
* There's a problem in WordPress due to which the starting delimiters of ASP, PHP were not displayed correctly, as whitespace was inserted between the '<' and the rest of the delimiter. This has been patched so that its displayed correctly, but its not saved in the database, so the database still contains the delimiters as formatted by WordPress.

##### **v3.0**

* Complete re-write of the plugin resulting in reduction of code from 750+ lines to about 400 Lines.
* New GeSHi Core(v1.0.7) which has some bug-fixes, please see GeSHi Website for its changelog.
* New languages added are C#, Delphi, Smarty & VB.NET.
* ASP language file structure updated & more keywords added.
* Drag-n-Drop usage of new languages. The plugin now supports all languages that GeSHi(v1.0.7) supports. You just need to drop the language file in the "geshi" directory & use the filename as the tag for the language(like if file is "pascal.php", then the filename is "pascal" & the tags will be [pascal] & [/pascal]).
* Language name which is displayed in the Code-Box can now be turned ON or OFF easily.
* No more need to set the physical-path to the "geshi" directory if you are doing a default installation.
* Plain-Text View of the code hilited in the code-box is now possible. This feature can be enabled/disabled easily in the Configuration Interface in WordPress Administration.
* NO NEED TO EDIT THE PLUGIN FILE ANYMORE. You can now configure the plugin settings from a GUI located under the OPTIONS menu in your WordPress Administration(WordPress 1.5 & above only).

##### **v2.01**

* BUGFIX: Fixed a bug by removing a <br /> tag from the function pFix() which lead to closing of an unnecessary <p> tag making the code not xHTML valid(as per my desires).

##### **v2.0 Final**

* Implemented the new version of GeSHi core, v1.0.2 which has some bug fixes & which uses OL(Ordered Lists) for Line Numbering and supports starting of a Line Number from any given number.
* The ASP(Active Server Pages) language file has been updated to the new Language File structure of GeSHi as well as more keywords added & hiliting is more effective now.
* iG:Syntax Hiliter now also supports ActionScript, C, C++, JavaScript, Perl, Python, Visual Basic & XML.
* The whole plugin has been re-written & all the hiliting code is now in a class. You can just use the class anywhere else too for hiliting the code. But to also use the Code Tags to wrap your code & then hilite them, you will need to use all other functions. You can remove the WordPress Filter calls at the end of the plugin & use the rest of the code as you want somewhere else.
* BUGFIX: The issue of multi-line comments not being hilited properly in v2.0 Preview has been sorted out.

##### **v2.0 Preview**

* Implemented the new version of GeSHi core, v1.0.1 which has some bug fixes including the extra quote(") bug that broke the xHTML validation of the code.
* I've created a new language file for ASP(Active Server Pages) which has been added to this release & will also be a part of the next GeSHi release.
* Line numbering is now done through Ordered Lists(<OL>) & the code is xHTML compliant.
* Auto-Formatting disabled for posts that contain the iG:Syntax Hiliter code tags so that your code is good for copy-paste operations.

##### **v1.1**

* Implemented the line numbering of code.
* The code box is now of fixed dimensions without word-wrap & with scrollbars(if required).

##### **v1.0**

* Hilites code between the special tags, all of them differently.
* Uses GeSHi for syntax hiliting.
* Supports HTML, CSS, PHP, JAVA & SQL codes.
