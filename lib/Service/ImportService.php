<?php

/**
 * OpenRegister Import Service
 *
 * This file contains the class for handling data import operations in the OpenRegister application.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use React\Async\PromiseInterface;
use React\Promise\Promise;
use Symfony\Component\Uid\Uuid;

/**
 * Service for importing data from various formats
 *
 * @package OCA\OpenRegister\Service
 */
class ImportService
{

    /**
     * Object entity mapper instance
     *
     * @var ObjectEntityMapper
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Schema mapper instance
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Object service instance
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Constructor for the ImportService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ObjectService      $objectService      The object service
     */
    public function __construct(ObjectEntityMapper $objectEntityMapper, SchemaMapper $schemaMapper, ObjectService $objectService)
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->schemaMapper       = $schemaMapper;
        $this->objectService      = $objectService;
    }//end __construct()


    /**
     * Import data from Excel file asynchronously
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromExcelAsync(string $filePath, ?Register $register=null, ?Schema $schema=null): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
                    try {
                        $result = $this->importFromExcel($filePath, $register, $schema);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end importFromExcelAsync()


    /**
     * Import data from Excel file
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     */
    public function importFromExcel(string $filePath, ?Register $register=null, ?Schema $schema=null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // If we have a register but no schema, process each sheet as a different schema
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheet($spreadsheet, $register);
        }

        return $this->processSpreadsheet($spreadsheet, $register, $schema);

    }//end importFromExcel()


    /**
     * Import data from CSV file asynchronously
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromCsvAsync(string $filePath, ?Register $register=null, ?Schema $schema=null): PromiseInterface
    {
        return new Promise(
                function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
                    try {
                        $result = $this->importFromCsv($filePath, $register, $schema);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

    }//end importFromCsvAsync()


    /**
     * Import data from CSV file
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     */
    public function importFromCsv(string $filePath, ?Register $register=null, ?Schema $schema=null): array
    {
        // CSV can only handle a single schema
        if ($schema === null) {
            throw new \InvalidArgumentException('CSV import requires a specific schema');
        }

        $reader = new Csv();
        $reader->setReadDataOnly(true);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setLineEnding("\r\n");
        $spreadsheet = $reader->load($filePath);

        return $this->processSpreadsheet($spreadsheet, $register, $schema);

    }//end importFromCsv()


    /**
     * Process spreadsheet with multiple schemas
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     */
    private function processMultiSchemaSpreadsheet(Spreadsheet $spreadsheet, Register $register): array
    {
        $summary = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
        ];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema     = $this->getSchemaBySlug($schemaSlug);

            // Skip sheets that don't correspond to a valid schema.
            if ($schema === null) {
                continue;
            }

            // Set the worksheet as active and process
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($worksheet));
            $sheetSummary = $this->processSpreadsheet($spreadsheet, $register, $schema);
            $summary['created'] = array_merge($summary['created'], $sheetSummary['created']);
            $summary['updated'] = array_merge($summary['updated'], $sheetSummary['updated']);
            $summary['unchanged'] = array_merge($summary['unchanged'], $sheetSummary['unchanged']);
        }

        return $summary;

    }//end processMultiSchemaSpreadsheet()


    /**
     * Process spreadsheet data and create/update objects using ObjectService
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to process
     * @param Register|null $register    Optional register to associate with imported objects
     * @param Schema|null   $schema      Optional schema to associate with imported objects
     *
     * @return array<string, array> Summary of import: ['created'=>[], 'updated'=>[], 'unchanged'=>[]]
     * @phpstan-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     * @psalm-return array{created: array<int|string>, updated: array<int|string>, unchanged: array<int|string>}
     */
    private function processSpreadsheet(Spreadsheet $spreadsheet, ?Register $register=null, ?Schema $schema=null): array
    {
        $sheet         = $spreadsheet->getActiveSheet();
        $highestRow    = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        // Get headers from first row.
        $headers = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headers[$col] = $sheet->getCell($col.'1')->getValue();
        }

        // Get schema properties for mapping
        $schemaProperties = $schema ? $schema->getProperties() : [];
        $propertyKeys = array_keys($schemaProperties);

        $summary = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
        ];

        // Process each row.
        for ($row = 2; $row <= $highestRow; $row++) {
            $objectData = [];
            $objectFields = [];

            // Collect data for each column.
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $header = $headers[$col];
                $value  = $sheet->getCell($col.$row)->getValue();

                // Skip empty values.
                if ($value === null || $value === '') {
                    continue;
                }

                if (in_array($header, $propertyKeys, true)) {
                    $objectData[$header] = $value;
                } else {
                    // Otherwise, treat as a top-level field
                   // $objectData[$header] = $value;
                }
            }

            // Get current timestamp before saving
            $beforeSave = new \DateTime();

            // Use ObjectService to save the object (handles create/update/validation)
            try {
                $savedObject = $this->objectService->saveObject(
                    $objectData,
                    [],
                    $register,
                    $schema,
                    $objectData['id']
                );

                // Get the created and updated timestamps from the saved object
                $created = $savedObject->getCreated();
                $updated = $savedObject->getUpdated();

                // Get the last log from the saved object
                $log = method_exists($savedObject, 'getLastLog') ? $savedObject->getLastLog() : null;

                // If created timestamp is after our beforeSave timestamp, it's a new object
                if ($created && $created > $beforeSave) {
                    $summary['created'][] = $log;
                }
                // If updated timestamp is after our beforeSave timestamp, it's an updated object
                else if ($updated && $updated > $beforeSave) {
                    $summary['updated'][] = $log;
                }
                // If neither timestamp is after beforeSave, the object was unchanged
                else {
                    $summary['unchanged'][] = $log;
                }
            } catch (\Exception $e) {
                // Optionally, handle or log errors for this row
                continue;
            }
        }
        return $summary;

    }//end processSpreadsheet()


    /**
     * Get schema by slug
     *
     * @param string $slug The schema slug
     *
     * @return Schema|null The schema or null if not found
     */
    private function getSchemaBySlug(string $slug): ?Schema
    {
        try {
            return $this->schemaMapper->find($slug);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }

    }//end getSchemaBySlug()


}//end class
