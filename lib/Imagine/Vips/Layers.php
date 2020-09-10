<?php

/*
 * This file is part of the Imagine package.
 *
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Vips;

use Imagine\Exception\NotSupportedException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractLayers;
use Imagine\Image\Metadata\MetadataBag;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Image as VipsImage;

class Layers extends AbstractLayers
{

    public const DEFAULT_GIF_DELAY = 100;

    /**
     * @var Image
     */
    private $image;

    /**
     * @var Image[]
     */
    private $layers = [];

    private $resources = [];

    /**
     * @var int
     */
    private $offset = 0;

    /**
     * @var int
     */
    private $count = 0;

    public function __construct(Image $image, Layers $layers = null)
    {
        $this->image = $image;

        $vips = $image->getVips();
        //try extracting layers
        if ($layers !== null) {
            $this->layers = $layers->layers;
            $this->resources = $layers->resources;
            $this->count = count($layers->resources) + count($this->layers);

        } else {
            try {
                if ($vips->get('page-height')) {
                    $page_height = $vips->get('page-height');
                    $total_height = $vips->height;
                    $total_width = $vips->width;
                    for ($i = 0; $i < ($total_height / $page_height); ++$i) {
                        $this->resources[$i] = $vips->crop(0, $page_height * $i, $total_width, $page_height);
                    }
                    $image->setVips($this->resources[0]);
                }
            } catch (Exception $e) {
                $this->resources[0] = $vips;
            }
            $this->count = count($this->resources);
            //always set the first layer
            $this->layers[0] = $this->image;
            // we don't need it, it's in $this->image
            unset( $this->resources[0]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function merge()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function animate($format, $delay, $loops): self
    {
        $vips = $this->image->vipsCopy();
        if (version_compare(vips_version(), '8.9', '<')) {
            $vips->set('gif-delay', $delay / 10);
        } else {
            $vips->set('delay', array_fill(0, count($this), $delay));
        }
        $vips->set('gif-loop', $loops );
        if($vips->typeof('page-height') === 0) {
            $vips->set("page-height", (int) ($vips->height / count($this)));
        }
        $this->vips = $vips;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function coalesce(): self
    {
        $merged = $this->extractAt(0)->getVips();
        $width = $merged->width;
        $height = $merged->height;
        $i = 0;
        foreach ($this->getResources() as $res) {
            if (0 == $i) {
                ++$i;
                continue;
            }

            // if width and height are the same and it is opaque, we don't have to composite
            if (($res->width === $width && $res->height === $height) && Image::isOpaque($res)) {
                ++$i;
                continue;
            }

            $merged = $merged->composite([$res], [BlendMode::OVER])->copyMemory();

            $frame = clone $merged;
            unset($this->layers[$i]);
            $this->resources[$i] = $frame;
            ++$i;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->extractAt($this->offset);
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->offset;
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        ++$this->offset;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->offset = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return $this->offset < count($this);
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return $this->count;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        return is_int($offset) && $offset >= 0 && $offset < count($this);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        return $this->extractAt($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $image)
    {
        if ($offset === null) {
            $offset = $this->count;
        }

        if (!(isset($this->layers[$offset]) || isset($this->resources[$offset]))) {
            $this->count++;
        }

        $this->layers[$offset] = $image;

        if (isset($this->resources[$offset])) {
            unset($this->resources[$offset]);
        }

        if ($this->count === 2) {
            $this->image->vipsCopy();
            $this->image->getVips()->set('page-height', $this->image->getVips()->height);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        throw new NotSupportedException("Removing frames is not supported yet.");
    }

    public function getResource($offset): VipsImage
    {
        if ($offset === 0) {
            return $this->image->getVips();
        }
        // if we already have an image object for this $offset, use this
       if (isset($this->layers[$offset])) {
            return $this->layers[$offset]->getVips();
        } else {
            return $this->resources[$offset];
        }
    }

     /**
     * @return VipsImage[]
     */
    public function getResources(): array
    {
        $resources = [];
        $count = count($this);
        for($i = 0; $i < $count; $i++) {
            $resources[$i] = $this->getResource($i);

        }
        return $resources;
    }

    /**
     * Returns the delays in milliseconds per frame as array (or null, if not set yet)
     *
     * @return array|null
     * @throws \Imagine\Exception\RuntimeException
     */
    public function getDelays() {
        if (version_compare(vips_version(), '8.9', '<')) {
            throw new RuntimeException('This feature needs at least vips 8.9');
        }
        $vips = $this->image->getVips();
        try {
            return $vips->get('delay');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sets the delays for all the frames in an animated image
     *
     * @param int[] $delays
     *
     * @throws \Jcupitt\Vips\Exception
     */
    public function setDelays(array $delays) {
        if (version_compare(vips_version(), '8.9', '<')) {
            throw new RuntimeException('This feature needs at least vips 8.9');
        }
        $vips = $this->image->vipsCopy();
        $vips->set('delay', $delays);
    }

    /**
     * Gets delay in milliseconds for a single frame
     *
     * @return int|null  Delay in miliseconds
     * @throws \Imagine\Exception\RuntimeException
     */
    public function getDelay($index) {
        if (version_compare(vips_version(), '8.9', '<')) {
            throw new RuntimeException('This feature needs at least vips 8.9');
        }
        $vips = $this->image->getVips();
        try {
            $delays = $this->getDelays();
            if ($delays === null) {
                return null;
            }
            if (isset($delays[$index])) {
               return $delays[$index];
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sets delay for a single frame
     *
     * @param $index int Frame number
     * @param $delay int Delay in miliseconds
     *
     * @throws \Imagine\Exception\NotSupportedException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \Jcupitt\Vips\Exception
     */
    public function setDelay($index, $delay) {
        if (version_compare(vips_version(), '8.9', '<')) {
            throw new RuntimeException('This feature needs at least vips 8.9');
        }
        $vips = $this->image->getVips();
        $delays = $this->getDelays();
        if ($delays === null) {
            $delays = array_fill(0,count($this), self::DEFAULT_GIF_DELAY);
            $this->setDelays($delays);
        }
        $oldValue = null;
        if (isset($delays[$index])) {
            $oldValue = $delays[$index];
        }
        if ($oldValue != $delay) {
            $delays[$index] = $delay;
            $this->setDelays($delays);
        }
    }

    /**
     * Tries to extract layer at given offset.
     *
     * @param int $offset
     *
     * @throws RuntimeException
     *
     * @return Image
     */
    private function extractAt($offset): Image
    {
        if ($offset === 0) {
            return $this->image;
        }
        if (!isset($this->layers[$offset])) {
            try {
                $this->layers[$offset] = new Image($this->resources[$offset], $this->image->palette(), new MetadataBag());
                //unset resource, not needed anymore, directly from the image object fetched from now on
                unset($this->resources[$offset]);
            } catch (Exception $e) {
                throw new RuntimeException(sprintf('Failed to extract layer %d', $offset), $e->getCode(), $e);
            }
        }

        return $this->layers[$offset];
    }
}
