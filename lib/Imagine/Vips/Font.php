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

use Imagine\Image\AbstractFont;
use Imagine\Image\Box;
use Imagine\Image\Palette\Color\ColorInterface;
use Jcupitt\Vips\Image as VipsImage;

/**
 * Font implementation using the Imagick PHP extension.
 */
final class Font extends AbstractFont
{
    /**
     * @var \Jcupitt\Vips\Image
     */
    private $vips;

    /**
     * @param VipsImage      $vips
     * @param string         $file
     * @param int            $size
     * @param ColorInterface $color
     */
    public function __construct(VipsImage $vips = null, $file, $size, ColorInterface $color)
    {
        parent::__construct($file, $size, $color);
    }

    /**
     * {@inheritdoc}
     */
    public function box($string, $angle = 0)
    {
        //FIXME, doesn't work, maybe we don't have text support compiled in?
        $text = VipsImage::text($string, ['font' => $this->file, 'size' => $this->size, 'dpi' => 300]);

        return new Box($text->width, $text->height);
    }
}
