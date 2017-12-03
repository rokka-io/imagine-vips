<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\NotSupportedException;
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
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\Color\Gray;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;
use Imagine\Image\ProfileInterface;
use Imagine\Image\VipsProfile;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Direction;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Extend;
use Jcupitt\Vips\ForeignTiffCompression;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Image implementation using the Vips PHP extension.
 */
class Image extends AbstractImage
{
    const ICC_DEFAULT_PROFILE_DEFAULT = 'sRGB_IEC61966-2-1_black_scaled.icc';
    const ICC_DEFAULT_PROFILE_BW = 'ISOcoated_v2_grey1c_bas.ICC';
    const ICC_DEFAULT_PROFILE_CMYK = 'USWebUncoated.icc';

    /**
     * @var VipsImage
     */
    protected $vips;
    /**
     * @var Layers
     */
    protected $layers;
    /**
     * @var PaletteInterface
     */
    private $palette;

    private $strip = false;

    private static $colorspaceMapping = [
        PaletteInterface::PALETTE_RGB => Interpretation::SRGB,
        PaletteInterface::PALETTE_GRAYSCALE => Interpretation::B_W,
    ];

    private static $interpretationIccProfileMapping = [
        Interpretation::B_W => self::ICC_DEFAULT_PROFILE_BW,
        Interpretation::GREY16 => self::ICC_DEFAULT_PROFILE_BW,
        Interpretation::CMYK => self::ICC_DEFAULT_PROFILE_CMYK,
    ];

