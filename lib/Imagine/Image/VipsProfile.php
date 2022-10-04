<?php

/*
 * This file is part of the imagine-vips package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Image;

use Imagine\Exception\InvalidArgumentException;

class VipsProfile implements ProfileInterface
{
    private $data;
    private $name;
    private $path = null;

    public function __construct($name, $data, $path = null)
    {
        $this->name = $name;
        $this->data = $data;
        $this->path = $path;
    }

    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function path()
    {
        if (null == $this->path) {
            self::getTmpFileFromRawData($this->data);
        }

        return $this->path;
    }

    public static function fromRawData(string $profile)
    {
        $profileFile = self::getTmpFileFromRawData($profile);

        return new self(basename($profileFile), $profile, $profileFile);
    }

    /**
     * Creates a profile from a path to a file.
     *
     * @param string $path
     *
     * @throws InvalidArgumentException In case the provided path is not valid
     *
     * @return self
     */
    public static function fromPath($path)
    {
        if (!file_exists($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Path %s is an invalid profile file or is not readable', $path));
        }

        return new self(basename($path), file_get_contents($path), $path);
    }

    protected static function getTmpFileFromRawData(string $profile): string
    {
        $profileMd5 = md5($profile);
        $profileFile = sys_get_temp_dir().'/imagine-vips-profile-'.$profileMd5.'.icc';
        if (!file_exists($profileFile)) {
            file_put_contents($profileFile, $profile);
        }

        return $profileFile;
    }
}
