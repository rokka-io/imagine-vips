# CHANGELOG

### 0.31.0 (2022-10-12)
  * Make it compatible with jcupitt/vips 2.1 (and disable support for 2.0, 1.0.x is still supported). See https://github.com/libvips/php-vips/pull/147 for details.
  * Add more phpstan tests for all the combinations
  * Replace vips_version with Jcupitt\Vips\Config::version() for better support, when vips-ext is not available
  * Add some simple PHPUnit tests

### 0.30.1 (2022-10-04)
  * Fix issue with PHP < 8.0

### 0.30.0 (2022-10-04)
  * Add `force_magick` for using magicksave insteaf of gifsave
  * Make it run on PHP 8.1 without warnings
  * Fix some other type issues
  * BC break, if you extended \Imagine\Vips\Layers. You need to add some return types now

### 0.20.0 (2022-04-29)
  * Uses the new FFI based libvips/php-vips (2.0) library, if FFI is installed. 
    If FFI is not installed, still uses the old library, which needs the 
    libvips/php-vips-ext extension.

### 0.14.0 (2021-12-09)
  * Add Drawer::text() support
  * Add JPEG-XL (jxl) support (needs libvips 8.11 with builtin support)
  * Remove support for PHP 7.0, minimum is now PHP 7.1
  * Use gifsave, when vips 8.12 is installed (needs the cgif library)
  * Fixed two bugs when converting pixel to color (thanks to @chmgr #21)

### 0.13.0 (2021-02-22)
  * Add PHP 8 compatibility
  * Add Avif support
  * Strip metadata in Heif

### 0.12.0 (2020-07-14)
  * Improve color profile handling. Always transform them.

### 0.11.0 (2020-02-17)
  * Fix gif delay for vips versions < 8.9
  * Add webp_reduction_effort save option. Default is 4, max is 6.

### 0.10.1 (2020-01-14)

  * Fix some issues when adding new frames to layers.
  * Throw an NotSupportedException when trying to unset a layer as not yet supported.
  
### 0.10.0 (2020-01-14)

  * Improved handling of animated gifs and webp
  * Possibility to define delay per frame with vips 8.9 (`Layers::setDelay($index, $delay)` et al.) 

### 0.9.2 (2020-01-09)

  * Improved coalesce for animated gifs
  * Added Image::isOpaque($vips)

### 0.9.1 (2020-01-08)

  * Throw a proper NotSupportedException (thanks to @alexander-schranz) 
  * Get rid of some warnings when using vips 8.9.
  * Autorotate HEIF images on load.
  * Support animated gif save to file.
  * Throw an early error, if magicksave can't save an image.
  * Remove 'shrink' options, when not supported by a vips loader.

### 0.9.0 (2019-03-07)

  * BREAKING CHANGE: Based on imagine 1.1.0
  * Added support for layers. Animated GIFs should now work without imagick, but needs vips 8.7.
  * Add support for 'heif_quality' (only useful if your imagemagick or vips 8.8 supports heif).
  * Add support for 'jp2_quality' (only useful if your imagemagick supports jpeg2000).
  * Add support for 'png_quality' to define quality of pngquant (only useful if vips is compiled with libimagequant).
    If set to 100, no lossy conversion is applied (default).
  * Add support for magicksave. If you have vips >= 8.7 and imagemagick is included, we now 
    directly use magicksave to save non-supported-by-vips file formats. No need to convert it to an imagick 
    object first, resulting in much better performance. 
  * Add possibility for individual vips save options 
  * Replace colorprofile with free ones.
  * Support for animated webp, needs vips 8.8.

### 0.1.0 (2017-12-06)

  * Add 2nd optional parameter to `Image::convertToAlternative` to provide your own options for loading the image as tiff. 

### 0.0.5 (2017-12-03)

  * ext/vips 1.0.8 is required. Throw exceptions in methods, which needs vips 8.6.
  * Add constructor config array to be able to set `vips_cache_set_max_mem` et al.
  * Fix paste method to work with future php-vips versions
  * Added php-cs-fixer

### 0.0.4 (2017-11-27)

  * Fix some operations on grayscale images
  * Convert CMYK to sRGB early on
  * Convert CMYK to sRGB before save, in case we still have a CMYK picture
  * Fix colorprofile for GREY16 pictures
  * Fix `Image::paste` to make it faster, when you have many pastes.

### 0.0.3 (2017-11-21)
  * Fix conversion from cmyk to rgb, when no profile is supplied
  * Add `convertToAlternative(ImagineInterface $imagine = null)` to convert the image to 
     another imagine adapter. Uses Imagick or GD, if none set.
  * Fix resize for some format changes
  * Fix grayscale for cmyk
  * Fix negative effect for images with transparency
  
### 0.0.2 (2017-11-20)
  * Improve Profile/Palette/ICC support
  * Improve `generateImage` to make it faster, thanks to jcupitt

### 0.0.1 (2017-11-19)
  * Initial release