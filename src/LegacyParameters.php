<?php

namespace Wallabag\Wallabin;

use Symfony\Component\Yaml\Yaml;

final class LegacyParameters
{
    private const SIMPLE = [
        'secret' => 'APP_SECRET',
        'locale' => 'DEFAULT_LOCALE',
        'domain_name' => 'WALLABAG_BASE_URL',
        'mailer_dsn' => 'MAILER_DSN',
        'from_email' => 'WALLABAG_FROM_EMAIL',
        'server_name' => 'WALLABAG_SERVER_NAME',
        'twofactor_sender' => 'WALLABAG_TWOFACTOR_SENDER',
        'rabbitmq_prefetch_count' => 'WALLABAG_RABBITMQ_PREFETCH_COUNT',
        'sentry_dsn' => 'SENTRY_DSN',
        'wallabag_user_agent' => 'WALLABAG_USER_AGENT',
        'fos_oauth_server_access_token_lifetime' => 'WALLABAG_OAUTH_ACCESS_TOKEN_LIFETIME',
        'fos_oauth_server_refresh_token_lifetime' => 'WALLABAG_OAUTH_REFRESH_TOKEN_LIFETIME',
        'database_table_prefix' => 'WALLABAG_TABLE_PREFIX',
    ];

    private const BOOLEANS = [
        'fosuser_registration' => 'WALLABAG_REGISTRATION_ENABLED',
        'fosuser_confirmation' => 'WALLABAG_CONFIRMATION_ENABLED',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function read(string $file): array
    {
        $parameters = Yaml::parseFile($file)['parameters'] ?? null;

        if (!\is_array($parameters)) {
            throw new \RuntimeException(sprintf('No parameters found in %s', $file));
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    public static function toEnv(array $parameters): array
    {
        $value = static fn (string $key): string => (string) ($parameters[$key] ?? '');
        $env = [];

        foreach (self::SIMPLE as $key => $name) {
            if (\array_key_exists($key, $parameters)) {
                $env[$name] = $value($key);
            }
        }

        foreach (self::BOOLEANS as $key => $name) {
            if (\array_key_exists($key, $parameters)) {
                $env[$name] = $parameters[$key] ? '1' : '0';
            }
        }

        $env['DATABASE_URL'] = self::databaseUrl($parameters);

        if (\array_key_exists('redis_host', $parameters) || \array_key_exists('redis_path', $parameters)) {
            $env['REDIS_URL'] = self::redisUrl($value);
        }

        if (\array_key_exists('rabbitmq_host', $parameters)) {
            $env['RABBITMQ_URL'] = self::rabbitMqUrl($value);
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    public static function format(array $env): string
    {
        $lines = array_map(
            static fn (string $name, string $value): string => sprintf('%s="%s"', $name, addcslashes($value, '\\"$')),
            array_keys($env),
            $env,
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function databaseUrl(array $parameters): string
    {
        $value = static fn (string $key): string => (string) ($parameters[$key] ?? '');
        $scheme = match ($value('database_driver')) {
            'pdo_mysql' => 'mysql',
            'pdo_pgsql' => 'postgresql',
            'pdo_sqlite' => 'sqlite',
            default => throw new \RuntimeException(sprintf('Unsupported database driver: %s', $value('database_driver'))),
        };

        if ('sqlite' === $scheme) {
            $path = '' !== $value('database_path') ? $value('database_path') : $value('database_name');

            return 'sqlite:///' . $path;
        }

        $url = $scheme . '://' . self::credentials($value('database_user'), $value('database_password')) . $value('database_host');

        if ('' !== $value('database_port')) {
            $url .= ':' . $value('database_port');
        }

        $url .= '/' . $value('database_name');
        $query = array_filter(['unix_socket' => $value('database_socket'), 'charset' => $value('database_charset')], static fn (string $item): bool => '' !== $item);

        return [] === $query ? $url : $url . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param callable(string): string $value
     */
    private static function redisUrl(callable $value): string
    {
        $password = '' !== $value('redis_password') ? '?password=' . rawurlencode($value('redis_password')) : '';

        if ('unix' === $value('redis_scheme')) {
            return 'unix://' . $value('redis_path') . $password;
        }

        $url = ('' !== $value('redis_scheme') ? $value('redis_scheme') : 'redis') . '://' . $value('redis_host');

        if ('' !== $value('redis_port')) {
            $url .= ':' . $value('redis_port');
        }

        if ('' !== $value('redis_path')) {
            $url .= '/' . ltrim($value('redis_path'), '/');
        }

        return $url . $password;
    }

    /**
     * @param callable(string): string $value
     */
    private static function rabbitMqUrl(callable $value): string
    {
        $url = 'amqp://' . self::credentials($value('rabbitmq_user'), $value('rabbitmq_password')) . $value('rabbitmq_host');

        return '' !== $value('rabbitmq_port') ? $url . ':' . $value('rabbitmq_port') : $url;
    }

    private static function credentials(string $user, string $password): string
    {
        if ('' === $user && '' === $password) {
            return '';
        }

        return rawurlencode($user) . ('' !== $password ? ':' . rawurlencode($password) : '') . '@';
    }
}
