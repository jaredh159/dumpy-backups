#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use Dotenv\Dotenv;
use Aws\S3\S3Client;
use DumpyBackups\Command\Backup;
use Symfony\Component\Console\Application;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;

$dotenv = new Dotenv(__DIR__);
$dotenv->load();
$dotenv->required([
    'S3_BUCKET',
    'S3_REGION',
    'S3_KEY',
    'S3_SECRET',
    // 'SLACK_API_TOKEN',
    // 'SLACK_NOTIFY_CHANNEL',
    // 'SLACK_NOTIFY_EMOJI',
]);

$client = new S3Client([
    'version' => 'latest',
    'region' => getenv('S3_REGION'),
    'credentials' => [
        'key'    => getenv('S3_KEY'),
        'secret' => getenv('S3_SECRET'),
    ]
]);

$adapter = new Local('/');
$filesystem = new Filesystem($adapter);

$app = new Application();
$app->add(new Backup($client, $filesystem));
$app->run();
