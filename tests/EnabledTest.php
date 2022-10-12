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
        // if we have ffi installed, it should have the FFI::class
        if (\extension_loaded('ffi')) {
            $this->assertTrue(class_exists(FFI::class));
        } elseif (!\extension_loaded('vips')) {
            // otherwise, there should be the vips extension
            $this->fail('Neither ffi nor vips were loaded');
        } else {
            $this->assertTrue(\extension_loaded('vips'));
        }
    }
}
