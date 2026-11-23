# wallabin

wallabin installs and updates [wallabag](https://wallabag.org) from version 2.7.0, without git.

⚠️ Work in progress: this first version is kept as simple as possible.

## Download

```bash
curl -L -o wallabin.phar https://wallabag.org/wallabin
```

A new binary is published with each [wallabin release](https://github.com/wallabag/wallabin/releases).

## Usage

```bash
php wallabin.phar requirements
php wallabin.phar install
php wallabin.phar check --dir=wallabag
sudo -u www-data php wallabin.phar update --dir=wallabag
```

- `requirements` checks that your server can run wallabag: PHP version, PHP extensions (with the driver of your database when wallabag is installed), the `tar` command and a writable directory. `install` and `update` run these checks first and stop if something is missing.
- `install` downloads the latest stable wallabag release, checks its SHA-256 checksum and extracts it in a new `wallabag` directory. Give another directory with `php wallabin.phar install /var/www/wallabag`, and another version with `--release=2.7.1`.
- `check` tells you if a newer version is available, without changing anything. It also tells you if the update fixes security issues, changes the database, or needs a newer PHP version.
- `update` does what `make update` did in wallabag 2.6, in one command: it downloads the new release, replaces the files, runs the database migrations and clears the cache. If something fails, the previous files are restored.

### Run wallabin as the owner of the files

Run `update` as the user who owns the files of wallabag, usually the user of your web server (`www-data`, `nginx`, `apache`…). Otherwise the new files and the cache would belong to another user, and your web server could not write them anymore. wallabin refuses to update when you are not the owner of `var/`.

Also check that `php` on the command line is the same version as the one of your web server: wallabin checks the PHP version it runs with.

### Backups

Before replacing the files, `update` keeps a backup in `var/wallabin/backup-<version>/`: the files of the previous version, and its database when you use SQLite. Only the last backup is kept. If the update fails, the files and the SQLite database are restored.

wallabin can't back up MySQL, MariaDB or PostgreSQL databases. When an update changes the database, `update` asks you to confirm that you backed it up. Use `--yes` to skip this question, for example in a script.

### Test versions

To try an alpha, a beta or a release candidate, install it in a new directory:

```bash
php wallabin.phar install --pre wallabag-test
```

Test versions are for fresh installs only: `check` and `update` only look for stable versions, so that an instance is never broken by a test version. An instance running a test version is updated with the next stable release.

### Kept files

`update` keeps these files: `.env.local`, `.env.prod.local`, `.env.local.php`, `data/`, `var/` and `web/assets/images/`. All other files are replaced by the ones of the new release.

| Option | |
|---|---|
| `--dir` | wallabag directory for `requirements`, `check` and `update`, the current directory by default |
| `--pre` | install the latest test version (`install` only) |
| `--yes` | don't ask to confirm that the database is backed up (`update` only) |
| `--releases-url` | URL of the list of releases, `https://wallabag.org/releases.json` by default |

`check` exits with `0` when wallabag is up to date, `2` when an update is available and `1` on error.

wallabin needs the same PHP version as wallabag 2.7: PHP 8.2 or later (tested with 8.2, 8.3 and 8.4), with the `curl` extension, and the `tar` command.

## How it works

wallabin reads [releases.json](https://wallabag.org/releases.json) on wallabag.org. This file lists the releases from 2.7.0, with their package and its SHA-256 checksum. Your instance sends no information: wallabin downloads the whole file and compares it with the installed version.

wallabag 2.6.x and older can't be updated with wallabin: follow the [upgrade documentation](https://doc.wallabag.org/admin/upgrade/) to move to 2.7.

## Roadmap

- back up MySQL, MariaDB and PostgreSQL databases before an update
- `rollback`: go back to the previous version
- `self-update`: update wallabin itself
- signed packages

See [wallabag/wallabag#9209](https://github.com/wallabag/wallabag/issues/9209) and [wallabag/wallabag#9212](https://github.com/wallabag/wallabag/issues/9212).

## Development

```bash
composer install
vendor/bin/phpunit
```

To build the PHAR, download [Box](https://github.com/box-project/box) first:

```bash
curl -L -o box.phar https://github.com/box-project/box/releases/latest/download/box.phar
composer install --no-dev --optimize-autoloader
php box.phar compile
php build/wallabin.phar --version
composer install
```

## License

wallabin is released under the [MIT license](LICENSE).
