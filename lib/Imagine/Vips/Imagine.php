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

use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\NotSupportedException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\RGB;
use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Imagine implementation using the Vips PHP extension.
 */
class Imagine extends AbstractImagine
{
    /**
     * @throws RuntimeException
     */
    public function __construct()
    {
        if (!extension_loaded('vips')) {
            throw new RuntimeException('Vips not installed');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($path)
    {
        $path = $this->checkPath($path);

        try {
            $loadOptions = $this->getLoadOptions(VipsImage::findLoad($path));
            $vips = VipsImage::newFromFile($path, $loadOptions);
            $vips = $this->importIccProfile($vips);

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
    public function load($string)
    {
        try {
            $loadOptions = $this->getLoadOptions(VipsImage::findLoadBuffer($string));
            $vips = VipsImage::newFromBuffer($string, '', $loadOptions);
            $vips = $this->importIccProfile($vips);

            return new Image($vips, self::createPalette($vips), $this->getMetadataReader()->readData($string));
        } catch (\Exception $e) {
            throw new RuntimeException('Could not load image from string', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($resource)
    {
        if (!is_resource($resource)) {
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
        return new Font(null, $file, $size, $color);
    }

    /**
     * Returns the palette corresponding to an VIPS resource colorspace.
     *
     * @param VipsImage $vips
     *
     * @throws NotSupportedException
     *
     * @return CMYK|Grayscale|RGB
     */
    public static function createPalette(VipsImage $vips)
    {
        switch ($vips->interpretation) {
            case Interpretation::RGB:
            case Interpretation::RGB16:
            case Interpretation::SRGB:
                return new RGB();
            case Interpretation::CMYK:
            case Interpretation::LAB:
                return new CMYK();
            case Interpretation::GREY16:
            case Interpretation::B_W:
                return new Grayscale();
            default:
                throw new NotSupportedException('Only RGB, CMYK and Grayscale colorspace are currently supported');
        }
    }

    /**
     * @param $vips
     *
     * @return \Jcupitt\Vips\Image
     */
    protected function importIccProfile($vips)
    {
        try {
            return $vips->icc_import(['embedded' => true]);
        } catch (Exception $e) {
            //no problem if no icc is embedded
        }

        return $vips;
    }

    protected function getLoadOptions($loader)
    {
        $options = [];
        switch ($loader) {
            case 'VipsForeignLoadJpegFile':
            case 'VipsForeignLoadJpegBuffer':
                $options['autorotate'] = true;
        }

        return $options;
    }
}
