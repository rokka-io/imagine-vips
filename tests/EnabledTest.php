<?php

declare(strict_types=1);

use Jcupitt\Vips\FFI;
use PHPUnit\Framework\TestCase;

final class EnabledTest extends TestCase
{
    public function testIsVipsEnabled()
    {
        $this->assertTrue(\Imagine\Vips\Imagine::hasVipsInstalled());
    }

    public function testCorrectLibrary()
    {
        // if we have the FFI::class (php-vips 2.0), we need the ffi extension
        if (class_exists(FFI::class)) {
            $this->assertTrue(\extension_loaded('ffi'), "The needed ffi extension was not installed");
            return;
        }
        // otherwise the vips extension needs to be installed
        if (\extension_loaded('vips')) {
            $this->assertTrue(\extension_loaded('vips'), "The needed vips extension was not installed");
            return;
        }
        $this->fail("Neither ffi nor vips were installed");
    }
}
