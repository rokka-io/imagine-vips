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
        $version = \Composer\InstalledVersions::getVersion("jcupitt/vips");
        // if we have version 2.1 of the extension, we need the ffi extension
        if ( version_compare($version, "2.1.0", ">=")) {
            $this->assertTrue(\extension_loaded('ffi'), 'The needed ffi extension was not installed');
            // and check we get a string
            $this->assertIsString(\Jcupitt\Vips\Config::version());

            return;
        }
        // otherwise the vips extension needs to be installed and version needs to be lower than 2.0.0
        if (version_compare($version, "2.0.0", "<")) {
            $this->assertTrue(\extension_loaded('vips'), 'The needed vips extension was not installed');
            // and check we get a string
            $this->assertIsString(\Jcupitt\Vips\Config::version());

            return;
        }
        // otherwise we may have an unsupported version
        $this->fail('jcupitt/vips version '. $version . ' is not supported');
    }
}
