<?php

namespace Wallabag\Wallabin\Tests;

use PHPUnit\Framework\TestCase;
use Wallabag\Wallabin\Filesystem;
use Wallabag\Wallabin\Installation;

final class InstallationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wallabin-test-' . bin2hex(random_bytes(4));
        $this->write('wallabag/app/config/wallabag.yml', "parameters:\n    wallabag.version: 2.7.0\n");
        $this->write('wallabag/app/config/parameters.yml', 'mine');
        $this->write('wallabag/src/Obsolete.php', 'old');
        $this->write('wallabag/.env.local', 'mine');
        $this->write('wallabag/data/db/wallabag.sqlite', 'mine');
        $this->write('wallabag/web/assets/images/picture.jpg', 'mine');
        $this->write('new/app/config/wallabag.yml', "parameters:\n    wallabag.version: 2.7.1\n");
        $this->write('new/src/Kernel.php', 'new');
        $this->write('new/data/db/wallabag.sqlite', 'new');
        $this->write('new/web/assets/images/.gitkeep', '');
        mkdir($this->root . '/old');
    }

    protected function tearDown(): void
    {
        Filesystem::remove($this->root);
    }

    public function testVersion(): void
    {
        $this->assertSame('2.7.0', (new Installation($this->root . '/wallabag'))->version());
        $this->assertNull((new Installation($this->root))->version());
    }

    public function testReplaceKeepsTheUserFiles(): void
    {
        $installation = new Installation($this->root . '/wallabag');
        $installation->replaceWith($this->root . '/new', $this->root . '/old');

        $this->assertSame('2.7.1', $installation->version());
        $this->assertFileExists($this->root . '/wallabag/src/Kernel.php');
        $this->assertFileDoesNotExist($this->root . '/wallabag/src/Obsolete.php');

        foreach (['.env.local', 'data/db/wallabag.sqlite', 'app/config/parameters.yml', 'web/assets/images/picture.jpg'] as $file) {
            $this->assertStringEqualsFile($this->root . '/wallabag/' . $file, 'mine', $file);
        }
    }

    public function testRestore(): void
    {
        $installation = new Installation($this->root . '/wallabag');
        $installation->replaceWith($this->root . '/new', $this->root . '/old');
        $installation->restore($this->root . '/old');

        $this->assertSame('2.7.0', $installation->version());
        $this->assertFileExists($this->root . '/wallabag/src/Obsolete.php');
        $this->assertFileDoesNotExist($this->root . '/wallabag/src/Kernel.php');

        foreach (['.env.local', 'data/db/wallabag.sqlite', 'app/config/parameters.yml', 'web/assets/images/picture.jpg'] as $file) {
            $this->assertStringEqualsFile($this->root . '/wallabag/' . $file, 'mine', $file);
        }
    }

    private function write(string $path, string $content): void
    {
        $file = $this->root . '/' . $path;

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }

        file_put_contents($file, $content);
    }
}
