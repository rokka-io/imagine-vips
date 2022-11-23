<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Image\AbstractFont;
use Imagine\Image\Box;
use Imagine\Image\Palette\Color\ColorInterface;
use Jcupitt\Vips\Image as VipsImage;

/**
 * Font implementation using the Vips PHP extension.
 */
final class Font extends AbstractFont
{
    /**
     * @param string $file
     * @param int    $size
     */
    public function __construct($file, $size, ColorInterface $color)
    {
        parent::__construct($file, $size, $color);
    }

    /**
     * {@inheritdoc}
     */
    public function box($string, $angle = 0)
    {
        $FL = \FontLib\Font::load($this->file);

        $fontSize = (int)($this->size * (96 / 72));
        $text = VipsImage::text($string, [
            'fontfile' => $this->file,
            'font' => $FL->getFontFullName() . ' ' . $fontSize,
            'dpi' => 72,
            'height' => $fontSize
        ]);

        return new Box($text->width, $text->height);
    }
}
