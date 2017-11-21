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

use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractLayers;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Point;
use Jcupitt\Vips\Exception;

class Layers extends AbstractLayers
{
    /**
     * @var Image
     */
    private $image;

    private $layers = [];

    private $resources = [];

    /**
     * @var int
     */
    private $offset = 0;

    public function __construct(Image $image)
    {
        $this->image = $image;

        $vips = $image->getVips();
        //try extracting layers
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
    public function animate($format, $delay, $loops)
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function coalesce()
    {
        $merged = $this->offsetGet(0);
        $i = 0;
        foreach ($this->resources as $res) {
            if (0 == $i) {
                ++$i;
                continue;
            }
            $merged = $merged->paste($this->offsetGet($i), new Point(0, 0));

            $frame = clone $merged;
            $this->layers[$i] = $frame;
            $this->resources[$i] = $frame->getVips();
            ++$i;
        }
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
        return 1;
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
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
    }

    public function getResource($offset)
    {
        return $this->resources[$offset];
    }

    public function setResource($offset, \Jcupitt\Vips\Image $resource)
    {
        if ($resource->interpretation != $this->resources[$offset]) {
            if (isset($this->layers[$offset])) {
                $this->layers[$offset]->updatePalette();
            }
        }
        $this->resources[$offset] = $resource;
    }

    /**
     * @return \Jcupitt\Vips\Image[]
     */
    public function getResources()
    {
        return $this->resources;
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
    private function extractAt($offset)
    {
        if (!isset($this->layers[$offset])) {
            try {
                $this->layers[$offset] = new Image($this->resources[$offset], $this->image->palette(), new MetadataBag());
            } catch (Exception $e) {
                throw new RuntimeException(sprintf('Failed to extract layer %d', $offset), $e->getCode(), $e);
            }
        }

        return $this->layers[$offset];
    }
}
