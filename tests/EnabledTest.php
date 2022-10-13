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
        // if we have the FFI::class, we need the ffi extension
        if (class_exists(FFI::class)) {
            $this->assertTrue(\extension_loaded('ffi'), 'The needed ffi extension was not installed');
            // and check we get a string
            $this->assertIsString(\Jcupitt\Vips\Config::version());

            return;
        }
        $this->assertTrue(\extension_loaded('vips'), 'The needed vips extension was not installed');
        // and check we get a string
        $this->assertIsString(\Jcupitt\Vips\Config::version());
    }
}
