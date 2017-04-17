# Dumpy Backups

> There's Dumpy the frog.

A simple utility written in PHP that will mirror a directory to an S3 bucket.

Supports cleaning up old backups by means of a `keep` parameter, which you can use to specify how many copies of the directory backup you want to keep.  Older copies will be deleted.

Configuration details are stored in a [.env file](https://github.com/vlucas/phpdotenv).

### Installation

```
$ git clone git@github.com:jaredh159/dumpy-backups.git
$ cd dumpy-backups
$ composer install
$ cp .env.example .env
$ vim .env
```

### Usage

Invoke via SSH or (more likely) cron, like so:

```
$ php path/to/dumpy backup:create --path=/dir/to/backup --prefix=files/weekly --keep=3
```
