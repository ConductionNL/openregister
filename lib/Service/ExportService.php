<?php

/**
 * OpenRegister Export Service
 *
 * This file contains the class for handling data export operations in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use React\EventLoop\Loop;

/**
 * Service for exporting data to various formats
 *
 * @package OCA\OpenRegister\Service
 */
class ExportService
{
    /**
     * Object entity mapper instance
     *
     * @var ObjectEntityMapper
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Register mapper instance
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Constructor for the ExportService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper    $registerMapper    The register mapper
     */
    public function __construct(ObjectEntityMapper $objectEntityMapper, RegisterMapper $registerMapper)
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->registerMapper = $registerMapper;
    }

    /**
     * Export data to Excel format asynchronously
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @return PromiseInterface<Spreadsheet> Promise that resolves with the generated spreadsheet
     */
    public function exportToExcelAsync(?Register $register = null, ?Schema $schema = null, array $filters = []): PromiseInterface
    {
        return new Promise(function (callable $resolve, callable $reject) use ($register, $schema, $filters) {
            try {
                $spreadsheet = $this->exportToExcel($register, $schema, $filters);
                $resolve($spreadsheet);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Export data to Excel format
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @return Spreadsheet The generated spreadsheet
     */
    public function exportToExcel(?Register $register = null, ?Schema $schema = null, array $filters = []): Spreadsheet
    {
        // Create new spreadsheet
        $spreadsheet = new Spreadsheet();
        
        // If we have a register but no schema, export each schema to its own tab
        if ($register !== null && $schema === null) {
            // Remove the default sheet
            $spreadsheet->removeSheetByIndex(0);
            
            // Get all schemas for this register
            $schemas = $this->getSchemasForRegister($register);
            
            foreach ($schemas as $schema) {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle($schema->getSlug());
                
                $this->populateSheet($sheet, $register, $schema, $filters);
            }
        } else {
            // Single schema export
            $sheet = $spreadsheet->getActiveSheet();
            if ($schema !== null) {
                $sheet->setTitle($schema->getSlug());
            }
            
            $this->populateSheet($sheet, $register, $schema, $filters);
        }

        return $spreadsheet;
    }

    /**
     * Export data to CSV format asynchronously
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @return PromiseInterface<string> Promise that resolves with the CSV content
     */
    public function exportToCsvAsync(?Register $register = null, ?Schema $schema = null, array $filters = []): PromiseInterface
    {
        return new Promise(function (callable $resolve, callable $reject) use ($register, $schema, $filters) {
            try {
                $csv = $this->exportToCsv($register, $schema, $filters);
                $resolve($csv);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Export data to CSV format
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @throws \InvalidArgumentException If trying to export multiple schemas to CSV
     *
     * @return string The CSV content
     */
    public function exportToCsv(?Register $register = null, ?Schema $schema = null, array $filters = []): string
    {
        // CSV can only handle a single schema
        if ($register !== null && $schema === null) {
            throw new \InvalidArgumentException('CSV export requires a specific schema');
        }

        $spreadsheet = $this->exportToExcel($register, $schema, $filters);
        
        // Create CSV writer
        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\r\n");
        $writer->setSheetIndex(0);

        // Get CSV content
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }

    /**
     * Populate a worksheet with data
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet    The worksheet to populate
     * @param Register|null                                 $register Optional register to filter by
     * @param Schema|null                                   $schema   Optional schema to filter by
     * @param array                                         $filters  Additional filters to apply
     *
     * @return void
     */
    private function populateSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, ?Register $register = null, ?Schema $schema = null, array $filters = []): void
    {
        // Get all objects based on filters
        $objects = $this->objectEntityMapper->findAll(
            limit: null,
            offset: null,
            filters: $filters,
            register: $register,
            schema: $schema
        );

        // Set headers
        $headers = $this->getHeaders($objects);
        $column = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($column, 1, $header);
            $column++;
        }

        // Style headers
        $headerStyle = [
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'CCCCCC',
                ],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ];
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyle);

        // Add data
        $row = 2;
        foreach ($objects as $object) {
            $column = 1;
            foreach ($headers as $header) {
                $value = $this->getObjectValue($object, $header);
                $sheet->setCellValueByColumnAndRow($column, $row, $value);
                $column++;
            }
            $row++;
        }

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * Get headers from objects
     *
     * @param array $objects Array of objects to extract headers from
     *
     * @return array Array of header names
     */
    private function getHeaders(array $objects): array
    {
        $headers = ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated'];
        
        // Add object-specific headers
        foreach ($objects as $object) {
            $objectData = $object->getObject();
            if (is_array($objectData)) {
                foreach (array_keys($objectData) as $key) {
                    if (!in_array($key, $headers)) {
                        $headers[] = $key;
                    }
                }
            }
        }

        return $headers;
    }

    /**
     * Get value from object for a specific header
     *
     * @param mixed  $object The object to get value from
     * @param string $header The header name
     *
     * @return mixed The value
     */
    private function getObjectValue(mixed $object, string $header): mixed
    {
        // Handle special fields
        switch ($header) {
            case 'id':
                return $object->getId();
            case 'uuid':
                return $object->getUuid();
            case 'uri':
                return $object->getUri();
            case 'register':
                return $object->getRegister();
            case 'schema':
                return $object->getSchema();
            case 'created':
                return $object->getCreated();
            case 'updated':
                return $object->getUpdated();
            default:
                $objectData = $object->getObject();
                return $objectData[$header] ?? null;
        }
    }

    /**
     * Get all schemas for a register
     *
     * @param Register $register The register to get schemas for
     *
     * @return array Array of Schema objects
     */
    private function getSchemasForRegister(Register $register): array
    {
        return $this->registerMapper->getSchemasByRegisterId($register->getId());
    }
} 