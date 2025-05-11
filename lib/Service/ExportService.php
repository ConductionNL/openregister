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
use OCA\OpenRegister\Db\ObjectEntity;
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
     * @param RegisterMapper     $registerMapper     The register mapper
     */
    public function __construct(ObjectEntityMapper $objectEntityMapper, RegisterMapper $registerMapper)
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->registerMapper     = $registerMapper;

    }//end __construct()


    /**
     * Export data to Excel format asynchronously
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @return PromiseInterface<Spreadsheet> Promise that resolves with the generated spreadsheet
     */
    public function exportToExcelAsync(?Register $register=null, ?Schema $schema=null, array $filters=[]): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($register, $schema, $filters) {
                    try {
                        $spreadsheet = $this->exportToExcel($register, $schema, $filters);
                        $resolve($spreadsheet);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end exportToExcelAsync()


    /**
     * Export data to Excel format
     *
     * @param Register|null $register Optional register to export
     * @param Schema|null   $schema   Optional schema to export
     * @param array         $filters  Optional filters to apply
     *
     * @return Spreadsheet
     */
    public function exportToExcel(?Register $register=null, ?Schema $schema=null, array $filters=[]): Spreadsheet
    {
        // Create new spreadsheet.
        $spreadsheet = new Spreadsheet();

        // Remove default sheet.
        $spreadsheet->removeSheetByIndex(0);

        if ($register !== null && $schema === null) {
            // Export all schemas in register.
            $schemas = $this->getSchemasForRegister($register);
            foreach ($schemas as $schema) {
                $this->populateSheet($spreadsheet, $register, $schema, $filters);
            }
        } else {
            // Export single schema.
            $this->populateSheet($spreadsheet, $register, $schema, $filters);
        }

        return $spreadsheet;

    }//end exportToExcel()


    /**
     * Export data to CSV format asynchronously
     *
     * @param Register|null $register Optional register to filter by
     * @param Schema|null   $schema   Optional schema to filter by
     * @param array         $filters  Additional filters to apply
     *
     * @return PromiseInterface<string> Promise that resolves with the CSV content
     */
    public function exportToCsvAsync(?Register $register=null, ?Schema $schema=null, array $filters=[]): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($register, $schema, $filters) {
                    try {
                        $csv = $this->exportToCsv($register, $schema, $filters);
                        $resolve($csv);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end exportToCsvAsync()


    /**
     * Export data to CSV format
     *
     * @param Register|null $register Optional register to export
     * @param Schema|null   $schema   Optional schema to export
     * @param array         $filters  Optional filters to apply
     *
     * @return string CSV content
     *
     * @throws InvalidArgumentException If trying to export multiple schemas to CSV
     */
    public function exportToCsv(?Register $register=null, ?Schema $schema=null, array $filters=[]): string
    {
        if ($register !== null && $schema === null) {
            throw new InvalidArgumentException('Cannot export multiple schemas to CSV format.');
        }

        $spreadsheet = $this->exportToExcel($register, $schema, $filters);
        $writer      = new Csv($spreadsheet);

        ob_start();
        $writer->save('php://output');
        return ob_get_clean();

    }//end exportToCsv()


    /**
     * Populate a worksheet with data
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to populate
     * @param Register|null $register    Optional register to export
     * @param Schema|null   $schema      Optional schema to export
     * @param array         $filters     Optional filters to apply
     *
     * @return void
     */
    private function populateSheet(Spreadsheet $spreadsheet, ?Register $register=null, ?Schema $schema=null, array $filters=[]): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($schema !== null ? $schema->getSlug() : 'data');

        $headers = $this->getHeaders($register, $schema);
        $row     = 1;

        // Write headers.
        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col.$row, $header);
        }

        // Add register and schema to filters if they are set.
        if ($register !== null) {
            $filters['register'] = $register->getId();
        }
        if ($schema !== null) {
            $filters['schema'] = $schema->getId();
        }

        // Get objects.
        $objects = $this->objectEntityMapper->findAll(filters: $filters);

        // Write data.
        foreach ($objects as $object) {
            $row++;
            foreach ($headers as $col => $header) {
                $value = $this->getObjectValue($object, $header);
                $sheet->setCellValue($col.$row, $value);
            }
        }

    }//end populateSheet()


    /**
     * Get headers for export
     *
     * @param Register|null $register Optional register to export
     * @param Schema|null   $schema   Optional schema to export
     *
     * @return array Headers indexed by column letter
     */
    private function getHeaders(?Register $register=null, ?Schema $schema=null): array
    {
        $headers = [
            'A' => 'id',
            'B' => 'uuid',
            'C' => 'uri',
        ];

        if ($register !== null) {
            $headers['D'] = 'register';
        }

        if ($schema !== null) {
            $headers['E'] = 'schema';
        }

        $headers['F'] = 'created';
        $headers['G'] = 'updated';

        // Add schema fields using getObject()
        if ($schema !== null) {
            $schemaObject = $schema->jsonSerialize();
            $col = 'H';
            foreach (array_keys($schemaObject) as $field) {
                $headers[$col] = $field;
                $col++;
            }
        }

        return $headers;

    }//end getHeaders()


    /**
     * Get value from object for given header
     *
     * @param ObjectEntity $object The object to get value from
     * @param string       $header The header to get value for
     *
     * @return mixed
     */
    private function getObjectValue(ObjectEntity $object, string $header)
    {
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
                return $object->getCreated()->format('Y-m-d H:i:s');
            case 'updated':
                return $object->getUpdated()->format('Y-m-d H:i:s');
            default:
                $data = $object->getObject();
                if (array_key_exists($header, $data)) {
                    return $data[$header];
                }
                return null;
        }

    }//end getObjectValue()


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

    }//end getSchemasForRegister()


}//end class
