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
    public function animate($format, $delay, $loops)
    {
        $vips = $this->image->getVips();
        $vips->set('gif-delay', $delay );
        $vips->set('gif-loop', $loops );

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function coalesce()
    {
        $merged = $this->extractAt(0)->getVips();
        $i = 0;
        foreach ($this->getResources() as $res) {
            if (0 == $i) {
                ++$i;
                continue;
            }
            $merged = $merged->paste($this->offsetGet($i), new Point(0, 0));

            $frame = clone $merged;
            $this->layers[$i] = $frame;
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
            $this->layers[] = $image;
        } else {
            $this->layers[$offset] = $image;
        }
        $this->count++;
        if ($this->count === 2) {
            $this->image->getVips()->set('page-height', $this->image->getVips()->height);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
    }

    public function getResource($offset)
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
     * @return \Jcupitt\Vips\Image[]
     */
    public function getResources()
    {
        $resources = [];
        for($i = 0; $i < count($this); $i++) {
            $resources[$i] = $this->getResource($i);

        }
        return $resources;
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
