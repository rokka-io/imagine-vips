<?php

use Imagine\Driver\Info;
use Imagine\Driver\InfoProvider;
use Imagine\Exception\NotSupportedException;
use Imagine\Image\FormatList;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\Grayscale;
use Imagine\Image\Palette\RGB;
use Imagine\Vips\DriverInfo;
use Imagine\Vips\Imagine;
use Jcupitt\Vips\Config;
use PHPUnit\Framework\TestCase;

final class DriverInfoTest extends TestCase
{
    public function testImagineProvidesDriverInfo(): void
    {
        $this->assertTrue(is_subclass_of(Imagine::class, InfoProvider::class));
        $info = Imagine::getDriverInfo(false);

        if (null === $info) {
            $this->expectException(NotSupportedException::class);
            Imagine::getDriverInfo();

            return;
        }

        $this->assertInstanceOf(Info::class, $info);
        $this->assertSame($info, Imagine::getDriverInfo());
        $this->assertSame(Config::version(), $info->getEngineVersion());
        $this->assertSame(Config::version(), $info->getDriverVersion(true));
        $this->assertSame($info->getEngineVersion(), $info->getDriverVersion());
        $this->assertSame($info->getEngineVersion(true), $info->getDriverVersion(true));
        $this->assertSame(1, preg_match('/^\d+\.\d+\.\d+$/', $info->getDriverVersion()));
        $info->checkVersionIsSupported();
    }

    public function testAdapterCapabilities(): void
    {
        $info = $this->createDriverInfo('8.12.0');

        $this->assertTrue($info->hasFeature([
            Info::FEATURE_GRAYSCALEEFFECT,
            Info::FEATURE_NEGATEIMAGE,
            Info::FEATURE_SHARPENIMAGE,
            Info::FEATURE_MULTIPLELAYERS,
            Info::FEATURE_ADDLAYERSTOEMPTYIMAGE,
            Info::FEATURE_TRANSPARENCY,
            Info::FEATURE_DETECTGRAYCOLORSPACE,
            Info::FEATURE_ROTATEIMAGEWITHCORRECTSIZE,
        ]));
        foreach ([
            Info::FEATURE_COLORIZEIMAGE,
            Info::FEATURE_CONVOLVEIMAGE,
            Info::FEATURE_DRAWFILLEDCHORDSCORRECTLY,
            Info::FEATURE_DRAWUNFILLEDCIRCLESWITHTICHKESSCORRECTLY,
            Info::FEATURE_DRAWUNFILLEDELLIPSESWITHTICHKESSCORRECTLY,
            Info::FEATURE_GETCMYKCOLORSCORRECTLY,
            Info::FEATURE_CUSTOMRESOLUTION,
            Info::FEATURE_EXPORTWITHCUSTOMRESOLUTION,
            Info::FEATURE_EXPORTWITHCUSTOMJPEGSAMPLINGFACTORS,
        ] as $feature) {
            $this->assertFalse($info->hasFeature($feature));
        }
        $this->assertFalse($info->hasFeature([Info::FEATURE_TRANSPARENCY, Info::FEATURE_COLORIZEIMAGE]));
        $this->expectException(NotSupportedException::class);
        $info->requireFeature(Info::FEATURE_COLORIZEIMAGE);
    }

    public function testRotationRequiresVips86(): void
    {
        $info = $this->createDriverInfo('8.5.0');

        $this->assertFalse($info->hasFeature(Info::FEATURE_ROTATEIMAGEWITHCORRECTSIZE));
    }

    public function testEveryImagineFeatureIsRecognized(): void
    {
        $info = $this->createDriverInfo('8.12.0');

        foreach ((new ReflectionClass(Info::class))->getConstants() as $name => $feature) {
            if (0 !== strpos($name, 'FEATURE_')) {
                continue;
            }
            try {
                $info->requireFeature($feature);
                $this->addToAssertionCount(1);
            } catch (NotSupportedException $exception) {
                $this->assertFalse(strpos($exception->getMessage(), 'Unknown Vips feature'), $name);
            }
        }
    }

    public function testUnknownFeaturesAreRejected(): void
    {
        $info = $this->createDriverInfo();

        $this->assertFalse($info->hasFeature(-1));
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('Unknown Vips feature -1');
        $info->requireFeature(-1);
    }

    public function testSupportedPalettes(): void
    {
        $info = $this->createDriverInfo();

        foreach ([new RGB(), new CMYK(), new Grayscale()] as $palette) {
            $this->assertTrue($info->isPaletteSupported($palette));
        }
    }

    public function testSupportedFormats(): void
    {
        $info = Imagine::getDriverInfo(false);
        if (null === $info) {
            $this->markTestSkipped('Vips is not installed');
        }

        $formats = $info->getSupportedFormats();
        $this->assertInstanceOf(FormatList::class, $formats);
        $this->assertSame($formats, $info->getSupportedFormats());
        $this->assertTrue($info->isFormatSupported('png'));
        $this->assertSame($info->isFormatSupported('jpeg'), $info->isFormatSupported('jpg'));
        $this->assertFalse($info->isFormatSupported('not-a-format'));
    }

    private function createDriverInfo(string $version = '8.12.0'): DriverInfo
    {
        $info = $this->getMockBuilder(DriverInfo::class)->disableOriginalConstructor()
            ->setMethods(['getEngineVersion'])->getMock();
        $info->method('getEngineVersion')->willReturn($version);

        return $info;
    }
}
