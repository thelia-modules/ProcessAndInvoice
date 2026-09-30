<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProcessAndInvoice\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * The files of a batch live under var/process-and-invoice/<batch id>/ of the project, out of
 * the public directory: one PDF per processed order until the batch is complete, then the
 * single document the administrator downloads.
 */
final readonly class BatchStorage
{
    private const DOCUMENT_FILE_NAME = 'invoices.pdf';
    private const REPORT_FILE_NAME = 'report.pdf';

    private string $rootDirectory;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDirectory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->rootDirectory = rtrim($projectDirectory, '/').'/var/process-and-invoice';
    }

    public function invoicePath(int $batchId, int $orderId): string
    {
        return $this->directory($batchId).'/'.$orderId.'.pdf';
    }

    public function reportPath(int $batchId): string
    {
        return $this->directory($batchId).'/'.self::REPORT_FILE_NAME;
    }

    public function documentPath(int $batchId): string
    {
        return $this->directory($batchId).'/'.self::DOCUMENT_FILE_NAME;
    }

    public function write(string $path, string $content): void
    {
        $this->filesystem->dumpFile($path, $content);
    }

    public function remove(string $path): void
    {
        $this->filesystem->remove($path);
    }

    /**
     * Only the document of a complete batch is kept: the invoices of each order and the
     * report are merged into it.
     */
    public function removeWorkingFiles(int $batchId): void
    {
        $directory = $this->directory($batchId);

        if (!is_dir($directory)) {
            return;
        }

        foreach (Finder::create()->files()->depth(0)->in($directory)->notName(self::DOCUMENT_FILE_NAME) as $file) {
            $this->filesystem->remove($file->getPathname());
        }
    }

    /**
     * A new batch makes the documents of the previous ones obsolete: they are deleted.
     */
    public function removeAllExcept(int $batchId): void
    {
        if (!is_dir($this->rootDirectory)) {
            return;
        }

        foreach (Finder::create()->directories()->depth(0)->in($this->rootDirectory) as $directory) {
            if ((string) $batchId !== $directory->getFilename()) {
                $this->filesystem->remove($directory->getPathname());
            }
        }
    }

    public function directory(int $batchId): string
    {
        return $this->rootDirectory.'/'.$batchId;
    }
}
