<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Effects\EffectsInterface;
use Imagine\Exception\RuntimeException;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Utils\Matrix;
use Jcupitt\Vips\Exception;
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
            $this->image->setVips($this->image->getVips()->gamma(['exponent' => $correction]));
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
            $vips = $this->image->getVips();
            if ($vips->hasAlpha()) {
                $imageWithoutAlpha = $vips->extract_band(0, ['n' => $vips->bands - 1]);
                $alpha = $vips->extract_band($vips->bands - 1, ['n' => 1]);
                $newVips = $imageWithoutAlpha->invert()->bandjoin($alpha);
            } else {
                $newVips = $vips->invert();
            }
            $this->image->setVips($newVips);
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
            $this->image->applyToLayers(function (\Jcupitt\Vips\Image $vips) {

                if (Interpretation::CMYK == $vips->interpretation) {
                    $vips = $vips->icc_import(['embedded' => true]);
                }
                $vips = $vips->colourspace(Interpretation::B_W);
                // remove icc_profile_data, since this can be wrong

                return $vips;
            });
            try {
                $vips->remove('icc-profile-data');
            } catch (\Jcupitt\Vips\Exception $e) {
                //throws an exception if not existing, so just move on
            }
            $this->image->setVips($vips, true);
        } catch (Exception $e) {
            dump($e);die;
            throw new RuntimeException('Failed to grayscale the image', $e->getCode(), $e);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function colorize(ColorInterface $color)
    {
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * {@inheritdoc}
     */
    public function sharpen()
    {
        try {
            $this->image->setVips($this->image->getVips()->sharpen());
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
            $this->image->setVips($this->image->getVips()->gaussblur($sigma));
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to blur the image', $e->getCode(), $e);
        }

        return $this;
    }

    public function brightness($brightness)
    {
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter. You can use modulate() instead.');
    }

    public function convolve(Matrix $matrix)
    {
        throw new \RuntimeException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    /**
     * Modulates an image for brightness, saturation and hue.
     *
     * @param int $brightness Multiplier in percent
     * @param int $saturation Multiplier in percent
     * @param int $hue        rotate by degrees on the color wheel, 0/360 don't change anything
     *
     * @return RokkaImageInterface
     */

    public function modulate(int $brightness = 100, int $saturation = 100, int $hue = 0): RokkaImageInterface
    {
        $originalColorspace = $this->vips->interpretation;
        $lch = $this->vips->colourspace(Interpretation::LCH);
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
        $this->setVips($image);

        return $this;
    }
}
