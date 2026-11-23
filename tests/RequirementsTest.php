<?php

namespace Wallabag\Wallabin\Tests;

use PHPUnit\Framework\TestCase;
use Wallabag\Wallabin\Requirements;

final class RequirementsTest extends TestCase
{
    public function testEverythingIsThere(): void
    {
        $requirements = new Requirements('8.2.0', [...Requirements::EXTENSIONS, 'pdo_sqlite'], true);

        $this->assertSame([], $requirements->failures(sys_get_temp_dir()));
        $this->assertSame([], $requirements->failures(sys_get_temp_dir() . '/wallabin-missing/wallabag'));
    }

    public function testMissingRequirements(): void
    {
        $extensions = array_diff(Requirements::EXTENSIONS, ['intl', 'tidy']);
        $requirements = new Requirements('8.1.30', [...$extensions, 'pdo_sqlite'], false);

        $this->assertSame([
            'PHP 8.2 or later (you run PHP 8.1.30)',
            'PHP extension intl',
            'PHP extension tidy',
            'tar command',
            '/nonexistent-wallabin is writable',
        ], $requirements->failures('/nonexistent-wallabin'));
    }

    public function testTheDriverOfTheDatabaseIsNeeded(): void
    {
        $requirements = new Requirements('8.4.0', [...Requirements::EXTENSIONS, 'PDO_SQLite'], true);

        $this->assertSame([], $requirements->failures(sys_get_temp_dir(), 'sqlite:///%kernel.project_dir%/data/db/wallabag.sqlite'));
        $this->assertSame(['PHP extension pdo_mysql for your database'], $requirements->failures(sys_get_temp_dir(), 'mysql://wallabag:secret@127.0.0.1:3306/wallabag'));
        $this->assertSame(['PHP extension pdo_pgsql for your database'], $requirements->failures(sys_get_temp_dir(), 'postgresql://wallabag:secret@127.0.0.1:5432/wallabag'));
    }

    public function testAnyDriverWhenTheDatabaseIsNotKnownYet(): void
    {
        $this->assertSame(
            ['PHP extension pdo_sqlite, pdo_mysql or pdo_pgsql for your database'],
            (new Requirements('8.2.0', Requirements::EXTENSIONS, true))->failures(sys_get_temp_dir()),
        );
        $this->assertSame([], (new Requirements('8.2.0', [...Requirements::EXTENSIONS, 'pdo_pgsql'], true))->failures(sys_get_temp_dir()));
    }
}
