<?php

/**
 * OpenRegister Spreadsheet Extractor
 *
 * Dedicated handler for extracting plain text from spreadsheet files
 * (xlsx/ods/csv via PhpSpreadsheet). Split out of the TextExtractionService
 * god-class (extract-god-class-services) so each format lives in its own
 * single-responsibility class, mirroring the existing EmlParser handler.
 * Behaviour is identical to the former TextExtractionService::extractSpreadsheet().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\TextExtraction;

use Exception;
use OCP\Files\File;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Psr\Log\LoggerInterface;

/**
 * Extracts text content from spreadsheet files.
 */
class SpreadsheetExtractor
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Extract plain text from a spreadsheet file.
     *
     * @param File $file The spreadsheet file to extract.
     *
     * @return string|null The extracted text, or null when empty.
     *
     * @throws Exception When PhpSpreadsheet is unavailable or extraction fails.
     */
    public function extract(File $file): ?string
    {
        // PhpSpreadsheet should already be installed (in composer.json).
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory') === false) {
            $this->logger->warning(
                message: '[SpreadsheetExtractor] PhpSpreadsheet library not available',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $file->getId(),
                ]
            );
            $msg  = "PhpSpreadsheet library (phpoffice/phpspreadsheet) is not installed. ";
            $msg .= "Run: composer require phpoffice/phpspreadsheet";
            throw new Exception($msg);
        }

        try {
            $this->logger->debug(
                message: '[SpreadsheetExtractor] Extracting spreadsheet',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $file->getId(),
                    'name'   => $file->getName(),
                ]
            );

            // Get file content.
            $content = $file->getContent();

            // Create temporary file for PhpSpreadsheet.
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            fwrite($tempFile, $content);

            // Load spreadsheet.
            $spreadsheet = SpreadsheetIOFactory::load($tempPath);

            // Extract text from all sheets.
            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $text .= "Sheet: ".$sheet->getTitle()."\n";

                $highestRow    = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Iterate through rows and columns.
                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = [];
                    // @psalm-suppress StringIncrement - Excel column increment is intentional
                    for ($col = 'A'; $col !== $highestColumn; $col++) {
                        $value = $sheet->getCell($col.$row)->getValue();
                        if ($value !== null && $value !== '') {
                            $rowData[] = $value;
                        }
                    }

                    // Add last column.
                    $value = $sheet->getCell($highestColumn.$row)->getValue();
                    if ($value !== null && $value !== '') {
                        $rowData[] = $value;
                    }

                    if (empty($rowData) === false) {
                        $text .= implode("\t", $rowData)."\n";
                    }
                }

                $text .= "\n";
            }//end foreach

            // Clean up.
            fclose($tempFile);

            if (trim($text) === '' || trim($text) === null) {
                $this->logger->warning(
                    message: '[SpreadsheetExtractor] Spreadsheet extraction returned empty text',
                    context: [
                        'file'   => __FILE__,
                        'line'   => __LINE__,
                        'fileId' => $file->getId(),
                    ]
                );
                return null;
            }

            $this->logger->debug(
                message: '[SpreadsheetExtractor] Spreadsheet extracted successfully',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $file->getId(),
                    'length' => strlen($text),
                ]
            );

            return $text;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SpreadsheetExtractor] Spreadsheet extraction failed',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $file->getId(),
                    'error'  => $e->getMessage(),
                ]
            );
            throw new Exception("Spreadsheet extraction failed: ".$e->getMessage());
        }//end try
    }//end extract()
}//end class
