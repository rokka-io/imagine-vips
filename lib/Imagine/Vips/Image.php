<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Core\Operation\Grayscale;
use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\OutOfBoundsException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImage;
use Imagine\Image\Box;
use Imagine\Image\BoxInterface;
use Imagine\Image\Fill\FillInterface;
use Imagine\Image\Fill\Gradient\Horizontal;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;
use Imagine\Image\ProfileInterface;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\Direction;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Extend;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Image implementation using the Vips PHP extension.
 */
class Image extends AbstractImage
{
    /**
     * @var \Jcupitt\Vips\Image
     */
    protected $vips;
    /**
     * @var Layers
     */
    private $layers;
    /**
     * @var PaletteInterface
     */
    private $palette;

    private $strip = false;

    private static $colorspaceMapping = [
        PaletteInterface::PALETTE_CMYK => \Imagick::COLORSPACE_CMYK,
        PaletteInterface::PALETTE_RGB => \Imagick::COLORSPACE_RGB,
        PaletteInterface::PALETTE_GRAYSCALE => \Imagick::COLORSPACE_GRAY,
    ];

    /**
     * Constructs a new Image instance.
     *
     * @param \Jcupitt\Vips\Image         vips
     * @param PaletteInterface $palette
     * @param MetadataBag      $metadata
     */
    public function __construct(VipsImage $vips, PaletteInterface $palette, MetadataBag $metadata)
    {
        $this->vips = $vips;

        $this->metadata = $metadata;
        $this->palette = $palette;
        $this->layers = new Layers($this);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->get('png');
    }

    /**
     * Returns the underlying \Jcupitt\Vips\Image instance.
     *
     * @return \Jcupitt\Vips\Image
     */
    public function getVips()
    {
        return $this->vips;
    }

