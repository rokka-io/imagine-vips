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

use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractLayers;

class Layers extends AbstractLayers
{
    /**
     * @var Image
     */
    private $image;

    /**
     * @var int
     */
    private $offset = 0;

    public function __construct(Image $image)
    {
        $this->image = $image;
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
        try {
            return 1;
        } catch (\ImagickException $e) {
            throw new RuntimeException('Failed to count the number of layers', $e->getCode(), $e);
        }
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
        if ($offset > 0) {
            throw new RuntimeException("The vips adapter doesn't support layered images. Only the first one is available.");
        }

        return $this->image;
    }
}
