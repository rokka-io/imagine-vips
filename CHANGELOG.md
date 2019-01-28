# CHANGELOG

### 0.9.0 (unreleased) 

  * BREAKING CHANGE: Based on imagine 1.1.0
  * Added support for layers. Animated GIFs should now work without imagick, but needs vips 8.7.
  * Add support for 'heif_quality' (only useful if your imagemagick supports heif).
  * Add support for 'jp2_quality' (only useful if your imagemagick supports jpeg2000).
  * Add support for 'png_quality' to define quality of pngquant (only useful if vips is compiled with libimagequant).
    If set to 100, no lossy conversion is applied (default).
  * Add support for magicksave. If you have vips >= 8.7 and imagemagick is included, we now 
    directly use magicksave to save non-supported-by-vips file formats. No need to convert it to an imagick 
    object first, resulting in much better performance. 
  * Add possibility for individual vips save options 
  * Replace colorprofile with free ones.

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