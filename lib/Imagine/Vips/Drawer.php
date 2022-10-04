<?php

namespace Imagine\Vips;

use Imagine\Draw\DrawerInterface;
use Imagine\Exception\NotSupportedException;
use Imagine\Image\AbstractFont;
use Imagine\Image\BoxInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\PointInterface;
use Jcupitt\Vips\Align;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\Extend;
use Jcupitt\Vips\Image as VipsImage;

class Drawer implements DrawerInterface
{
    /**
     * @var \Imagine\Vips\Image
     */
    private $image;

    public function __construct(Image $image)
    {
        $this->image = $image;
    }

    public function text(
        $string,
        AbstractFont $font,
        PointInterface $position,
        $angle = 0,
        $width = null
    ) {
        $this->textWithHeight($string, $font, $position, $angle, $width);

        return $this;
    }

    /**
     * Draw text onto an image.
     *
     * This code is not totally tested, but works basically.
     *
     * @param $string
     * @param int    $angle
     * @param null   $width
     * @param null   $height
     * @param string $align
     *
     * @throws \FontLib\Exception\FontNotFoundException
     * @throws \Imagine\Exception\NotSupportedException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \Jcupitt\Vips\Exception
     */
    public function textWithHeight(
        $string,
        AbstractFont $font,
        PointInterface $position,
        $angle = 0,
        $width = null,
        $height = null,
        $align = 'centre'
    ) {
        $size = $font->getSize();
        $resize = 4;
        $colors = Image::getColorArrayAlpha($font->getColor());
        $alpha = array_pop($colors);
        $FL = \FontLib\Font::load($font->getFile());

        switch ($align) {
            case 'left':
                $vipsAlign = Align::LOW;
                break;
            case 'right':
                $vipsAlign = Align::HIGH;
                break;
            default:
                $vipsAlign = Align::CENTRE;
        }
        $text = $this->image->getVips()->text($string, [
            'font' => $FL->getFontFullName().' '.$size * $resize,
            'fontfile' => $font->getFile(),
            'width' => $width * $resize,
            'align' => $vipsAlign,
            'spacing' => 0,
        ]);

        if (0 !== $angle) {
            $text = $text->similarity(['angle' => $angle]);
        }

        $red = $text->newFromImage($colors)->copy(['interpretation' => 'srgb']);
        $overlay = $red->bandjoin($text);

        $overlay = $overlay->multiply([1, 1, 1, (255 - $alpha) / 255]);

        $overlay = $overlay->resize(1 / $resize);

        $newWidth = $overlay->width;
        $newHeight = $overlay->height;
        if (null !== $width && $overlay->width < $width) {
            $newWidth = $width;
        }
        if (null !== $height && $overlay->height < $height) {
            $newHeight = $height;
        }
        if ($newHeight !== $overlay->height || $newWidth !== $overlay->width) {
            $pixel = VipsImage::black(1, 1)->cast(BandFormat::UCHAR);
            $pixel = $pixel->embed(0, 0, $newWidth, $newHeight, ['extend' => Extend::COPY]);

            if ('centre' === $align) {
                $overlay = $pixel->insert($overlay, (int) ($newWidth - $overlay->width) / 2, ($newHeight - $overlay->height) / 2);
            } elseif ('left' === $align) {
                $overlay = $pixel->insert($overlay, (int) 0, ($newHeight - $overlay->height) / 2);
            } elseif ('right' === $align) {
                $overlay = $pixel->insert($overlay, (int) $newWidth - $overlay->width, ($newHeight - $overlay->height) / 2);
            }
        }

        $vips = $this->image->getVips();
        if (!$vips->hasAlpha()) {
            $vips = $vips->bandjoin([0]);
        }
        //$vips = $vips->premultiply();
        $vips = $this->image->pasteVipsImage($overlay, $position);
        $this->image->setVips($vips);
    }

    public function arc(PointInterface $center, BoxInterface $size, $start, $end, ColorInterface $color, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function chord(PointInterface $center, BoxInterface $size, $start, $end, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function dot(PointInterface $position, ColorInterface $color)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function circle(PointInterface $center, $radius, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function ellipse(PointInterface $center, BoxInterface $size, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function line(PointInterface $start, PointInterface $end, ColorInterface $outline, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function pieSlice(PointInterface $center, BoxInterface $size, $start, $end, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function polygon(array $coordinates, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }

    public function rectangle(PointInterface $leftTop, PointInterface $rightBottom, ColorInterface $color, $fill = false, $thickness = 1)
    {
        throw new NotSupportedException(__METHOD__.' not implemented yet in the vips adapter.');
    }
}
