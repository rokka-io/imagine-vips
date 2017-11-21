# libvips adapter for Imagine
 
This package provides a [libvips](https://github.com/libvips/libvips) integration for [Imagine](https://imagine.readthedocs.io/en/latest/). The [VIPS image processing system](https://libvips.github.io/libvips/) is a very fast, multi-threaded image processing library with low memory needs.

Version 8.6 or higher of libvips is highly recommended. `paste` and `rotate` by angles other than multipliers of 90 are not supported with older versions of libvips.
 
You also need the [php-vips-ext](https://github.com/libvips/php-vips-ext) extension version 1.0.8 or higher (you need to install that manually) and the [php-vips](https://github.com/libvips/php-vips) classes (automatically installed by composer)

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
 
 * Font
 * Drawer
 * Methods:
   * fill
   * histogram
 * Filters:
   * colorize

Most of them are not that important to us, so any contributions are welcome. Drawer for example may be a low hanging fruit, if you want to get into it.
  
### Layers

Layers work (not completely implemented yet and API may change). You can use it like the layer like described in the [Imagine docs](https://imagine.readthedocs.io/en/latest/usage/layers.html).

The only thing you have to be aware of is that vips can't really write all of those layers at once (eg. for animated gifs).

## Animated gifs

If you want to save an animated gif, you have to do convert the imagine-vips object to a imagine-imagick object with `$image->convertToAlternative()` and then save on that.

Example code:

```php
$im = new \Imagine\Vips\Imagine();
$ori = $im->open("animated.gif");
# make sure the images are "full" images
$ori->layers()->coalesce();
# do some operations on it
$resized = $ori->resize(new Box(200,200));
# convert to imagick, this is actually optional, you just are more aware what you are doing.
$imagick = $resized->convertToAlternative();
#save as animated gif
$imagick->save("resized.gif" ,['animated' => true]);
```

## Saving files

Natively supported by libvips for saving are jpg, png, webp and tiff. If you have vips 8.7.0 with imagemagick support, it will use vips "[magicksave](https://jcupitt.github.io/libvips/API/current/VipsForeignSave.html#vips-magicksave)" for all other formats. It not, this adapter falls back to the Imagick or GD implementation.

## Contribution

Any contribution is very appreciated, just file an issue or send a Pull Request.
 
 
