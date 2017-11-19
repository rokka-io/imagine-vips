# libvips adapter for imagine
 
 This package provides a [libvips](https://jcupitt.github.io/libvips/) integration for [imagine](https://imagine.readthedocs.io/en/latest/). The as of mid november 2017 not yet released version 8.6 is recommended for some functions. But 8.5 works for most too.
 
 You also need the [php-vips-ext](https://github.com/jcupitt/php-vips-ext) extension  and the [php-vips](https://github.com/jcupitt/php-vips) classes, add them to your composer.json with
 ```
 composer require jcupitt/vips
 ```
 
 Most (to me at least) important stuff is implemented, they may be edge cases, which are not covered yet.
 
## Missing stuff
 
 Not implemented yet
 
 * Layers - Just the first layer is loaded (see below for more info)
 * Font
 * Drawer
 * methods:
  * fill
  * histogram
  * profile
  * setColorspace
 * Filters:
  * colorize

 Most of them are not that important to us, so any contribution is welcome
  
### Layers

Currently, only the first frame is loaded and available for image manipulation.

Layers can theoretically be supported, BUT there's no save support in vips for layers (mainly animated gifs). So in case you need them, vips is currently the wrong library for you. And they don't plan to add it. So not sure, how much worth an effort for supporting that is. We could read them and made them accessible through the layers function and maybe safe them via imagick or gd, dunno yet.

## Saving files

Natively supported by vips are jpg, png and webp. For the rest this adapter falls back to the Imagick or GD implementation.

## Contribution

Any contribution is very appreciated, just file an issue or send a Pull Request.
 
 