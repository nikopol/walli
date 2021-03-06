WALLi v0.8 (c) niko 2012
------------------------
"all-in-one" Images Wall

the purpose of this project is to provide an *all-in-one* php file, without any 
configuration required (but optional), into a directory containing sub-dirs 
and/or images to "browse" them with a modern browser or mobile device.

**FEATURES**

  - all-in-one single file
  - support chrome, firefox, IE9+, IOS, Android
  - thumbnail
  - multi themes (show customization below)
  - multi subs directories
  - auto locale supported (en & fr for the moment)
  - comments by image (optional)
  - slideshow mode by directory
  - download a zip of selected images (optional)
  - permalink support
  - google analytics support (optional)
  - mini admin included (upload/delete images,stats,mkdir,cache control) (optional)
  - keyboard shortcuts (h or ? for help)
  - exif infos (if php exif support is enabled)

**LOULOU THEME SCREENSHOTS**

![screenshot](https://github.com/nikopol/walli/blob/master/screenshots/loulou-thumb.png?raw=true "thumbnail in loulou theme")
![screenshot](https://github.com/nikopol/walli/blob/master/screenshots/loulou-zoom.png?raw=true "zoom with comments panel in loulou theme")

**BLACK THEME SCREENSHOTS**

![screenshot](https://github.com/nikopol/walli/blob/master/screenshots/black-thumb.png?raw=true "thumbnail in black theme")
![screenshot](https://github.com/nikopol/walli/blob/master/screenshots/black-zoom.png?raw=true "zoom with comments panel in black theme")


**INSTALL**

select the index-***.php you prefer (each one provide a different look/css), 
put it an http served directory, rename it index.php, and that's it.
  
you can also edit some parameters in your index.php, such as the page title, 
the cache directory, etc.  
  
you can create a cache directory ( /same_dir_as_your_index.php/.cache by 
default ) and give your http server write right on it to enable cache and 
comments.

**REQUIRED**

a http server supporting php with gd enabled.
if you want enable photos download in a zip, you must have the zip support.
if you want enable comments, you must have the json support.

	#on ubuntu
	sudo aptitude install php5 libphp-pclzip php5-gd php5-json

	#you can also enable exif support in your php.ini


if you want customize, you'll need some perl modules (used to minify & concat
files) :
  - JSON
  - LWP::UserAgent
  - MIME::Base64

install:

	#on ubuntu
	sudo aptitude install libjson-perl libmime-base64-perl libwww-perl

	#or with cpan
	sudo cpan JSON LWP::UserAgent MIME::Base64

**CUSTOMIZATION**

in src/ you'll find what you need to build your own
index-*.php.

steps:

	git clone git://github.com/nikopol/walli.git

	#copy your most nearest css to have a start
	cd walli/src/css/themes
	cp black.css yours.css
	
	#setup your dev/test environment
	#  return in walli/src/css
	cd ..
	ln -sf themes/yours.css walli.css

	#configure your http server to handle walli/ directory
	#test it via http://localhost/path/to/walli/src/walli.php
	#when it's done, to minify it
	#  return walli/src
	cd ..
	bin/minify < walli.php > ../index-yours.php
	#or
	bin/minify-all

if you do, don't hesitate to pull request your themes/improvments =)

**CREDITS**

  * admin icons from http://gemicon.net
  * favicon from http://tango.freedesktop.org
