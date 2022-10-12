# libvips adapter for Imagine

[![Static analysis](https://github.com/rokka-io/imagine-vips/actions/workflows/tests.yml/badge.svg)](https://github.com/rokka-io/imagine-vips/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/rokka/imagine-vips/version.png)](https://packagist.org/packages/rokka/imagine-vips)

 
This package provides a [libvips](https://github.com/libvips/libvips) integration for [Imagine](https://imagine.readthedocs.io/en/latest/). The [VIPS image processing system](https://libvips.github.io/libvips/) is a very fast, multi-threaded image processing library with low memory needs.

Version 8.7 or higher of libvips is highly recommended. `paste` and `rotate` by angles other than multipliers of 90 are not supported with older versions of libvips.
 
You either need the [PHP FFI](https://www.php.net/manual/en/book.ffi.php) extension (recommended, since that's the currently supported way by the libvips maintainer) or the 
[php-vips-ext](https://github.com/libvips/php-vips-ext) extension version 1.0.8 or higher (you need to install that manually). 
And the [php-vips](https://github.com/libvips/php-vips) classes (automatically installed by composer)


The most (to us at least) important stuff is implemented. There may be edge cases, which are not covered yet, but those will be hopefully fixed soon. Report them, if you encounter one.

Even it this is not a 1.0.0 release yet, the library is somehow battle tested as we use it on [rokka.io](https://rokka.io).

## Installation
 
Just run the following
 
```
composer require rokka/imagine-vips
```
 
 and then you can use it like any other Imagine implementation with eg.
 
```
$imagine = new \Imagine\Vips\Imagine();

$size    = new Imagine\Image\Box(40, 40);
$mode    = Imagine\Image\ImageInterface::THUMBNAIL_INSET;

$imagine->open('/path/to/large_image.jpg')
    ->thumbnail($size, $mode)
    ->save('/path/to/thumbnail.png')
```
 
## Missing stuff

Needs vips 8.6 or higher:

* paste
* rotate by angles other than multipliers of 90

Not implemented yet
 
 * Complete Drawer support, only text is. 
 * Methods:
   * fill
   * histogram
 * Filters:
   * colorize

Most of them are not that important to us, so any contributions are welcome. Drawer for example may be a low hanging fruit, if you want to get into it.
  
### Layers and Animated gifs

If you have vips 8.7.0, layers and animated gifs should work like with imagick. 

## Saving files

Natively supported by libvips for saving are jpg, png, webp and tiff. If you have vips 8.7.0 with imagemagick support, it will use vips "[magicksave](https://libvips.github.io/libvips/API/current/VipsForeignSave.html#vips-magicksave)" for all other formats. It not, this adapter falls back to the Imagick or GD implementation.

## Contribution

Any contribution is very appreciated, just file an issue or send a Pull Request.
 
 