    public function setVips($vips)
    {
        $this->vips = $vips;
        $this->updatePalette();
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function copy()
    {
        $clone = clone $this->vips->copy();

        return new self($clone, $this->palette, clone $this->metadata);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function crop(PointInterface $start, BoxInterface $size)
    {
        $thisBox = $this->getSize();
        if (!$start->in($thisBox)) {
            throw new OutOfBoundsException('Crop coordinates must start at minimum 0, 0 position from top left corner, crop height and width must be positive integers and must not exceed the current image borders');
        }
        // behave the same as imagick and gd, if box is too big, resize to max possible value, so that it
        //  stops at right and bottom border
        if (!$thisBox->contains($size, $start)) {
            if ($start->getX() + $size->getWidth() > $thisBox->getWidth()) {
                $size = new Box($thisBox->getWidth() - $start->getX(), $size->getHeight());
            }
            if ($start->getY() + $size->getHeight() > $thisBox->getHeight()) {
                $size = new Box($size->getWidth(), $thisBox->getHeight() - $start->getY());
            }
        }
        try {
            /*
             * FIXME: Layers support
             * if ($this->layers()->count() > 1) {
             *   // Crop each layer separately
             * } else {
             */
            $this->vips = $this->vips->crop($start->getX(), $start->getY(), $size->getWidth(), $size->getHeight());
        } catch (VipsException $e) {
            throw new RuntimeException('Crop operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function flipHorizontally()
    {
        try {
            $this->vips = $this->vips->flip(Direction::HORIZONTAL);
        } catch (VipsException $e) {
            throw new RuntimeException('Horizontal Flip operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function flipVertically()
    {
        try {
            $this->vips = $this->vips->flip(Direction::VERTICAL);
        } catch (VipsException $e) {
            throw new RuntimeException('Vertical Flip operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function strip()
    {
        $this->strip = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function paste(ImageInterface $image, PointInterface $start)
    {
        /** @var VipsImage $inVips */
        $inVips = $image->getVips();

        if (!$inVips->hasAlpha()) {
            if ($this->vips->hasAlpha()) {
                $inVips = $inVips->bandjoin([255]);
            }
        }

        if (!$this->vips->hasAlpha()) {
            if ($inVips->hasAlpha()) {
                $this->vips = $this->vips->bandjoin([255]);
            }
        }
        $image = $image->extendImage($this->getSize(), $start)->getVips();
        $this->vips = $this->vips->composite([$this->vips, $image], [2]);

        return $this;
    }

    public static function generateImage(BoxInterface $size, ColorInterface $color = null)
    {
        $width = $size->getWidth();
        $height = $size->getHeight();
        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');
        list($red, $green, $blue, $alpha) = self::getColorArrayAlpha($color);

        // Make a 1x1 pixel with the red channel and cast it to provided format.
        $pixel = VipsImage::black(1, 1)->add($red)->cast(BandFormat::UCHAR);
        // Extend this 1x1 pixel to match the origin image dimensions.
        $vips = $pixel->embed(0, 0, $width, $height, ['extend' => Extend::COPY]);
        $vips = $vips->copy(['interpretation' => self::getInterpretation($color->getPalette())]);
        // Bandwise join the rest of the channels including the alpha channel.
        $vips = $vips->bandjoin([
            $green,
            $blue,
            $alpha,
        ]);

        return $vips;
    }

    /**
     * {@inheritdoc}
     */
    public function resize(BoxInterface $size, $filter = ImageInterface::FILTER_UNDEFINED)
    {
        try {
            if ($this->vips->hasAlpha()) {
                $this->vips = $this->vips->premultiply();
            }
            $this->vips = $this->vips->resize($size->getWidth() / $this->vips->width, ['vscale' => $size->getHeight() / $this->vips->height]);
            if ($this->vips->hasAlpha()) {
                $this->vips = $this->vips->unpremultiply();
            }
        } catch (VipsException $e) {
            throw new RuntimeException('Resize operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function rotate($angle, ColorInterface $background = null)
    {
        $color = $background ? $background : $this->palette->color('fff');
        try {
            switch ($angle) {
                case 0:
                case 360:
                    break;
                case 90:
                    $this->vips = $this->vips->rot90();
                    break;
                case 180:
                    $this->vips = $this->vips->rot180();
                    break;
                case 270:
                    $this->vips = $this->vips->rot270();
                    break;
                default:
                    if (!$this->vips->hasAlpha()) {
                        //FIXME, alpha channel with Grey16 isn't doing well on rotation. there's only alpha in the end
                        if ($this->vips->interpretation !== Interpretation::GREY16) {
                            $this->vips = $this->vips->bandjoin(255);
                        }
                    }
                    //needs upcoming vips 8.6
                    $this->vips = $this->vips->similarity(['angle' => $angle, 'background' => self::getColorArrayAlpha($color)]);
            }
        } catch (VipsException $e) {
            throw new RuntimeException('Rotate operation failed. '.$e->getMessage(), $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function save($path = null, array $options = [])
    {
        $options = $this->applyImageOptions($this->vips, $options, $path);
        $this->prepareOutput($options);
        $format = $options['format'];
        if ($format == 'jpg' || $format == 'jpeg') {
            return $this->vips->jpegsave($path, ['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        } elseif ($format == 'png') {
            return $this->vips->pngsave($path, ['strip' => $this->strip, 'compression' => $options['png_compression_level']]);
        } elseif ($format == 'webp') {
            return $this->vips->webpsave($path, ['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);
        }
        //fallback to imagemagick or gd
        return $this->getFallbackImagineImage()->save($path, $options);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function show($format, array $options = [])
    {
        header('Content-type: '.$this->getMimeType($format));
        echo $this->get($format, $options);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get($format, array $options = [])
    {
        $options['format'] = $format;
        $this->prepareOutput($options);
        $options = $this->applyImageOptions($this->vips, $options);

        if ($format == 'jpg' || $format == 'jpeg') {
            return $this->vips->jpegsave_buffer(['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        } elseif ($format == 'png') {
            return $this->vips->pngsave_buffer(['strip' => $this->strip, 'compression' => $options['png_compression_level']]);
        } elseif ($format == 'webp') {
            return $this->vips->webpsave_buffer(['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);
        }

        //FIXME: and maybe make that more customizable
        if (class_exists('Imagick')) {
            $imagine = new \Imagine\Imagick\Imagine();
        } else {
            $imagine = new \Imagine\GD\Imagine();
        }
        //fallback to imagemagick or gd
        return $this->getFallbackImagineImage()->get($format, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function interlace($scheme)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function draw()
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
        return new Drawer($this->vips);
    }

    /**
     * {@inheritdoc}
     */
    public function effects()
    {
        return new Effects($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize()
    {
        $width = $this->vips->width;
        $height = $this->vips->height;

        return new Box($width, $height);
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function applyMask(ImageInterface $mask)
    {
        if (!$mask instanceof self) {
            throw new InvalidArgumentException('Can only apply instances of Imagine\Imagick\Image as masks');
        }

        $size = $this->getSize();
        $maskSize = $mask->getSize();

        if ($size != $maskSize) {
            throw new InvalidArgumentException(sprintf('The given mask doesn\'t match current image\'s size, Current mask\'s dimensions are %s, while image\'s dimensions are %s', $maskSize, $size));
        }

        $mask = $mask->getVips()->colourspace(Interpretation::B_W)->extract_band(0);
        //remove alpha
        if ($this->vips->hasAlpha()) {
            $new = $this->vips->extract_band(0, ['n' => $this->vips->bands - 1]);
        } else {
            $new = $this->vips->copy();
        }
        $new = $new->bandjoin($mask);
        $newImage = clone $this;
        $newImage->setVips($new);

        return $newImage;
    }

    /**
     * {@inheritdoc}
     */
    public function mask()
    {
        /** @var \Jcupitt\Vips\Image $lch */
        $lch = $this->vips->colourspace(Interpretation::LCH);
        $multiply = [1, 0, 1];
        if ($lch->hasAlpha()) {
            $multiply[] = 1;
        }
        $lch = $lch->multiply($multiply);
        $lch = $lch->colourspace(Interpretation::B_W);
        //$lch = $lch->extract_band(0);
        $newImage = clone $this;
        $newImage->setVips($lch);

        return $newImage;
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function fill(FillInterface $fill)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function histogram()
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function getColorAt(PointInterface $point)
    {
        if (!$point->in($this->getSize())) {
            throw new RuntimeException(sprintf('Error getting color at point [%s,%s]. The point must be inside the image of size [%s,%s]', $point->getX(), $point->getY(), $this->getSize()->getWidth(), $this->getSize()->getHeight()));
        }

        try {
            $pixel = $this->vips->getpoint($point->getX(), $point->getY());
        } catch (VipsException $e) {
            throw new RuntimeException('Error while getting image pixel color', $e->getCode(), $e);
        }

        return $this->pixelToColor($pixel);
    }

    /**
     * Returns a color given a pixel, depending the Palette context.
     *
     * Note : this method is public for PHP 5.3 compatibility
     *
     * @param array $pixel
     *
     * @throws InvalidArgumentException In case a unknown color is requested
     *
     * @return ColorInterface
     */
    public function pixelToColor(array $pixel)
    {
        if ($this->palette->supportsAlpha() && $this->vips->hasAlpha()) {
            $alpha = array_pop($pixel) / 255 * 100;
        } else {
            $alpha = null;
        }
        if ($this->palette() instanceof RGB) {
            return $this->palette()->color($pixel, (int) $alpha);
        }
        if ($this->palette() instanceof \Imagine\Image\Palette\Grayscale) {
            $alpha = array_pop($pixel) / 255 * 100;
            $g = (int) $pixel[0];

            return $this->palette()->color([$g, $g, $g], (int) $alpha);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function layers()
    {
        //FIXME: implement actual layers, not just the first layer in vips

        return $this->layers;
    }

    /**
     * {@inheritdoc}
     */
    public function usePalette(PaletteInterface $palette)
    {
        if (!isset(self::$colorspaceMapping[$palette->name()])) {
            throw new InvalidArgumentException(sprintf('The palette %s is not supported by Vips driver', $palette->name()));
        }

        /* FIXME: implement palette support.. */
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function palette()
    {
        return $this->palette;
    }

    /**
     * {@inheritdoc}
     */
    public function profile(ProfileInterface $profile)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public static function getColorArrayAlpha(ColorInterface $color): array
    {
        if ($color->getPalette() instanceof RGB) {
            return [
                $color->getValue(ColorInterface::COLOR_RED),
                $color->getValue(ColorInterface::COLOR_GREEN),
                $color->getValue(ColorInterface::COLOR_BLUE),
                $color->getAlpha() / 100 * 255,
            ];
        }
        if ($color->getPalette() instanceof Grayscale) {
            return [
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getAlpha() / 100 * 255,
            ];
        }
    }

    protected function extendImage(BoxInterface $box, PointInterface $start)
    {
        $color = new \Imagine\Image\Palette\Color\RGB(new RGB(), [255, 255, 255], 0);
        if (!$this->vips->hasAlpha()) {
            $this->vips = $this->vips->bandjoin([255]);
        }
        $new = self::generateImage($box, $color);
        //$this->vips = $new;
        $this->vips = $new->insert($this->vips, $start->getX(), $start->getY());

        return $this;
    }

    protected function updatePalette()
    {
        $this->palette = Imagine::createPalette($this->vips);
    }

    protected static function getInterpretation(PaletteInterface $palette)
    {
        if ($palette instanceof RGB) {
            return Interpretation::SRGB;
        }
        if ($palette instanceof Grayscale) {
            return Interpretation::GREY16;
        }
        if ($palette instanceof CMYK) {
            return Interpretation::CMYK;
        }
    }

    /**
     * @return ImagineInterface
     */
    protected function getFallbackImagineImage()
    {
        //FIXME: make this better configurable...
        if (class_exists('Imagick')) {
            $imagine = new \Imagine\Imagick\Imagine();
        } else {
            $imagine = new \Imagine\GD\Imagine();
        }

        return $imagine->load($this->vips->pngsave_buffer(['interlace' => false]));
    }

    /**
     * @param array  $options
     * @param string $path
     */
    private function prepareOutput(array $options, $path = null)
    {
        if (isset($options['format'])) {
            // $this->vips->format = $options['format'];
            //$this->vips->setImageFormat($options['format']);
        }
        // FIXME: layer support, merge them if $options['animated'] != true or $options['flatten'] == true
    }

    /**
     * Internal.
     *
     * Flatten the image.
     */
    private function flatten()
    {
        try {
            return  $this->vips->flatten();
        } catch (VipsException $e) {
            throw new RuntimeException('Flatten operation failed', $e->getCode(), $e);
        }
    }

    /**
     * Internal.
     *
     * Applies options before save or output
     *
     * @param VipsImage $image
     * @param array     $options
     * @param string    $path
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function applyImageOptions(VipsImage $vips, array $options, $path = null)
    {
        if (isset($options['format'])) {
            $format = $options['format'];
        } elseif ('' !== $extension = pathinfo($path, \PATHINFO_EXTENSION)) {
            $format = $extension;
        } else {
            //FIXME, may not work
            $format = pathinfo($vips->filename, \PATHINFO_EXTENSION);
        }
        $format = strtolower($format);
        $options['format'] = $format;

        if (!isset($options['jpeg_quality']) && in_array($format, ['jpeg', 'jpg', 'pjpeg'], true)) {
            $options['jpeg_quality'] = 92;
        }
        if (!isset($options['webp_quality']) && in_array($format, ['webp'], true)) {
            $options['webp_quality'] = 80; // FIXME: correct value?
        }
        if (!isset($options['webp_lossless']) && in_array($format, ['webp'], true)) {
            $options['webp_lossless'] = false;
        }

        if ($format === 'png') {
            if (!isset($options['png_compression_level'])) {
                $options['png_compression_level'] = 7;
            }
            //FIXME: implement different png_compression_filter
            if (!isset($options['png_compression_filter'])) {
                $options['png_compression_filter'] = 5;
            }
        }
        /* FIXME: do we need this?
        if (isset($options['resolution-units']) && isset($options['resolution-x']) && isset($options['resolution-y'])) {
            if ($options['resolution-units'] == ImageInterface::RESOLUTION_PIXELSPERCENTIMETER) {
                $vips->setImageUnits(\Imagick::RESOLUTION_PIXELSPERCENTIMETER);
            } elseif ($options['resolution-units'] == ImageInterface::RESOLUTION_PIXELSPERINCH) {
                $vips->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
            } else {
                throw new RuntimeException('Unsupported image unit format');
            }

            $filter = ImageInterface::FILTER_UNDEFINED;
            if (!empty($options['resampling-filter'])) {
                $filter = $options['resampling-filter'];
            }

            $image->setImageResolution($options['resolution-x'], $options['resolution-y']);
            $image->resampleImage($options['resolution-x'], $options['resolution-y'], $this->getFilter($filter), 0);
        }
         */
        return $options;
    }

    /**
     * Internal.
     *
     * Get the mime type based on format.
     *
     * @param string $format
     *
     * @throws RuntimeException
     *
     * @return string mime-type
     */
    private function getMimeType($format)
    {
        static $mimeTypes = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'wbmp' => 'image/vnd.wap.wbmp',
            'xbm' => 'image/xbm',
            'webp' => 'image/webp',
        ];

        if (!isset($mimeTypes[$format])) {
            throw new RuntimeException(sprintf('Unsupported format given. Only %s are supported, %s given', implode(', ', array_keys($mimeTypes)), $format));
        }

        return $mimeTypes[$format];
    }

    /**
     * Sets colorspace and image type, assigns the palette.
     *
     * @param PaletteInterface $palette
     *
     * @throws InvalidArgumentException
     */
    private function setColorspace(PaletteInterface $palette)
    {
        //FIXME: implement in vips
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    private function getColorArray(ColorInterface $color): array
    {
        return [$color->getValue(ColorInterface::COLOR_RED),
            $color->getValue(ColorInterface::COLOR_GREEN),
            $color->getValue(ColorInterface::COLOR_BLUE),
        ];
    }
}
