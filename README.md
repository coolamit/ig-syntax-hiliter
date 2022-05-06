## **iG:Syntax Hiliter v5.1**
---------------------------------

**iG:Syntax Hiliter** is a WordPress plugin to easily present source code on your site with syntax highlighting and formatting  (as seen in code editors, IDEs).

##### **Minimum Requirements**
- WordPress 5.4 or above
- PHP 7.4.0 or above

**WordPress.org:** [https://wordpress.org/plugins/igsyntax-hiliter/](https://wordpress.org/plugins/igsyntax-hiliter/)

**iG:Syntax Hiliter** allows you to post source code to your site with syntax highlighting and formatting  (as seen in code editors, IDEs). You can paste the code as is from your code editor or IDE and this plugin will take care of all the code colouring and preserve your formatting. It uses the [GeSHi library](http://qbnz.com/highlighter/) to colourise your code and supports over a 100 programming languages. Most common languages are included with the plugin and it includes drop in support for more languages used by GeSHi.

**NOTE :** For fast results and less load on your server, you should have a cache plugin installed. That way the plugin won't have to parse the code blocks on a post every time it is loaded in browser.

### **[Changelog](CHANGELOG.md)**

### **Installation**

#### **Upgrading from v4.0 or later**
Just click `update now` link below the plugin listing on the plugins page in your `wp-admin`. The plugin will handle any settings migration if needed. Easy peasy!!

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

**Important :** Do not forget to close the tags, as your code will not be highlighted if you don't close your tags. Also, *don't nest tags*. Nesting of tags don't work, so don't try it, it'll ruin your output.

**Important :** A WYSIWYG editor (like the one bundled with WordPress) will likely mess up your code when you paste it in the editor. If you are having that issue, then please don't report it as a bug. WYSIWYG editors are just not supported at present.

***Similarly, Gutenberg Editor is not supported at present either.***

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

When you click the `iG:Syntax Hiliter` configuration page, you are offered some configuration settings which you can set to your liking. Lets go through each of them.

**Use plugin CSS for styling? :** This option allows you to tell the plugin whether it should use its own CSS for styling the code box (not the highlighted code, just code box) or not. If you want to use your own styling for the code box, tool-bar etc then you can set it to `NO`. By default its set to `YES`.

**GeSHi Strict Mode? :** This option allows you to tell the plugin the [strict mode](http://qbnz.com/highlighter/geshi-doc.html#using-strict-mode) setting to use with GeSHi. Strict mode can be set to be always on or off or you can set it to `MAYBE` to have GeSHi decide on its own using the language file of the language whose code you're highlighting. *If you don't have any clue about this then leave it at default setting.* By default its set to `MAYBE`. This option can be overridden for any code block using `strict_mode` attribute in the tag.

**Languages where GeSHi strict mode is disabled :** This option lets you specify a comma separated list of languages where the GeSHi strict mode should always be disabled. Strict mode is disabled for PHP by default.

**Show Toolbar? :** This option allows you to tell the plugin whether to show the tool-bar (which shows plain text option, file name, language name) above the code boxes or not. This option can be overridden for any code block using `toolbar` attribute in the tag.

**Show Plain Text Option? :** This option allows you to tell the plugin whether to show the *Plain Text* view option on the code boxes or not. This option can be overridden for any code block using `plaintext` attribute in the tag.

**Show line numbers in code? :** This option allows you to tell the plugin whether to show the line numbers along with code in the code boxes or not. Line numbers along with code look great, are a great help when referring to some code from a code box. This option can be overridden for any code block using `gutter` attribute in the tag.

**Hilite code in comments? :** This option allows you to tell the plugin whether to highlight code posted in comments or not. If this is enabled, code posted in the comments will be highlighted as it is in the posts.

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
