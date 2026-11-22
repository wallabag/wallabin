<?php

namespace Wallabag\Wallabin\Tests;

use PHPUnit\Framework\TestCase;
use Wallabag\Wallabin\Releases;

final class ReleasesTest extends TestCase
{
    private Releases $releases;

    protected function setUp(): void
    {
        $this->releases = new Releases([
            ['version' => '2.7.0', 'prerelease' => false, 'php_min' => '8.2'],
            ['version' => '2.7.1', 'prerelease' => false, 'php_min' => '8.2'],
            ['version' => '2.8.0-beta.1', 'prerelease' => true, 'php_min' => '8.4'],
            ['version' => '2.8.0', 'prerelease' => false, 'php_min' => '8.4'],
        ]);
    }

    public function testNewerStableReleasesHighestFirst(): void
    {
        $this->assertSame(['2.8.0', '2.7.1'], array_column($this->releases->newerThan('2.7.0', false, '8.4.0'), 'version'));
    }

    public function testPrereleasesOnlyWhenAsked(): void
    {
        $this->assertSame(['2.8.0', '2.8.0-beta.1', '2.7.1'], array_column($this->releases->newerThan('2.7.0', true, '8.4.0'), 'version'));
    }

    public function testReleasesNeedingANewerPhpAreLeftOut(): void
    {
        $this->assertSame(['2.7.1'], array_column($this->releases->newerThan('2.7.0', false, '8.2.0'), 'version'));
        $this->assertSame(['2.8.0'], array_column($this->releases->needingNewerPhp('2.7.0', false, '8.2.0'), 'version'));
    }

    public function testUpToDate(): void
    {
        $this->assertSame([], $this->releases->newerThan('2.8.0', false, '8.4.0'));
    }
}
