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
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\VipsProfile;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Exception;
use Jcupitt\Vips\FFI;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Imagine implementation using the Vips PHP extension.
 */
class Imagine extends AbstractImagine
{
    /**
     * Initialize imagine library.
     *
     * You can also apply global vips settings
     *
     * Key -> calls the following php function
     *
     * max_mem -> vips_cache_set_max_mem
     * max_ops -> vips_cache_set_max
     * max_files -> vips_cache_set_max_files
     * concurrency -> vips_concurrency_set
     *
     * @throws RuntimeException
     */
    public function __construct(array $config = [])
    {
        if (\class_exists(FFI::class)) {
            if (!\extension_loaded('ffi')) {
                throw new RuntimeException('ffi extension not installed');
            }
        } else {
            if (!\extension_loaded('vips')) {
                throw new RuntimeException('vips extension not installed');
            }
        }
        foreach ($config as $key => $value) {
            switch ($key) {
                case 'max_mem':
                    Config::cacheSetMaxMem($value);
                    break;
                case 'max_ops':
                    Config::cacheSetMax($value);
                    break;
                case 'max_files':
                    Config::cacheSetMaxFiles($value);
                    break;
                case 'concurrency':
                    Config::concurrencySet($value);
                    break;
            }
        }
    }

    public function open($path, $loadOptions = [])
    {
        $path = $this->checkPath($path);

        try {
            $loader = VipsImage::findLoad($path);
            $loadOptions = $this->getLoadOptions($loader, $loadOptions);
            $vips = VipsImage::newFromFile($path, $loadOptions);
            if ('VipsForeignLoadTiffFile' === $loader) {
                $vips = $this->removeUnnecessaryAlphaChannels($vips);
            }

            return new Image($vips, self::createPalette($vips), $this->getMetadataReader()->readFile($path));
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Unable to open image %s', $path), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create(BoxInterface $size, ColorInterface $color = null)
    {
        $vips = Image::generateImage($size, $color);

        return new Image($vips, self::createPalette($vips), new MetadataBag());
    }

    /**
     * {@inheritdoc}
     */
    public function load($string, $loadOptions = [])
    {
        try {
            $loader = VipsImage::findLoadBuffer($string);
            $loadOptions = $this->getLoadOptions($loader, $loadOptions);
            $vips = VipsImage::newFromBuffer($string, '', $loadOptions);
            if ('VipsForeignLoadTiffBuffer' === $loader) {
                $vips = $this->removeUnnecessaryAlphaChannels($vips);
            }

            return new Image($vips, self::createPalette($vips), $this->getMetadataReader()->readData($string));
        } catch (\Exception $e) {
            // sometimes we have files with colorspaces vips does not support (heic files for eaxample),
            // let's try loading them with imagick,
            // and convert them to png and then load again with vips.
            // not the fastest thing, of course, but fine for our usecase
            if (false !== strpos($e->getMessage(), 'magickload_buffer: unsupported colorspace') && class_exists('Imagick')) {
                $im = new \Imagick();
                $im->readImageBlob($string);
                $im->setFormat('png');

                return $this->load($im->getImageBlob(), $loadOptions);
            }

            if ('gifload_buffer: Image is defective, decoding aborted' === $e->getMessage()) {
                throw new RuntimeException('Image is defective, decoding aborted', $e->getCode(), $e);
            }

            throw new RuntimeException('Could not load image from string. Message: '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($resource)
    {
        if (!\is_resource($resource)) {
            throw new InvalidArgumentException('Variable does not contain a stream resource');
        }

        $content = stream_get_contents($resource);

        return $this->load($content);
    }

    /**
     * {@inheritdoc}
     */
    public function font($file, $size, ColorInterface $color)
    {
        return new Font($file, $size, $color);
    }

    /**
     * Returns the palette corresponding to an VIPS resource colorspace.
     *
     * @throws NotSupportedException
     *
     * @return PaletteInterface
     */
    public static function createPalette(VipsImage $vips)
    {
        switch ($vips->interpretation) {
            case Interpretation::RGB:
            case Interpretation::RGB16:
            case Interpretation::SRGB:
                $palette = new RGB();
                break;
            case Interpretation::CMYK:
                $palette = new CMYK();
                break;
            case Interpretation::GREY16:
            case Interpretation::B_W:
                $palette = new Grayscale();
                break;
            default:
                throw new NotSupportedException('Only RGB, CMYK and Grayscale colorspace are currently supported');
        }
        try {
            $profile = $vips->get('icc-profile-data');
            $palette->useProfile(VipsProfile::fromRawData($profile));
        } catch (Exception $e) {
        }

        return $palette;
    }

    /**
     * Checks, if the necessary Libraries are installed.
     *
     * @return bool
     */
    public static function hasVipsInstalled()
    {
        try {
            // this method only exists in php-vips 2.0
            if (\class_exists(FFI::class)) {
                // if ffi extension is not installed, we can't use php-vips
                if (!\extension_loaded('ffi')) {
                    return false;
                }
                // this will throw an exception, if libvips is not installed
                // will return false in the catch block
                Config::version();

                return true;
            }
            // if we're still on php-vips 1.0, check if 'vips' extension is installed
            return \extension_loaded('vips');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getLoadOptions($loader, $loadOptions = [])
    {
        $options = [];
        switch ($loader) {
            case 'VipsForeignLoadJpegFile':
            case 'VipsForeignLoadJpegBuffer':
                $options['autorotate'] = true;
                break;
            case 'VipsForeignLoadHeifFile':
            case 'VipsForeignLoadHeifBuffer':
                $options['autorotate'] = true;
                $options['n'] = -1; // not sure this should be enabled by default, to discuss
                break;
            case 'VipsForeignLoadGifFile':
            case 'VipsForeignLoadGifBuffer':
                $options['n'] = -1; // not sure this should be enabled by default, to discuss
                break;
        }
        $options = array_merge($loadOptions, $options);
        // FIXME: remove not allowed options

        if (isset($options['shrink'])) {
            switch ($loader) {
                case 'VipsForeignLoadJpegFile':
                case 'VipsForeignLoadJpegBuffer':
                case 'VipsForeignLoadWebpFile':
                case 'VipsForeignLoadWebpBuffer':
                    break;
                default:
                    unset($options['shrink']);
               }
        }

        return $options;
    }

    /**
     * Some files (esp. tiff) can have more than one alpha layer.. We just remove all except one.
     * Not sure, this is the right approach, but good enough for now.
     *
     * @param Image $vips
     *
     * @return Image
     */
    protected function removeUnnecessaryAlphaChannels($vips)
    {
        $lastVipsWithAlpha = $vips;

        while ($vips->hasAlpha()) {
            $lastVipsWithAlpha = $vips;
            $vips = $vips->extract_band(0, ['n' => $vips->bands - 1]);
        }

        return $lastVipsWithAlpha;
    }
}
