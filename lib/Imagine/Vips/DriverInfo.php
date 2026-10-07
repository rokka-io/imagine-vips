<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Driver\AbstractInfo;
use Imagine\Exception\NotSupportedException;
use Imagine\Image\Box;
use Imagine\Image\Format;
use Imagine\Image\FormatList;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Image\Palette\RGB;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image as VipsImage;

/**
 * Information about the running libvips library.
 */
class DriverInfo extends AbstractInfo
{
    /**
     * @var static|null
     */
    private static $instance;

    /**
     * @var array<string, bool>
     */
    private $formatSupport = [];

    final protected function __construct()
    {
        try {
            new Imagine();
            $engineVersion = Config::version();
        } catch (\Throwable $exception) {
            throw new NotSupportedException('Vips driver not available', 0, $exception);
        }

        $normalizedVersion = self::normalizeVersion($engineVersion);

        parent::__construct($engineVersion, $normalizedVersion, $engineVersion, $normalizedVersion);
    }

    /**
     * {@inheritdoc}
     */
    public static function get($required = true)
    {
        if (null === self::$instance) {
            try {
                self::$instance = new static();
            } catch (NotSupportedException $exception) {
                if ($required) {
                    throw $exception;
                }

                return null;
            }
        }

        return self::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function requirePaletteSupport(PaletteInterface $palette): void
    {
        if (!$palette instanceof RGB && !$palette instanceof CMYK && !$palette instanceof Grayscale) {
            throw new NotSupportedException('Vips supports only RGB, CMYK and Grayscale palettes');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isFormatSupported($format)
    {
        $format = $format instanceof Format ? $format : Format::get($format);
        if (null === $format) {
            return false;
        }

        $id = $format->getID();
        if (!isset($this->formatSupport[$id])) {
            $this->formatSupport[$id] = $this->canRoundTripFormat($format);
        }

        return $this->formatSupport[$id];
    }

    /**
     * {@inheritdoc}
     */
    protected function checkFeature($feature): void
    {
        switch ($feature) {
            case self::FEATURE_COLORPROFILES:
            case self::FEATURE_COLORSPACECONVERSION:
                $this->requireOperation('icc_transform');
                return;
            case self::FEATURE_TEXTFUNCTIONS:
                $this->requireOperation('text');
                return;
            case self::FEATURE_ROTATEIMAGEWITHCORRECTSIZE:
                if (version_compare($this->getEngineVersion(), '8.6', '<')) {
                    throw new NotSupportedException('Vips rotation requires libvips 8.6 or later');
                }
                return;
            case self::FEATURE_GRAYSCALEEFFECT:
            case self::FEATURE_NEGATEIMAGE:
            case self::FEATURE_SHARPENIMAGE:
            case self::FEATURE_MULTIPLELAYERS:
            case self::FEATURE_ADDLAYERSTOEMPTYIMAGE:
            case self::FEATURE_TRANSPARENCY:
            case self::FEATURE_DETECTGRAYCOLORSPACE:
                return;
            case self::FEATURE_COALESCELAYERS:
                $this->requireOperation('composite');
                return;
            case self::FEATURE_COLORIZEIMAGE:
            case self::FEATURE_CONVOLVEIMAGE:
            case self::FEATURE_CUSTOMRESOLUTION:
            case self::FEATURE_EXPORTWITHCUSTOMRESOLUTION:
            case self::FEATURE_DRAWFILLEDCHORDSCORRECTLY:
            case self::FEATURE_DRAWUNFILLEDCIRCLESWITHTICHKESSCORRECTLY:
            case self::FEATURE_DRAWUNFILLEDELLIPSESWITHTICHKESSCORRECTLY:
            case self::FEATURE_GETCMYKCOLORSCORRECTLY:
            case self::FEATURE_EXPORTWITHCUSTOMJPEGSAMPLINGFACTORS:
                throw new NotSupportedException(sprintf('Feature %s is not supported by the Vips adapter', $feature));
        }

        throw new NotSupportedException(sprintf('Unknown Vips feature %s', $feature));
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSupportedFormats()
    {
        $formats = [];
        foreach (Format::getAll() as $format) {
            if ($this->isFormatSupported($format)) {
                $formats[] = $format;
            }
        }

        return new FormatList($formats);
    }

    protected function canRoundTripFormat(Format $format): bool
    {
        // Probe the adapter itself so optional codecs and saving fallbacks are included.
        // Individual results are cached by format ID, including unsupported formats.
        // AbstractInfo also caches the complete list after it is requested.
        try {
            $imagine = new Imagine();
            $options = [];
            if (Format::ID_HEIC === $format->getID()) {
                $options[Image::OPTION_HEIF_QUALITY] = 75;
            } elseif (Format::ID_AVIF === $format->getID()) {
                $options[Image::OPTION_AVIF_QUALITY] = 75;
            }
            $data = $imagine->create(new Box(1, 1))->get($format->getID(), $options);
            $imagine->load($data);

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    private static function normalizeVersion(string $version): string
    {
        return preg_match('/(\d+\.\d+\.\d+)/', $version, $matches) ? $matches[1] : '';
    }

    private function requireOperation(string $operation): void
    {
        try {
            // Use image operations supported by both php-vips 1 and 2.
            if ('text' === $operation) {
                VipsImage::text('Vips');

                return;
            }
            $image = VipsImage::black(1, 1, ['bands' => 3])->copy(['interpretation' => 'srgb']);
            if ('icc_transform' === $operation) {
                $image->icc_transform('srgb', ['input_profile' => 'srgb']);
            } else {
                $image->composite([$image], ['over']);
            }
        } catch (\Throwable $exception) {
            throw new NotSupportedException(sprintf('Vips operation %s is not available', $operation), 0, $exception);
        }
    }
}
