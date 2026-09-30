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

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Concatenates PDF files into one, each page kept at its own size and orientation.
 *
 * FPDI keeps every source file open until the document is written: the sources are merged
 * by groups into intermediate files, then the intermediate files are merged the same way,
 * so a merge never holds more than one group of files open, whatever the number of sources.
 */
final readonly class PdfMerger
{
    public const DEFAULT_GROUP_SIZE = 50;

    public function __construct(
        private int $groupSize = self::DEFAULT_GROUP_SIZE,
    ) {
    }

    /**
     * @param list<string> $sourcePaths
     */
    public function merge(array $sourcePaths, string $targetPath): void
    {
        $level = 0;
        $intermediatePaths = [];

        try {
            while (\count($sourcePaths) > $this->groupSize) {
                $mergedGroups = [];

                foreach (array_chunk($sourcePaths, max(2, $this->groupSize)) as $index => $group) {
                    $groupPath = \sprintf('%s.part-%d-%d.pdf', $targetPath, $level, $index);
                    $this->mergeGroup($group, $groupPath);
                    $mergedGroups[] = $groupPath;
                    $intermediatePaths[] = $groupPath;
                }

                $sourcePaths = $mergedGroups;
                ++$level;
            }

            $this->mergeGroup($sourcePaths, $targetPath);
        } finally {
            foreach ($intermediatePaths as $intermediatePath) {
                if (is_file($intermediatePath)) {
                    unlink($intermediatePath);
                }
            }
        }
    }

    /**
     * Whether FPDI can read the given PDF content, as the merge will have to.
     */
    public function isReadable(string $pdfContent): bool
    {
        try {
            return (new Fpdi())->setSourceFile(StreamReader::createByString($pdfContent)) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $sourcePaths
     */
    private function mergeGroup(array $sourcePaths, string $targetPath): void
    {
        $document = new Fpdi();

        foreach ($sourcePaths as $sourcePath) {
            $pageCount = $document->setSourceFile($sourcePath);

            for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
                $template = $document->importPage($pageNumber);
                $size = $document->getTemplateSize($template);

                $document->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $document->useTemplate($template);
            }
        }

        $document->Output('F', $targetPath);
    }
}
