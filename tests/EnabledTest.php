<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EnabledTest extends TestCase
{

    public function testIsVipsEnabled() {
        $this->assertTrue(\Imagine\Vips\Imagine::hasVipsInstalled());
    }
}