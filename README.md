# libvips adapter for Imagine
 
This package provides a [libvips](https://jcupitt.github.io/libvips/) integration for [Imagine](https://imagine.readthedocs.io/en/latest/). The [VIPS image processing system](https://jcupitt.github.io/libvips/) is a very fast, multi-threaded image processing library with low memory needs.

The as of mid november 2017 not yet released version 8.6 of libvips is recommended for some functions. But 8.5 works for most too.
 
You also need the [php-vips-ext](https://github.com/jcupitt/php-vips-ext) extension version 1.0.8 or higher (you need to install that) and the [php-vips](https://github.com/jcupitt/php-vips) classes (automatically installed by composer)

The most (to us at least) important stuff is implemented. There may be edge cases, which are not covered yet, but those will be hopefully fixed soon. Report them, if you encounter one.

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

Needs upcoming vips 8.6:

* paste
* rotate by angles other than multipliers of 90

Not implemented yet
 
 * Layers - Just the first layer is loaded (see below for more info)
 * Font
 * Drawer
 * Methods:
   * fill
   * histogram
 * Filters:
   * colorize

Most of them are not that important to us, so any contributions are welcome. Drawer for example may be a low hanging fruit, if you want to get into it.
  
### Layers

Currently, only the first frame is loaded and available for image manipulation. 

There's a layers support in the works in  [layers-support](https://github.com/rokka-io/imagine-vips/tree/layers-support) branch. It loads an animated gif into the different layers. More work needs to be done. See also [this issue](https://github.com/rokka-io/imagine-vips/issues/1).


## Saving files

Natively supported by libvips for saving are jpg, png and webp. For the rest this adapter falls back to the Imagick or GD implementation.

## Contribution

Any contribution is very appreciated, just file an issue or send a Pull Request.
 
 