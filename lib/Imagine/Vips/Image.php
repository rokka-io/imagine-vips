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
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Metadata\DefaultMetadataReader;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\Color\Gray;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\PointInterface;
use Imagine\Image\ProfileInterface;
use Imagine\Image\VipsProfile;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Direction;
use Jcupitt\Vips\Exception;
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
    const ICC_DEFAULT_PROFILE_DEFAULT = 'sRGB.icm';
    const ICC_DEFAULT_PROFILE_BW = 'gray.icc';
    const ICC_DEFAULT_PROFILE_CMYK = 'cmyk.icm';

    public const OPTION_JPEG_QUALITY = 'jpeg_quality';
    public const OPTION_PNG_QUALITY = 'png_quality';

    public const OPTION_WEBP_QUALITY = 'webp_quality';
    public const OPTION_HEIF_QUALITY = 'heif_quality';
    public const OPTION_AVIF_QUALITY = 'avif_quality';
    public const OPTION_JXL_QUALITY = 'jxl_quality';
    public const OPTION_JXL_DISTANCE = 'jxl_distance';
    public const OPTION_JXL_EFFORT = 'jxl_effort';
    public const OPTION_JXL_LOSSLESS = 'jxl_lossless';
    public const OPTION_WEBP_LOSSLESS = 'webp_lossless';

    /**
     * The reduction effort for webp. Max is 6, Default 4.
     */
    public const OPTION_WEBP_REDUCTION_EFFORT = 'webp_reduction_effort';
    public const OPTION_PNG_COMPRESSION_LEVEL = 'png_compression_level';
    public const OPTION_PNG_COMPRESSION_FILTER = 'png_compression_filter';

    /**
     * @var VipsImage
     */
    protected $vips;

    /**
     * @var \Imagine\Image\LayersInterface
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
     */
    public function __construct(VipsImage $vips, PaletteInterface $palette, MetadataBag $metadata)
    {
        $this->vips = $vips;

        $this->metadata = $metadata;
        $this->palette = $palette;
        $this->layers = new Layers($this);
        if ($palette instanceof CMYK) {
            //convert to RGB when it's CMYK to make life much easier later on.
            // If someone really needs CMYK support, there's lots of stuff failing, which needs to be fixed
            // But  it could be added.
            $new = $this->usePalette(new RGB());
            $this->vips = $new->getVips();
            $this->palette = $new->palette();
            $this->layers = $new->layers();
        }
    }

    public function __clone()
    {
        parent::__clone();
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
     * @return VipsImage
     */
    public function getVips()
    {
        return $this->vips;
    }

    /**
     * Copies the image, mainly needed when manipulation image metadata.
     *
     * @return VipsImage
     */
    public function vipsCopy()
    {
        $this->vips = $this->vips->copy();

        return $this->vips;
    }

    /**
     * @param bool $updatePalette In case the palette should changed and should be updated
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
            $this->applyToLayers(function (VipsImage $vips) use ($size, $start): VipsImage {
                return $vips->crop($start->getX(), $start->getY(), $size->getWidth(), $size->getHeight());
            });
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
            $this->applyToLayers(function (VipsImage $vips): VipsImage {
                return $vips->flip(Direction::HORIZONTAL);
            });
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
            $this->applyToLayers(function (VipsImage $vips): VipsImage {
                return $vips->flip(Direction::VERTICAL);
            });
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
    public function paste(ImageInterface $image, PointInterface $start, $alpha = 100)
    {
        if (version_compare(Config::version(), '8.6', '<')) {
            throw new RuntimeException('The paste method needs at least vips 8.6');
        }

        if (!$image instanceof self) {
            if (method_exists($image, 'convertToVips')) {
                $image = $image->convertToVips();
            } else {
                throw new RuntimeException("Paste image needs to be a Imagine\Vips\Image object");
            }
        }
        $this->vips = $this->pasteVipsImage($image->getVips(), $start, $alpha);

        return $this;
    }

    public function pasteVipsImage(VipsImage $vips, PointInterface $start, $alpha = 100)
    {
        if (!$vips->hasAlpha()) {
            if ($this->vips->hasAlpha()) {
                $vips = $vips->bandjoin([255]);
            }
        }
        if (!$this->vips->hasAlpha()) {
            if ($vips->hasAlpha()) {
                $this->vips = $this->vips->bandjoin([255]);
            }
        }

        $vips = self::extendImageWithVips($vips, $this->getSize(), $start);

        return $this->vips->composite([$vips], [BlendMode::OVER])->copyMemory();
    }

    public static function generateImage(BoxInterface $size, ColorInterface $color = null)
    {
        $width = $size->getWidth();
        $height = $size->getHeight();
        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');
        if ($palette instanceof RGB) {
            [$red, $green, $blue, $alpha] = self::getColorArrayAlpha($color);

            // Make a 1x1 pixel with all the channels and cast it to provided format.
            $pixel = VipsImage::black(1, 1)->add([$red, $green, $blue, $alpha])->cast(BandFormat::UCHAR);
        } elseif ($palette instanceof Grayscale) {
            [$gray, $alpha] = self::getColorArrayAlpha($color, 2);

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
     * @param mixed $filter Not supported yet
     *
     * @return self
     */
    public function resize(BoxInterface $size, $filter = ImageInterface::FILTER_UNDEFINED)
    {
        try {
            $this->applyToLayers(function (VipsImage $vips) use ($size): VipsImage {
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

                return $vips;
            });
        } catch (VipsException $e) {
            throw new RuntimeException('Resize operation failed', $e->getCode(), $e);
        }

        return $this;
    }

    public function applyToLayers(callable $callback)
    {
        $layers = $this->layers();
        $n = \count($layers);
        for ($i = 0; $i < $n; ++$i) {
            $image = $layers[$i];
            $vips = $image->getVips();
            $vips = $callback($vips);
            $image->setVips($vips);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return ImageInterface
     */
    public function rotate($angle, ColorInterface $background = null)
    {
        $color = $background ?: $this->palette->color('fff');
        try {
            $this->applyToLayers(function (VipsImage $vips) use ($angle, $color): VipsImage {
                switch ($angle) {
                    case 0:
                    case 360:
                    case -360:
                        break;
                    case 90:
                    case -270:
                        $vips = $vips->rot90();
                        break;
                    case 180:
                    case -180:
                        $vips = $vips->rot180();
                        break;
                    case 270:
                    case -90:
                        $vips = $vips->rot270();
                        break;
                    default:
                        if (!$vips->hasAlpha()) {
                            //FIXME, alpha channel with Grey16 isn't doing well on rotation. there's only alpha in the end
                            if (Interpretation::GREY16 !== $vips->interpretation) {
                                $vips = $vips->bandjoin(255);
                            }
                        }
                        if (version_compare(Config::version(), '8.6', '<')) {
                            throw new RuntimeException('The rotate method for angles != 90, 180, 270 needs at least vips 8.6');
                        }
                        $vips = $vips->similarity(['angle' => $angle, 'background' => self::getColorArrayAlpha($color, $vips->bands)]);
                }

                return $vips;
            });
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
        /** @var Image $image */
        $image = $this->prepareOutput($options);
        $options = $this->applyImageOptions($image->getVips(), $options, $path);
        $format = $options['format'];

        [$method, $saveOptions] = $this->getSaveMethodAndOptions($format, $options);
        $vips = $this->joinMultilayer($format, $image);

        if (null !== $method) {
            try {
                $vips->$method($path, $saveOptions);
                return $this;
            } catch (\Jcupitt\Vips\Exception $e) {
                // try the alternative approach if method is magicksave, if we fail here, mainly means that the magicksave stuff isn't
                // installed
                if ('magicksave' !== $method) {
                    throw $e;
                }
                // if vips can't read it with libMagick, the alternatives can't either. throw an error
                if (strpos($e->getMessage(), 'libMagick error: no decode delegate for this image format') > 0) {
                    throw new NotSupportedException('Image format is not supported.', 0, $e);
                }
            }
        }
        $alt = $this->convertToAlternativeForSave($options, $image, $format);

        return $alt->save($path, $options);
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
        $options = $this->applyImageOptions($image->getVips(), $options);
        [$method, $saveOptions] = $this->getSaveMethodAndOptions($format, $options);

        $vips = $this->joinMultilayer($format, $image);
        if (null !== $method) {
            try {
                $saveMethod = $method.'_buffer';

                return $vips->$saveMethod($saveOptions);
            } catch (\Jcupitt\Vips\Exception $e) {
                // try the alternative approach if method is magicksave, if we fail here, mainly means that the magicksave stuff isn't
                // installed
                if ('magicksave' !== $method) {
                    throw $e;
                }

                // if vips can't read it with libMagick, the alternatives can't either. throw an error
                if (strpos($e->getMessage(), 'libMagick error: no decode delegate for this image format') > 0) {
                    throw new NotSupportedException('Image format is not supported.', 0, $e);
                }
            }
        }
        $alt = $this->convertToAlternativeForSave($options, $image, $format);

        return $alt->get($format, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function interlace($scheme)
    {
        //FIXME: implement in vips
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function draw()
    {
        return new Drawer($this);
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
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function histogram()
    {
        //FIXME: implement in vips
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
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
        if (\is_array($pixel)) {
            return $this->pixelToColor($pixel);
        }
        throw new RuntimeException('Error getting color at point. Was not an array.');
    }

    /**
     * Returns a color given a pixel, depending the Palette context.
     *
     * Note : this method is public for PHP 5.3 compatibility
     *
     * @throws InvalidArgumentException In case a unknown color is requested
     *
     * @return ColorInterface
     */
    public function pixelToColor(array $pixel)
    {
        if ($this->vips->hasAlpha()) {
            $alpha = (int) (array_pop($pixel) / 255 * 100);
        } else {
            $alpha = $this->palette->supportsAlpha() ? 100 : null;
        }
        if ($this->palette() instanceof RGB) {
            return $this->palette()->color($pixel, $alpha);
        }
        if ($this->palette() instanceof Grayscale) {
            $g = (int) $pixel[0];

            return $this->palette()->color([$g, $g, $g], $alpha);
        }

        throw new NotSupportedException('Image has a not supported palette');
    }

    /**
     * {@inheritdoc}
     */
    public function layers()
    {
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

        $vipsNew = $this->applyProfile($palette->profile(), $vipsNew);
        $vipsNew = $vipsNew->colourspace($newColorspace);

        try {
            //try to remove icc-profile-data, not sure that's always correct, for srgb and 'bw' it seems to.
            $vipsNew = $vipsNew->copy();
            $vipsNew->remove('icc-profile-data');
        } catch (VipsException $e) {
        }

        $profile = $palette->profile();
        // convert to a ICC profile, if it's not the default one
        $defaultProfile = $this->getDefaultProfileForInterpretation($vipsNew);
        if ($profile->name() != $defaultProfile) {
            $vipsNew = $this->applyProfile($palette->profile(), $vipsNew);
        }

        $this->setVips($vipsNew, true);

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
     * @param ImagineInterface|null $imagine     the alternative imagine interface to use, autodetects, if not set
     * @param array                 $tiffOptions options to load the tiff image for conversion, eg ['strip' => true]
     * @param bool                  $asTiff
     *
     * @return ImageInterface
     */
    public function convertToAlternative(ImagineInterface $imagine = null, array $tiffOptions = [], $asTiff = false)
    {
        if (null === $imagine) {
            $oldMetaReader = null;
            if (class_exists('Imagick')) {
                $imagine = new \Imagine\Imagick\Imagine();
            } else {
                $imagine = new \Imagine\Gd\Imagine();
            }
        } else {
            $oldMetaReader = $imagine->getMetadataReader();
        }

        // no need to reread meta data, saves lots of memory
        $imagine->setMetadataReader(new DefaultMetadataReader());

        $image = $imagine->load($this->getImageStringForLoad($this->vips, $tiffOptions, $asTiff));
        // readd metadata
        foreach ($this->metadata() as $key => $value) {
            $image->metadata()->offsetSet($key, $value);
        }

        if (null !== $oldMetaReader) {
            $imagine->setMetadataReader($oldMetaReader);
        }

        // if there's only one layer, we can do an early return
        if (1 == \count($this->layers())) {
            return $image;
        }
        $i = 0;
        if (!($this->layers() instanceof Layers)) {
            throw new \RuntimeException('Layers was not the correct class: '.Layers::class.', but '.get_class($image->layers()));
        }
        foreach ($this->layers()->getResources() as $res) {
            if (0 == $i) {
                ++$i;
                continue;
            }
            $newLayer = $imagine->load($this->getImageStringForLoad($res));
            $image->layers()->add($newLayer);
            ++$i;
        }
        try {
            // if there's a gif-delay option, set this
            $delay = $this->vips->get('gif-delay');
            $loop = $this->vips->get('gif-loop');
            $image->layers()->animate('gif', $delay * 10, $loop);
        } catch (Exception $e) {
        }

        return $image;
    }

    public function updatePalette()
    {
        $this->palette = Imagine::createPalette($this->vips);
    }

    public static function isOpaque(VipsImage $vips)
    {
        if (!$vips->hasAlpha()) {
            return true;
        }

        return 255 === (int) $vips->extract_band($vips->bands - 1)->min();
    }

    public static function extendImageWithVips(VipsImage $vips, BoxInterface $box, PointInterface $start)
    {
        if ($vips->bands > 2) {
            $color = new \Imagine\Image\Palette\Color\RGB(new RGB(), [255, 255, 255], 0);
        } else {
            $color = new Gray(new Grayscale(), [255], 0);
        }
        if (!$vips->hasAlpha()) {
            $vips = $vips->bandjoin([255]);
        }
        $new = self::generateImage($box, $color);
        $vips = $new->insert($vips, $start->getX(), $start->getY());

        return $vips;
    }

    protected function applyProfile(ProfileInterface $profile, VipsImage $vips)
    {
        $defaultProfile = $this->getDefaultProfileForInterpretation($vips);
        try {
            $vips = $vips->icc_transform(
                VipsProfile::fromRawData($profile->data())->path(),
                [
                    'embedded' => true,
                    'intent' => 'perceptual',
                    'input_profile' => __DIR__.'/../../resources/colorprofiles/'.$defaultProfile,
                ]
            );
        } catch (Exception $e) {
            // if there's an exception, usually something is wrong with the embedded profile
            // try without
            try {
                $vips = $vips->icc_transform(
                    VipsProfile::fromRawData($profile->data())->path(),
                    [
                        'embedded' => false,
                        'intent' => 'perceptual',
                        'input_profile' => __DIR__.'/../../resources/colorprofiles/'.$defaultProfile,
                    ]
                );
            } catch (Exception $e) {
                throw new RuntimeException('icc_transform error. Message: '.$e->getMessage().'. With defaultProfile: '.$defaultProfile);
            }
        }

        return $vips;
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
     * @param array|null $tiffOptions options to load the tiff image for conversion, eg ['strip' => true]
     * @param mixed      $asTiff
     *
     * @return string
     */
    protected function getImageStringForLoad(VipsImage $res, $tiffOptions = [], $asTiff = false)
    {
        $options = array_merge(['compression' => ForeignTiffCompression::NONE], $tiffOptions);

        if ($asTiff) {
            return $res->tiffsave_buffer($options);
        }

        return $res->pngsave_buffer($options);
    }

    /**
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
     * @param string $path
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
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

        if (!isset($options[self::OPTION_JPEG_QUALITY]) && \in_array($format, ['jpeg', 'jpg', 'pjpeg'], true)) {
            $options[self::OPTION_JPEG_QUALITY] = 92;
        }

        if (!isset($options[self::OPTION_JXL_QUALITY]) && \in_array($format, ['jxl'], true)) {
            $options[self::OPTION_JXL_QUALITY] = 92;
        }
        if (!isset($options[self::OPTION_JXL_LOSSLESS]) && \in_array($format, ['jxl'], true)) {
            $options[self::OPTION_JXL_LOSSLESS] = false;
        }

        if (!isset($options[self::OPTION_PNG_QUALITY]) && \in_array($format, ['png'], true)) {
            $options[self::OPTION_PNG_QUALITY] = 100; // don't do pngquant, if set to 100
        }
        if (!isset($options[self::OPTION_WEBP_QUALITY]) && \in_array($format, ['webp'], true)) {
            $options[self::OPTION_WEBP_QUALITY] = 80; // FIXME: correct value?
        }
        if (!isset($options[self::OPTION_WEBP_LOSSLESS]) && \in_array($format, ['webp'], true)) {
            $options[self::OPTION_WEBP_LOSSLESS] = false;
        }

        if ('png' === $format) {
            if (!isset($options[self::OPTION_PNG_COMPRESSION_LEVEL])) {
                $options[self::OPTION_PNG_COMPRESSION_LEVEL] = 7;
            }
            //FIXME: implement different png_compression_filter
            if (!isset($options[self::OPTION_PNG_COMPRESSION_FILTER])) {
                $options[self::OPTION_PNG_COMPRESSION_FILTER] = 5;
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

    private function applySaveOptions(array $saveOptions, array $options): array
    {
        if (isset($options['vips'])) {
            $saveOptions = array_merge($saveOptions, $options['vips']);
        }

        return $saveOptions;
    }

    /**
     * @param string $format
     */
    private function getSaveMethodAndOptions($format, array $options): array
    {
        $method = null;
        $saveOptions = [];
        if ('jpg' == $format || 'jpeg' == $format) {
            $saveOptions = $this->applySaveOptions(['strip' => $this->strip, 'Q' => $options[self::OPTION_JPEG_QUALITY], 'interlace' => true], $options);
            $method = 'jpegsave';
        } elseif ('jxl' == $format) {
            $jxlOptions = [
                'strip' => $this->strip,
                'lossless' => $options[self::OPTION_JXL_LOSSLESS],
            ];
            if (isset($options[self::OPTION_JXL_DISTANCE])) {
                $jxlOptions['distance'] = $options[self::OPTION_JXL_DISTANCE];
            } else {
                $jxlOptions['Q'] = $options[self::OPTION_JXL_QUALITY];
            }

            if (isset($options[self::OPTION_JXL_EFFORT])) {
                $jxlOptions['effort'] = $options[self::OPTION_JXL_EFFORT];
            }
            $saveOptions = $this->applySaveOptions($jxlOptions, $options);
            $method = 'jxlsave';
        } elseif ('png' == $format) {
            $pngOptions = ['strip' => $this->strip, 'compression' => $options[self::OPTION_PNG_COMPRESSION_LEVEL]];
            if ($options[self::OPTION_PNG_QUALITY] < 100) {
                $this->convertTo8BitMax();
                $pngOptions['Q'] = $options[self::OPTION_PNG_QUALITY];
                $pngOptions['palette'] = true;
            }
            $saveOptions = $this->applySaveOptions($pngOptions, $options);
            $method = 'pngsave';
        } elseif ('webp' == $format) {
            $saveOptions = $this->applySaveOptions([
                'strip' => $this->strip,
                'Q' => $options[self::OPTION_WEBP_QUALITY],
                'lossless' => $options[self::OPTION_WEBP_LOSSLESS],
            ], $options);
            if (isset($options[self::OPTION_WEBP_REDUCTION_EFFORT]) && version_compare(Config::version(), '8.8', '>=')) {
                $saveOptions['reduction_effort'] = $options[self::OPTION_WEBP_REDUCTION_EFFORT];
            }

            $method = 'webpsave';
        } elseif ('tiff' == $format) {
            $saveOptions = $this->applySaveOptions([], $options);
            $method = 'tiffsave';
        } elseif (('heif' == $format || 'heic' == $format) && version_compare(Config::version(), '8.8.0', '>=')) {
            $saveOptions = $this->applySaveOptions(['Q' => $options[self::OPTION_HEIF_QUALITY], 'strip' => $this->strip], $options);
            $method = 'heifsave';
        } elseif (('avif' == $format) && version_compare(Config::version(), '8.9.0', '>=')) {
            $saveOptions = $this->applySaveOptions(['Q' => $options[self::OPTION_AVIF_QUALITY], 'compression' => 'av1', 'strip' => $this->strip], $options);
            $method = 'heifsave';
        } elseif ('gif' == $format) {
            if (version_compare(Config::version(), '8.12.0', '>=') && !(isset($options['force_magick']) && true === $options['force_magick'])) {
                $saveOptions = $this->applySaveOptions([], $options);
                $method = 'gifsave';
            } else {
                $saveOptions = $this->applySaveOptions(['format' => 'gif'], $options);
                $method = 'magicksave';
            }
            $delayProperty = 'delay';
            if (version_compare(Config::version(), '8.9', '<')) {
                $delayProperty = 'gif-delay';
            }
            if (0 === $this->vips->typeof($delayProperty)) {
                $this->layers()->animate('gif', Layers::DEFAULT_GIF_DELAY, 0);
            }
        } elseif ('jp2' == $format) {
            $saveOptions = $this->applySaveOptions(['format' => 'jp2', 'quality' => $options['jp2_quality']], $options);
            $method = 'magicksave';
        } else {
            // use magicksave, if available and possible
            // ppm in vips has some strange issues, save in fallback...
            if ('ppm' !== $format && version_compare(Config::version(), '8.7.0', '>=')) {
                if ('heic' == $format || 'heif' === $format) {
                    $saveOptions = ['quality' => $options[self::OPTION_HEIF_QUALITY], 'format' => $format];
                    $method = 'magicksave';
                }
                // if only the format option is set, we can use that, otherwise we fall back to the alternative
                // since they may be options, magicksave doesn't support yet
                elseif (isset($options['format']) && 1 === \count($options)) {
                    $saveOptions = ['format' => $format];
                    $method = 'magicksave';
                }
            }
        }

        return [$method, $saveOptions];
    }

    private function convertToAlternativeForSave(array $options, self $image, string $format): ImageInterface
    {
        //fallback to imagemagick or gd
        $alt = $image->convertToAlternative();
        // set heif quality, if heif is asked for
        if ('heic' === $format || 'heif' === $format) {
            if ($alt instanceof \Imagine\Imagick\Image && isset($options[self::OPTION_HEIF_QUALITY])) {
                $alt->getImagick()->setCompressionQuality($options[self::OPTION_HEIF_QUALITY]);
            }
        }

        return $alt;
    }

    /**
     * @param $format
     * @param \Imagine\Vips\Image $image
     *
     * @throws \Imagine\Exception\OutOfBoundsException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \Jcupitt\Vips\Exception
     */
    private function joinMultilayer($format, self $image): VipsImage
    {
        $vips = $this->getVips();
        if ((('webp' === $format && version_compare(Config::version(), '8.8.0', '>='))
                || 'gif' === $format)
            && \count($image->layers()) > 1) {
            $vips = $vips->copy();
            $height = $vips->height;
            $width = $vips->width;
            $vips->set('page-height', $height);

            if (!($image->layers() instanceof Layers)) {
                throw new \RuntimeException('Layers was not the correct class: '.Layers::class.', but '.get_class($image->layers()));
            }
            foreach ($image->layers()->getResources() as $_k => $_v) {
                if (0 === $_k) {
                    continue;
                }
                // make frame the same size as the original, if height is not the same (if width is not the same, join will take care of it
                if ($_v->height !== $height) {
                    $_v = $_v->embed(0, 0, $width, $height, ['extend' => Extend::BACKGROUND]);
                }
                $vips = $vips->join($_v, 'vertical', ['expand' => true]);
            }
        }

        return $vips;
    }

    private function convertTo8BitMax(): self
    {
        switch ($this->vips->interpretation) {
            case Interpretation::GREY16:
                $this->vips = $this->vips->colourspace(Interpretation::B_W);
                break;
            case Interpretation::RGB16:
                $this->vips = $this->vips->colourspace(Interpretation::SRGB);
                break;
        }

        return $this;
    }
}
