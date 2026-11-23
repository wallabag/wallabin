<?php

namespace Wallabag\Wallabin\Tests;

use PHPUnit\Framework\TestCase;
use Wallabag\Wallabin\LegacyParameters;

final class LegacyParametersTest extends TestCase
{
    private const PARAMETERS = [
        'database_driver' => 'pdo_mysql',
        'database_host' => '127.0.0.1',
        'database_port' => 3306,
        'database_name' => 'wallabag',
        'database_user' => 'wallabag',
        'database_password' => 'p@ss:word',
        'database_path' => null,
        'database_table_prefix' => 'wallabag_',
        'database_socket' => null,
        'database_charset' => 'utf8mb4',
        'domain_name' => 'https://wallabag.example.org',
        'secret' => 'secret',
        'fosuser_registration' => false,
        'fosuser_confirmation' => true,
        'redis_scheme' => 'redis',
        'redis_host' => 'localhost',
        'redis_port' => 6379,
        'redis_path' => null,
        'redis_password' => 'redis pass',
        'rabbitmq_host' => 'localhost',
        'rabbitmq_port' => 5672,
        'rabbitmq_user' => 'guest',
        'rabbitmq_password' => 'guest',
        'rss_limit' => 50,
    ];

    public function testMysql(): void
    {
        $env = LegacyParameters::toEnv(self::PARAMETERS);

        $this->assertSame('mysql://wallabag:p%40ss%3Aword@127.0.0.1:3306/wallabag?charset=utf8mb4', $env['DATABASE_URL']);
        $this->assertSame('secret', $env['APP_SECRET']);
        $this->assertSame('https://wallabag.example.org', $env['WALLABAG_BASE_URL']);
        $this->assertSame('wallabag_', $env['WALLABAG_TABLE_PREFIX']);
        $this->assertSame('0', $env['WALLABAG_REGISTRATION_ENABLED']);
        $this->assertSame('1', $env['WALLABAG_CONFIRMATION_ENABLED']);
        $this->assertSame('redis://localhost:6379?password=redis%20pass', $env['REDIS_URL']);
        $this->assertSame('amqp://guest:guest@localhost:5672', $env['RABBITMQ_URL']);
        $this->assertArrayNotHasKey('MAILER_DSN', $env);
    }

    public function testPostgresqlWithASocket(): void
    {
        $env = LegacyParameters::toEnv(['database_driver' => 'pdo_pgsql', 'database_host' => 'localhost', 'database_port' => null, 'database_name' => 'wallabag', 'database_user' => 'wallabag', 'database_password' => null, 'database_socket' => '/run/postgresql', 'database_charset' => 'utf8']);

        $this->assertSame('postgresql://wallabag@localhost/wallabag?unix_socket=%2Frun%2Fpostgresql&charset=utf8', $env['DATABASE_URL']);
        $this->assertArrayNotHasKey('REDIS_URL', $env);
    }

    public function testSqlite(): void
    {
        $this->assertSame(
            'sqlite:///%kernel.project_dir%/data/db/wallabag.sqlite',
            LegacyParameters::toEnv(['database_driver' => 'pdo_sqlite', 'database_path' => '%kernel.project_dir%/data/db/wallabag.sqlite'])['DATABASE_URL'],
        );
        $this->assertSame(
            'sqlite:////var/lib/wallabag.sqlite',
            LegacyParameters::toEnv(['database_driver' => 'pdo_sqlite', 'database_path' => '/var/lib/wallabag.sqlite'])['DATABASE_URL'],
        );
    }

    public function testUnknownDriver(): void
    {
        $this->expectException(\RuntimeException::class);

        LegacyParameters::toEnv(['database_driver' => 'pdo_oci']);
    }

    public function testFormatEscapesTheValues(): void
    {
        $this->assertSame("APP_SECRET=\"a\\\$b\\\"c\\\\d\"\nSENTRY_DSN=\"\"\n", LegacyParameters::format(['APP_SECRET' => 'a$b"c\\d', 'SENTRY_DSN' => '']));
    }

    public function testRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wallabin');
        file_put_contents($file, "parameters:\n    database_driver: pdo_sqlite\n    secret: ~\n");

        try {
            $this->assertSame(['database_driver' => 'pdo_sqlite', 'secret' => null], LegacyParameters::read($file));
        } finally {
            unlink($file);
        }
    }
}
