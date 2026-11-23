# wallabin

wallabin installs and updates [wallabag](https://wallabag.org) from version 2.7.0, without git.

⚠️ Work in progress: this first version is kept as simple as possible.

## Download

```bash
curl -L -o wallabin.phar https://wallabag.org/wallabin
```

A new binary is published with each [wallabin release](https://github.com/wallabag/wallabin/releases), with its minisign signature. Update it with:

```bash
php wallabin.phar self-update
```

`self-update` downloads the latest wallabin release, checks its signature and replaces `wallabin.phar`.

## Usage

```bash
php wallabin.phar requirements
php wallabin.phar install
php wallabin.phar check --dir=wallabag
sudo -u www-data php wallabin.phar update --dir=wallabag
sudo -u www-data php wallabin.phar rollback --dir=wallabag
```

- `requirements` checks that your server can run wallabag: PHP version, PHP extensions (with the driver of your database when wallabag is installed), the `tar` command and a writable directory. `install` and `update` run these checks first and stop if something is missing.
- `install` downloads the latest stable wallabag release, checks its SHA-256 checksum and extracts it in a new `wallabag` directory. Then it asks for the URL of your instance, your database and the password of the `wallabag` admin user, creates the database and the admin user. Give another directory with `php wallabin.phar install /var/www/wallabag`, and another version with `--release=2.7.1`.
- `check` tells you if a newer version is available, without changing anything. It also tells you if the update fixes security issues, changes the database, or needs a newer PHP version.
- `update` does what `make update` did in wallabag 2.6, in one command: it downloads the new release, replaces the files, runs the database migrations and clears the cache. If something fails, the previous files are restored.

### Run wallabin as the owner of the files

Run `update` as the user who owns the files of wallabag, usually the user of your web server (`www-data`, `nginx`, `apache`…). Otherwise the new files and the cache would belong to another user, and your web server could not write them anymore. wallabin refuses to update when you are not the owner of `var/`.

Also check that `php` on the command line is the same version as the one of your web server: wallabin checks the PHP version it runs with.

### Backups

Before replacing the files, `update` keeps a backup in `var/wallabin/backup-<version>/`: the files of the previous version, and its database when you use SQLite. Only the last backup is kept. If the update fails, the files and the SQLite database are restored.

`rollback` goes back to the version kept in this backup, and removes the backup. When the last update changed the database, a SQLite database goes back to its state before the update: everything saved since then is lost. MySQL, MariaDB and PostgreSQL databases are not restored: restore your own backup. `rollback` asks for a confirmation first, use `--yes` to skip it.

wallabin can't back up MySQL, MariaDB or PostgreSQL databases. When an update changes the database, `update` asks you to confirm that you backed it up. Use `--yes` to skip this question, for example in a script.

### Install without questions

For a script, give the configuration as options:

```bash
php wallabin.phar install /var/www/wallabag --no-interaction \
    --url=https://wallabag.example.org \
    --database-url='mysql://wallabag:password@127.0.0.1:3306/wallabag?charset=utf8mb4' \
    --admin-password='a long password'
```

Without `--database-url`, wallabag uses a SQLite database in `data/db/`. With MySQL, MariaDB or PostgreSQL, wallabin creates the database if it doesn't exist yet.

### Test versions

To try an alpha, a beta or a release candidate, install it in a new directory:

```bash
php wallabin.phar install --pre wallabag-test
```

Test versions are for fresh installs only: `check` and `update` only look for stable versions, so that an instance is never broken by a test version. An instance running a test version is updated with the next stable release.

### Migrate from wallabag 2.6

```bash
sudo -u www-data php wallabin.phar migrate --dir=/var/www/wallabag
```

`migrate` moves a wallabag 2.6 installation to the latest stable 2.7 release, in place, whether it was installed with git or with a package:

1. it converts your `app/config/parameters.yml` file into a `.env.local` file, as wallabag 2.7 is configured with environment variables,
2. it replaces the files of wallabag 2.6 by the ones of 2.7, and keeps your `data/` folder, your downloaded images and your `var/` folder,
3. it runs the database migrations and clears the cache.

The files of wallabag 2.6, with your `parameters.yml` file and its `.git` folder if any, are kept in `var/wallabin/backup-2.6.x/`, so that `rollback` can go back to 2.6. Like `update`, it backs up a SQLite database, and asks you to confirm that you backed up a MySQL, MariaDB or PostgreSQL database.

To try a test version of 2.7 (alpha, beta or release candidate), migrate **a copy** of your instance, with a copy of its database, never the instance you use every day:

```bash
sudo -u www-data php wallabin.phar migrate --pre --dir=/var/www/wallabag-copy
```

wallabin asks you to confirm that you migrate a copy. `rollback` goes back to 2.6, but only a SQLite database is restored as it was before the migration. The migrated instance stays on this test version: `update` only installs stable versions, so it moves to the stable 2.7.0 release when it's out.

Before the migration, update wallabag to the latest 2.6 patch version (2.6.18 or later) with `make update`. After the migration, check your web server configuration: the built assets moved from `web/wallassets/` to `web/build/`, and `web/index.php` is the new front controller (`web/app.php` still works). See the [upgrade documentation](https://doc.wallabag.org/admin/upgrade/).

### Kept files

`update` keeps these files: `.env.local`, `.env.prod.local`, `.env.local.php`, `data/`, `var/`, `app/config/parameters.yml` and `web/assets/images/`. All other files are replaced by the ones of the new release.

| Option | |
|---|---|
| `--dir` | wallabag directory for `requirements`, `check`, `update`, `rollback` and `migrate`, the current directory by default |
| `--pre` | install or migrate to the latest test version (`install` and `migrate` only) |
| `--url`, `--database-url`, `--admin-password` | configuration of a new instance (`install` only) |
| `--yes` | don't ask for a confirmation (`update`, `rollback` and `migrate`) |
| `--releases-url` | URL of the list of releases, `https://wallabag.org/releases.json` by default |

`check` exits with `0` when wallabag is up to date, `2` when an update is available and `1` on error.

wallabin needs the same PHP version as wallabag 2.7: PHP 8.2 or later (tested with 8.2, 8.3 and 8.4), with the `curl` extension, and the `tar` command.

## How it works

wallabin reads [releases.json](https://wallabag.org/releases.json) on wallabag.org. This file lists the releases from 2.7.0, with their package and its SHA-256 checksum. Your instance sends no information: wallabin downloads the whole file and compares it with the installed version.

The wallabag packages and the wallabin binaries are signed with [minisign](https://jedisct1.github.io/minisign/). wallabin checks the signature of each package with the wallabag public key it contains, and refuses a package whose signature doesn't match. Packages released before the signatures are only checked with their SHA-256 checksum, with a warning. You can check a package yourself:

```bash
minisign -V -P RWQT4s5qtqdNSaZOi9peL3i+pP/KMMzpk/c9XJd84ZIpxFWaGqYOlwy+ -m wallabag-2.7.0.tar.gz
```

wallabag 2.6 is migrated to 2.7 with `migrate`. Update it to the latest 2.6 patch version first, and older versions to 2.6 before that.

## Roadmap

- back up MySQL, MariaDB and PostgreSQL databases before an update

See [wallabag/wallabag#9209](https://github.com/wallabag/wallabag/issues/9209) and [wallabag/wallabag#9212](https://github.com/wallabag/wallabag/issues/9212).

## Development

```bash
composer install
vendor/bin/phpunit
```

The end-to-end test installs wallabag 2.7 and logs in, installs wallabag 2.6, migrates it to 2.7, updates it and rolls it back, with SQLite, MySQL or PostgreSQL. It needs a wallabag 2.6 package and a 2.7 package:

```bash
DATABASE=mysql DB_PASSWORD=root tests/e2e/run.sh wallabag-2.6.18.tar.gz wallabag-2.7.0.tar.gz
```

The CI runs it on the three databases, with a 2.7 package built from wallabag.

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
