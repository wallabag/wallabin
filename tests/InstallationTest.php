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
        $this->write('wallabag/.env.prod.local', 'mine');
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

        $this->write('legacy/app/config/wallabag.yml', "wallabag_core:\n    version: 2.6.18\n");
        $this->assertSame('2.6.18', (new Installation($this->root . '/legacy'))->version());
    }

    public function testReplaceKeepsTheUserFiles(): void
    {
        $installation = new Installation($this->root . '/wallabag');
        $installation->replaceWith($this->root . '/new', $this->root . '/old');

        $this->assertSame('2.7.1', $installation->version());
        $this->assertFileExists($this->root . '/wallabag/src/Kernel.php');
        $this->assertFileDoesNotExist($this->root . '/wallabag/src/Obsolete.php');

        foreach (['.env.local', 'data/db/wallabag.sqlite', '.env.prod.local', 'web/assets/images/picture.jpg'] as $file) {
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

        foreach (['.env.local', 'data/db/wallabag.sqlite', '.env.prod.local', 'web/assets/images/picture.jpg'] as $file) {
            $this->assertStringEqualsFile($this->root . '/wallabag/' . $file, 'mine', $file);
        }
    }

    public function testBackupAndMigrationsSinceIt(): void
    {
        $installation = new Installation($this->root . '/wallabag');
        $this->assertNull($installation->backup());

        $this->write('wallabag/var/wallabin/backup-2.7.0/migrations/Version1.php', '');
        $this->write('wallabag/migrations/Version1.php', '');
        $this->assertSame($this->root . '/wallabag/var/wallabin/backup-2.7.0', $installation->backup());
        $this->assertSame([], $installation->migrationsSince($installation->backup()));

        $this->write('wallabag/migrations/Version2.php', '');
        $this->assertSame(['Version2.php'], $installation->migrationsSince($installation->backup()));
    }

    public function testSqlitePath(): void
    {
        $this->assertSame('/srv/wallabag/data/db/wallabag.sqlite', Installation::sqlitePath('sqlite:///%kernel.project_dir%/data/db/wallabag.sqlite', '/srv/wallabag'));
        $this->assertSame('/var/lib/wallabag.sqlite', Installation::sqlitePath('sqlite:////var/lib/wallabag.sqlite?charset=utf8', '/srv/wallabag'));
        $this->assertNull(Installation::sqlitePath('mysql://wallabag:secret@127.0.0.1:3306/wallabag', '/srv/wallabag'));
        $this->assertNull(Installation::sqlitePath('', '/srv/wallabag'));
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
