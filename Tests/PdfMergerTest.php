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

namespace ProcessAndInvoice\Tests;

use PHPUnit\Framework\TestCase;
use ProcessAndInvoice\Service\PdfMerger;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Filesystem\Filesystem;

/**
 * No kernel, no database: the merge alone, on generated one-page PDFs.
 */
final class PdfMergerTest extends TestCase
{
    private const SOURCE_COUNT = 300;

    private const OPEN_FILES_LIMIT = 64;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/process-and-invoice-merge-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /**
     * FPDI keeps each source open until the document is written: a batch of a thousand orders
     * would exceed the open files limit of the process if they were all merged at once.
     */
    public function testHundredsOfInvoicesAreMergedUnderALowOpenFilesLimit(): void
    {
        $sources = [];
        for ($index = 1; $index <= self::SOURCE_COUNT; ++$index) {
            $sources[] = $this->onePagePdf($index);
        }
        $target = $this->directory.'/merged.pdf';

        $command = \sprintf(
            'ulimit -n %d && exec %s %s %s %s 2>&1',
            self::OPEN_FILES_LIMIT,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(__DIR__.'/Concurrency/merge_worker.php'),
            escapeshellarg($target),
            implode(' ', array_map('escapeshellarg', $sources)),
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame(self::SOURCE_COUNT, (new Fpdi())->setSourceFile($target));
        self::assertSame([$target], glob($this->directory.'/merged.pdf*'), 'The intermediate files are removed.');
    }

    public function testASmallMergeKeepsEveryPageInOrder(): void
    {
        $target = $this->directory.'/merged.pdf';

        (new PdfMerger(2))->merge([$this->onePagePdf(1), $this->onePagePdf(2), $this->onePagePdf(3)], $target);

        self::assertSame(3, (new Fpdi())->setSourceFile($target));
        $text = (string) file_get_contents($target);
        self::assertLessThan(strpos($text, 'Page 3') ?: \PHP_INT_MAX, strpos($text, 'Page 1') ?: 0);
    }

    public function testUnreadableContentIsDetected(): void
    {
        $merger = new PdfMerger();

        self::assertFalse($merger->isReadable('not a PDF'));
        self::assertTrue($merger->isReadable((string) file_get_contents($this->onePagePdf(1))));
    }

    private function onePagePdf(int $number): string
    {
        $pdf = new \FPDF();
        $pdf->SetCompression(false);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, 'Page '.$number);

        $path = \sprintf('%s/source-%04d.pdf', $this->directory, $number);
        $pdf->Output('F', $path);

        return $path;
    }
}
