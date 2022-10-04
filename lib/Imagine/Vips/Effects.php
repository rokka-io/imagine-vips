<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Effects\EffectsInterface;
use Imagine\Exception\NotSupportedException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Utils\Matrix;
use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Image as VipsImage;
use Jcupitt\Vips\Interpretation;

/**
 * Effects implementation using the Vips PHP extension.
 */
class Effects implements EffectsInterface
{
    /**
     * @var Image
     */
    private $image;

    public function __construct(Image $image)
    {
        $this->image = $image;
    }

    /**
     * {@inheritdoc}
     */
    public function gamma($correction)
    {
        try {
            $this->image->applyToLayers(function (VipsImage $vips) use ($correction): VipsImage {
                return $vips->gamma(['exponent' => $correction]);
            });
        } catch (Exception $e) {
            throw new RuntimeException('Failed to apply gamma correction to the image', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function negative()
    {
        try {
            $this->image->applyToLayers(function (VipsImage $vips): VipsImage {
                if ($vips->hasAlpha()) {
                    $imageWithoutAlpha = $vips->extract_band(0, ['n' => $vips->bands - 1]);
                    $alpha = $vips->extract_band($vips->bands - 1, ['n' => 1]);
                    $newVips = $imageWithoutAlpha->invert()->bandjoin($alpha);
                } else {
                    $newVips = $vips->invert();
                }

                return $newVips;
            });
        } catch (Exception $e) {
            throw new RuntimeException('Failed to negate the image', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function grayscale()
    {
        try {
            $this->image->applyToLayers(function (VipsImage $vips): VipsImage {
                //FIXME: maybe more interpretations don't work
                if (Interpretation::CMYK == $vips->interpretation) {
                    $vips = $vips->icc_import(['embedded' => true]);
                }
                $vips = $vips->colourspace(Interpretation::B_W);
                // remove icc_profile_data, since this can be wrong

                return $vips;
            });
            try {
                $this->image->vipsCopy();
                $this->image->getVips()->remove('icc-profile-data');
            } catch (\Jcupitt\Vips\Exception $e) {
                //throws an exception if not existing, so just move on
            }
        } catch (Exception $e) {
            throw new RuntimeException('Failed to grayscale the image', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(ColorInterface $color)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen()
    {
        try {
            $this->image->applyToLayers(function (VipsImage $vips): VipsImage {
                $oldinterpretation = $vips->interpretation;
                $vips = $vips->sharpen();
                if ($oldinterpretation != $vips->interpretation) {
                    $vips = $vips->colourspace($oldinterpretation);
                }

                return $vips;
            });
        } catch (Exception $e) {
            throw new RuntimeException('Failed to sharpen the image', $e->getCode(), $e);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function blur($sigma = 1)
    {
        try {
            $this->image->applyToLayers(function (VipsImage $vips) use ($sigma): VipsImage {
                return $vips->gaussblur($sigma);
            });
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to blur the image', $e->getCode(), $e);
        }

        return $this;
    }

    public function brightness($brightness)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter. You can use modulate() instead.');
    }

    public function convolve(Matrix $matrix)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * Modulates an image for brightness, saturation and hue.
     *
     * @param int $brightness Multiplier in percent
     * @param int $saturation Multiplier in percent
     * @param int $hue        rotate by degrees on the color wheel, 0/360 don't change anything
     */
    public function modulate(int $brightness = 100, int $saturation = 100, int $hue = 0): self
    {
        $vips = $this->image->getVips();
        $originalColorspace = $vips->interpretation;
        $lch = $vips->colourspace(Interpretation::LCH);
        $multiply = [$brightness / 100, $saturation / 100, 1];
        if ($lch->hasAlpha()) {
            $multiply[] = 1;
        }
        $lch = $lch->multiply($multiply);

        if (0 != $hue) {
            $add = [0, 0, $hue];
            if ($lch->hasAlpha()) {
                $add[] = 0;
            }
            $lch = $lch->add($add);
        }
        // we can't convert from lch to rgb, needs srgb.
        if (Interpretation::RGB === $originalColorspace) {
            $originalColorspace = Interpretation::SRGB;
        }
        $image = $lch->colourspace($originalColorspace);
        $this->image->setVips($image);

        return $this;
    }
}
