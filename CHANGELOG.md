# CHANGELOG

### 0.0.4 (unreleased)

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