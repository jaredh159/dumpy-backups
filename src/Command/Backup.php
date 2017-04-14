<?php

namespace DumpyBackups\Command;

use GuzzleHttp\Promise;
use Aws\S3\S3ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use League\Flysystem\FilesystemInterface;

class Backup extends Command
{
    /**
     * @var S3ClientInterface
     */
    protected $client;

    /**
     * @var FilesystemInterface
     */
    protected $filesystem;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @param S3ClientInterface $client
     * @param FilesystemInterface $filesystem
     */
    public function __construct(
        S3ClientInterface $client,
        FilesystemInterface $filesystem
    ) {
        parent::__construct();
        $this->client = $client;
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $files = $this->getFiles();
        $prefix = $this->getPrefix();
        $date = date(DATE_ATOM);

        foreach ($files as $file) {
            $this->output->writeLn("<comment>PUT: {$file['relpath']}</comment>");
            $promises[] = $this->client->putObject([
                'Bucket' => getenv('S3_BUCKET'),
                'Key' => "{$prefix}{$date}/{$file['relpath']}",
                'Body' => fopen($file['fullpath'], 'r'),
            ]);
        }

        $this->addToManifest($date);
        $this->cleanup();
    }

    /**
     * Get files for mirroring
     *
     * @return array
     */
    protected function getFiles(): array
    {
        $path = $this->input->getOption('path');
        if (! $path || ! is_dir($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("The path $path must be a readible dir");
        }

        $this->filesystem->getAdapter()->setPathPrefix($path);
        $contents = $this->filesystem->listContents('', true);

        $exclude = [];
        if (getenv('EXCLUDE_FILE_EXTENSIONS')) {
            $exclude = explode(',', getenv('EXCLUDE_FILE_EXTENSIONS'));
        }

        $files = [];
        foreach ($contents as $item) {
            if ($item['type'] !== 'file' || $item['size'] === 0) {
                continue;
            }

            if (in_array($item['extension'] ?? null, $exclude, true)) {
                continue;
            }

            $files[] = [
                'fullpath' => "{$path}/{$item['path']}",
                'relpath'=> $item['path'],
            ];
        }

        return $files;
    }

    /**
     * Get a backup manifest
     *
     * A dumpy manifest is a simple file with json-formatted
     * data listing the backup dates that exist. This is to avoid
     * doing weird things to determine which backups exist and
     * should be kept/deleted due to the fact that S3 doesn't actually
     * have a concept of directories.
     *
     * @return array
     */
    protected function getManifest(): array
    {
        $prefix = $this->getPrefix();
        try {
            $result = $this->client->getObject([
                'Bucket' => getenv('S3_BUCKET'),
                'Key' => $prefix . '__dumpy-backup-manifest.json',
            ]);
        } catch (\Exception $e) {
            return [];
        }

        $times = json_decode($result->get('Body'));
        sort($times);
        return $times;
    }

    /**
     * Add a date to the manifest
     *
     * @param string $date
     * @param void
     */
    protected function addToManifest(string $date): void
    {
        $manifest = $this->getManifest();
        $manifest[] = $date;
        $this->saveManifest($manifest);
    }

    /**
     * Persist the manifest
     *
     * @param array $manifest
     * @return void
     */
    protected function saveManifest(array $manifest): void
    {
        $prefix = $this->getPrefix();
        $this->client->putObject([
            'Bucket' => getenv('S3_BUCKET'),
            'Key' => $prefix . '__dumpy-backup-manifest.json',
            'Body' => json_encode($manifest, JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Clean up backups by removing any older than max kept
     *
     * @return void
     */
    protected function cleanup(): void
    {
        $keep = (int) $this->input->getOption('keep');
        if ($keep < 1) {
            return;
        }

        $manifest = $this->getManifest();
        if (empty($manifest) || count($manifest) <= $keep) {
            return;
        }

        $remove = array_slice($manifest, 0, count($manifest) - $keep);
        $keep = array_slice($manifest, count($remove));
        foreach ($remove as $date) {
            $this->delete($date);
        }

        $this->saveManifest($keep);
    }

    /**
     * Delete an old backup by date
     *
     * @param string $date
     * @return void
     */
    protected function delete(string $date): void
    {
        $continue = $this->deletePage($date);
        while ($continue) {
            $continue = $this->deletePage($date, $continue);
        }
    }

    /**
     * Delete a "page" of objects
     *
     * S3 only allows listing of 1000 S3 bucket objects at a time, so
     * this method deletes <= 1000 and returns a `ContinuationToken`
     * if there are still more objects to delete.
     *
     * @param string $date
     * @param string|null $continuationToken
     * @return ?string
     */
    protected function deletePage(
        string $date,
        ?string $continuationToken = null
    ): ?string {
        $prefix = $this->getPrefix();
        $list = $this->client->listObjectsV2([
            'Bucket' => getenv('S3_BUCKET'),
            'Prefix' => "{$prefix}{$date}/",
            'ContinuationToken' => $continuationToken,
        ]);

        $contents = $list->get('Contents');
        if (! $contents) {
            return null;
        }

        $this->client->deleteObjects([
            'Bucket' => getenv('S3_BUCKET'),
            'Delete' => [
                'Objects' => $list->get('Contents'),
            ],
        ]);

        if ($list->get('IsTruncated')) {
            return $list->get('NextContinuationToken');
        }

        return null;
    }

    /**
     * Get the S3 mirror "prefix" (virtual directory)
     *
     * @return string
     */
    protected function getPrefix(): string
    {
        $prefix = trim($this->input->getOption('prefix'));
        if (! $prefix) {
            return '';
        }

        return rtrim($prefix, '/') . '/';
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('backup:create')
            ->setDescription('Creates a new backup.')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Absolute path to directory you want to mirror to S3'
            )
            ->addOption(
                'keep',
                null,
                InputOption::VALUE_REQUIRED,
                'How many versions of the backup to keep',
                0 // 0 means keep all
            )
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_OPTIONAL,
                'S3 object prefix (prepended to relative file path in bucket)',
                ''
            )
        ;
    }
}
