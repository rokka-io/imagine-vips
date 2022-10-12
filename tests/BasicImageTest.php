<?php declare(strict_types=1);

use Imagine\Image\Box;
use PHPUnit\Framework\TestCase;

final class BasicImageTest extends TestCase
{

    public function testIsVipsEnabled() {
        $imagine = new \Imagine\Vips\Imagine();
        $image = $imagine->create(new Box(10,10));
        $this->assertInstanceOf(\Imagine\Vips\Image::class, $image);
        $this->assertEquals($image->getSize()->getWidth(), 10);
    }

    public function testResizeImage() {
        $imagine = new \Imagine\Vips\Imagine();
        $image = $imagine->create(new Box(200,100));
        $image->resize(new Box(50,50));
        $this->assertEquals($image->getSize()->getWidth(), 50);
        $this->assertEquals($image->getSize()->getHeight(), 50);
    }

    public function testSaveImage() {
        $imagine = new \Imagine\Vips\Imagine();
        $image = $imagine->create(new Box(200,100));
        $image->save("foo.jpg");
        $this->assertFileExists("foo.jpg");
        $loaded = $imagine->open("foo.jpg");
        $this->assertEquals($loaded->getSize()->getWidth(), 200);
        $this->assertEquals($loaded->getSize()->getHeight(), 100);
        unlink("foo.jpg");
    }
}