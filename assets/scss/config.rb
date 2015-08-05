# Compass is a great cross-platform tool for compiling SASS.
# This compass config file will allow you to
# quickly dive right in.
# For more info about compass + SASS: http://net.tutsplus.com/tutorials/html-css-techniques/using-compass-and-sass-for-css-in-your-next-project/

#########
# 1. Set this to the root of your project when deployed:
http_path = "/"

# 2. probably don't need to touch these
css_dir = "../css"
sass_dir = "./"
images_dir = "../images"
javascripts_dir = "../js"
fonts_dir = "../fonts"
relative_assets = true

# 3. The environment determines the output_style, line_comments and sass_options
#    debug_info must be true for FireSASS to work.
#    Development - compile with: compass compile -e development --force
#    Production - compile with: compass compile -e production --force
output_style = (environment == :production) ? :compressed : :nested
line_comments = (environment == :production) ? false : true
sass_options = (environment == :production) ? {:debug_info=>false, :sourcemap=>false, :unix_newlines=>true} : {:debug_info=>true, :sourcemap=>true, :unix_newlines=>true}


# dont append random parameters to image urls in css to bust cache
# http://compass-style.org/help/tutorials/configuration-reference/#asset-cache-buster
asset_cache_buster :none

# don't touch this
preferred_syntax = :scss

# EOF