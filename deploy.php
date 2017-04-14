<?php

require __DIR__.'/vendor/autoload.php';

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();
$dotenv->required([
    'DEPLOY_PEM_FILE',
    'DEPLOY_PATH',
    'DEPLOY_SSH_HOST',
    'DEPLOY_SSH_USER',
]);

require('recipe/common.php');

use function Deployer\{server, task, run, set, get, add, after, runLocally, upload};

$pemFile = getenv('DEPLOY_PEM_FILE');
$deployPath = getenv('DEPLOY_PATH');

/**
 * Environment variables
 */
set('bin/composer', '~/composer.phar');
set('branch', 'master');

/**
 * Global Variables
 */
set('repository', 'git@github.com:jaredh159/dumpy-backups.git');

/**
 * Custom tasks
 */
task('deploy:env', function () use ($deployPath) {
    run("cd {$deployPath}/current && ln -sfn {$deployPath}/.env .env");
})->desc('Symlink .env file into current dir');


/**
 * Server environments
 */
server('production', getenv('DEPLOY_SSH_HOST'))
    ->user(getenv('DEPLOY_SSH_USER'))
    ->pemFile($pemFile)
    ->stage('production')
    ->set('deploy_path', $deployPath);

/**
 * Main deploy task
 */
task('deploy', [
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:symlink',
    'deploy:env',
    'cleanup'
])->desc('Deploy Dumpy Backups');

after('deploy', 'success');