    /**
     * Constructs a new Image instance.
     *
     * @param VipsImage        $vips
     * @param PaletteInterface $palette
     * @param MetadataBag      $metadata
     */
    public function __construct(VipsImage $vips, PaletteInterface $palette, MetadataBag $metadata)
    {
        $this->vips = $vips;

        $this->metadata = $metadata;
        $this->palette = $palette;
        $this->layers = new Layers($this);
        if ($palette instanceof  CMYK) {
            //convert to RGB when it's CMYK to make life much easier later on.
            // If someone really needs CMYK support, there's lots of stuff failing, which needs to be fixed
            // But  it could be added.
            $new = $this->usePalette(new RGB());
            $this->vips = $new->getVips();
            $this->palette = $new->palette();
        }
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
     * @return VipsImage
     */
    public function getVips()
    {
        return $this->vips;
    }

    /**
     * @param VipsImage $vips
     * @param bool      $updatePalette In case the palette should changed and should be updated
     *
     * @return self
     */
    public function setVips(VipsImage $vips, $updatePalette = false)
    {
        if ($this->vips->interpretation != $vips->interpretation) {
            $updatePalette = true;
        }

        $this->vips = $vips;
        if ($updatePalette) {
            $this->updatePalette();
        }

        return $this;
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
        if (!$image instanceof self) {
            throw new RuntimeException("Paste image needs to be a Imagine\Vips\Image object");
        }
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

        if (version_compare(vips_version(), '8.6', '<')) {
            throw new RuntimeException('The paste method needs at least vips 8.6');
        }
        // this class is new for vips 8.6 and in the dev-master branch of vips-ext
        // once we can require that via composer.json, we can remove this if and the else clause
        if (class_exists('\Jcupitt\Vips\BlendMode')) {
            // for php-vips > 1.0.2
            $this->vips = $this->vips->composite([$image], [BlendMode::OVER])->copyMemory();
        } else {
            // for php-vips <= 1.0.2
            $this->vips = $this->vips->composite([$this->vips, $image], 2)->copyMemory();
        }

        return $this;
    }

    public static function generateImage(BoxInterface $size, ColorInterface $color = null)
    {
        $width = $size->getWidth();
        $height = $size->getHeight();
        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');
        if ($palette instanceof RGB) {
            list($red, $green, $blue, $alpha) = self::getColorArrayAlpha($color);

            // Make a 1x1 pixel with all the channels and cast it to provided format.
            $pixel = VipsImage::black(1, 1)->add([$red, $green, $blue, $alpha])->cast(BandFormat::UCHAR);
        } elseif ($palette instanceof Grayscale) {
            list($gray, $alpha) = self::getColorArrayAlpha($color, 2);

            // Make a 1x1 pixel with all the channels and cast it to provided format.
            $pixel = VipsImage::black(1, 1)->add([$gray, $alpha])->cast(BandFormat::UCHAR);
        } else {
            throw new RuntimeException('Only RGB and Grayscale are supported for generating an image currently.');
        }
        // Extend this 1x1 pixel to match the origin image dimensions.
        $vips = $pixel->embed(0, 0, $width, $height, ['extend' => Extend::COPY]);
        $vips = $vips->copy(['interpretation' => self::getInterpretation($color->getPalette())]);

        return $vips;
    }

    /**
     * Resizes current image and returns self.
     *
     * @param BoxInterface $size
     * @param mixed        $filter Not supported yet
     *
     * @return self
     */
    public function resize(BoxInterface $size, $filter = ImageInterface::FILTER_UNDEFINED)
    {
        try {
            $vips = $this->vips;
            $original_format = $vips->format;
            if ($vips->hasAlpha()) {
                $vips = $vips->premultiply();
            }
            $vips = $vips->resize($size->getWidth() / $vips->width, ['vscale' => $size->getHeight() / $vips->height]);
            if ($vips->hasAlpha()) {
                $vips = $vips->unpremultiply();
                if ($vips->format != $original_format) {
                    $vips = $vips->cast($original_format);
                }
            }
            $this->vips = $vips;
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
                case -360:
                    break;
                case 90:
                case -270:
                    $this->vips = $this->vips->rot90();
                    break;
                case 180:
                case -180:
                    $this->vips = $this->vips->rot180();
                    break;
                case 270:
                case -90:
                    $this->vips = $this->vips->rot270();
                    break;
                default:
                    if (!$this->vips->hasAlpha()) {
                        //FIXME, alpha channel with Grey16 isn't doing well on rotation. there's only alpha in the end
                        if (Interpretation::GREY16 !== $this->vips->interpretation) {
                            $this->vips = $this->vips->bandjoin(255);
                        }
                    }
                    if (version_compare(vips_version(), '8.6', '<')) {
                        throw new RuntimeException('The rotate method for angles != 90, 180, 270 needs at least vips 8.6');
                    }
                    $this->vips = $this->vips->similarity(['angle' => $angle, 'background' => self::getColorArrayAlpha($color, $this->vips->bands)]);
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
        /** @var Image $image */
        $image = $this->prepareOutput($options);
        $vips = $image->getVips();

        $format = $options['format'];
        if ('jpg' == $format || 'jpeg' == $format) {
            $vips->jpegsave($path, ['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);

            return $this;
        } elseif ('png' == $format) {
            $vips->pngsave($path, ['strip' => $this->strip, 'compression' => $options['png_compression_level']]);

            return $this;
        } elseif ('webp' == $format) {
            $vips->webpsave($path, ['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);

            return $this;
        }
        //fallback to imagemagick or gd
        return $image->convertToAlternative()->save($path, $options);
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
        /** @var Image $image */
        $image = $this->prepareOutput($options);

        $vips = $image->getVips();
        $options = $this->applyImageOptions($vips, $options);

        if ('jpg' == $format || 'jpeg' == $format) {
            return $vips->jpegsave_buffer(['strip' => $this->strip, 'Q' => $options['jpeg_quality'], 'interlace' => true]);
        } elseif ('png' == $format) {
            return $vips->pngsave_buffer(['strip' => $this->strip, 'compression' => $options['png_compression_level']]);
        } elseif ('webp' == $format) {
            return $vips->webpsave_buffer(['strip' => $this->strip, 'Q' => $options['webp_quality'], 'lossless' => $options['webp_lossless']]);
        }

        //FIXME: and maybe make that more customizable
        if (class_exists('Imagick')) {
            $imagine = new \Imagine\Imagick\Imagine();
        } else {
            $imagine = new \Imagine\Gd\Imagine();
        }
        //fallback to imagemagick or gd
        return $image->convertToAlternative()->get($format, $options);
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
        $newImage->setVips($new, true);

        return $newImage;
    }

    /**
     * {@inheritdoc}
     */
    public function mask()
    {
        /** @var VipsImage $lch */
        $lch = $this->vips->colourspace(Interpretation::LCH);
        $multiply = [1, 0, 1];
        if ($lch->hasAlpha()) {
            $multiply[] = 1;
        }
        $lch = $lch->multiply($multiply);
        $lch = $lch->colourspace(Interpretation::B_W);
        //$lch = $lch->extract_band(0);
        $newImage = clone $this;
        $newImage->setVips($lch, true);

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
        if ($this->palette() instanceof Grayscale) {
            $alpha = array_pop($pixel) / 255 * 100;
            $g = (int) $pixel[0];

            return $this->palette()->color([$g, $g, $g], (int) $alpha);
        }

        throw new NotSupportedException('Image has a not supported palette');
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
        $new = clone $this;
        $vipsNew = $new->getVips();

        if (!isset(self::$colorspaceMapping[$palette->name()])) {
            $newColorspace = Interpretation::SRGB;
        } else {
            $newColorspace = self::$colorspaceMapping[$palette->name()];
        }

        try {
            $vipsNew = $vipsNew->icc_import(['embedded' => true]);
        } catch (VipsException $e) {
            // try with the supplied "default" profile, if no embedded was found and it failed
            $vipsNew = $this->applyProfile($palette->profile(), $vipsNew);
        }
        $vipsNew = $vipsNew->colourspace($newColorspace);

        try {
            //try to remove icc-profile-data, not sure that's always correct, for srgb and 'bw' it seems to.
            $vipsNew->remove('icc-profile-data');
        } catch (VipsException $e) {
        }

        $profile = $palette->profile();
        // convert to a ICC profile, if it's not the default one
        $defaultProfile = $this->getDefaultProfileForInterpretation($vipsNew);
        if ($profile->name() != $defaultProfile) {
            $vipsNew = $this->applyProfile($palette->profile(), $vipsNew);
        }

        $new->setVips($vipsNew, true);

        return $new;
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
        $new = clone $this;
        $vips = $new->getVips();
        $new->setVips($this->applyProfile($profile, $vips), true);

        return $new;
    }

    public static function getColorArrayAlpha(ColorInterface $color, $bands = 4): array
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
            if ($bands <= 2) {
                return [
                    $color->getValue(ColorInterface::COLOR_GRAY),
                    $color->getAlpha() / 100 * 255,
                ];
            }

            return [
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getValue(ColorInterface::COLOR_GRAY),
                $color->getAlpha() / 100 * 255,
            ];
        }
        throw new NotSupportedException('Image has a not supported palette.');
    }

    /**
     * @param ImagineInterface|null $imagine the alternative imagine interface to use, autodetects, if not set
     *
     * @return ImageInterface
     */
    public function convertToAlternative(ImagineInterface $imagine = null)
    {
        if (null === $imagine) {
            if (class_exists('Imagick')) {
                $imagine = new \Imagine\Imagick\Imagine();
            } else {
                $imagine = new \Imagine\Gd\Imagine();
            }
        }

        return $imagine->load($this->getImageStringForLoad($this->vips));
    }

    protected function applyProfile(ProfileInterface $profile, VipsImage $vips)
    {
        $defaultProfile = $this->getDefaultProfileForInterpretation($vips);
        $vips = $vips->icc_transform(
            VipsProfile::fromRawData($profile->data())->path(),
            [
                'embedded' => true,
                'input_profile' => __DIR__.'/../../resources/colorprofiles/'.$defaultProfile,
            ]
        );

        return $vips;
    }

    protected function extendImage(BoxInterface $box, PointInterface $start)
    {
        if ($this->vips->bands > 2) {
            $color = new \Imagine\Image\Palette\Color\RGB(new RGB(), [255, 255, 255], 0);
        } else {
            $color = new Gray(new Grayscale(), [255], 0);
        }
        if (!$this->vips->hasAlpha()) {
            $this->vips = $this->vips->bandjoin([255]);
        }
        $new = self::generateImage($box, $color);
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
            return Interpretation::B_W;
        }
        if ($palette instanceof CMYK) {
            return Interpretation::CMYK;
        }
        throw new NotSupportedException('Image has a not supported palette');
    }

    /**
     * @param VipsImage $vips
     *
     * @return string
     */
    protected function getDefaultProfileForInterpretation($vips)
    {
        $defaultProfile = self::ICC_DEFAULT_PROFILE_DEFAULT;
        if (isset(self::$interpretationIccProfileMapping[$vips->interpretation])) {
            $defaultProfile = self::$interpretationIccProfileMapping[$vips->interpretation];
        }

        return $defaultProfile;
    }

    /**
     * @param VipsImage $res
     *
     * @return string
     */
    protected function getImageStringForLoad(VipsImage $res)
    {
        return $res->tiffsave_buffer(['compression' => ForeignTiffCompression::NONE]);
    }

    /**
     * @param array  $options
     * @param string $path
     *
     * @return Image
     */
    private function prepareOutput(array $options, $path = null): self
    {
        //convert to RGB if it's cmyk
        if ($this->palette() instanceof CMYK) {
            return $this->usePalette(new RGB());
        }

        return $this;
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
            if ($this->vips->hasAlpha()) {
                return $this->vips->flatten();
            }

            return $this->vips;
        } catch (VipsException $e) {
            throw new RuntimeException('Flatten operation failed', $e->getCode(), $e);
        }
    }

    /**
     * Internal.
     *
     * Applies options before save or output
     *
     * @param VipsImage $vips
     * @param array     $options
     * @param string    $path
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @return array
     */
    private function applyImageOptions(VipsImage $vips, array $options, $path = null): array
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

        if ('png' === $format) {
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

    private function getColorArray(ColorInterface $color): array
    {
        return [$color->getValue(ColorInterface::COLOR_RED),
            $color->getValue(ColorInterface::COLOR_GREEN),
            $color->getValue(ColorInterface::COLOR_BLUE),
        ];
    }
}
