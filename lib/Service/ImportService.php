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
     * Constructor for the ImportService
     *
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param SchemaMapper      $schemaMapper      The schema mapper
     */
    public function __construct(ObjectEntityMapper $objectEntityMapper, SchemaMapper $schemaMapper)
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->schemaMapper = $schemaMapper;
    }

    /**
     * Import data from Excel file asynchronously
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromExcelAsync(string $filePath, ?Register $register = null, ?Schema $schema = null): PromiseInterface
    {
        return new Promise(function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
            try {
                $result = $this->importFromExcel($filePath, $register, $schema);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Import data from Excel file
     *
     * @param string        $filePath The path to the Excel file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return array Array of imported object IDs
     */
    public function importFromExcel(string $filePath, ?Register $register = null, ?Schema $schema = null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        
        // If we have a register but no schema, process each sheet as a different schema
        if ($register !== null && $schema === null) {
            return $this->processMultiSchemaSpreadsheet($spreadsheet, $register);
        }
        
        return $this->processSpreadsheet($spreadsheet, $register, $schema);
    }

    /**
     * Import data from CSV file asynchronously
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @return PromiseInterface<array> Promise that resolves with array of imported object IDs
     */
    public function importFromCsvAsync(string $filePath, ?Register $register = null, ?Schema $schema = null): PromiseInterface
    {
        return new Promise(function (callable $resolve, callable $reject) use ($filePath, $register, $schema) {
            try {
                $result = $this->importFromCsv($filePath, $register, $schema);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Import data from CSV file
     *
     * @param string        $filePath The path to the CSV file
     * @param Register|null $register Optional register to associate with imported objects
     * @param Schema|null   $schema   Optional schema to associate with imported objects
     *
     * @throws \InvalidArgumentException If trying to import without a specific schema
     *
     * @return array Array of imported object IDs
     */
    public function importFromCsv(string $filePath, ?Register $register = null, ?Schema $schema = null): array
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
    }

    /**
     * Process spreadsheet with multiple schemas
     *
     * @param Spreadsheet $spreadsheet The spreadsheet to process
     * @param Register    $register    The register to associate with imported objects
     *
     * @return array Array of imported object IDs
     */
    private function processMultiSchemaSpreadsheet(Spreadsheet $spreadsheet, Register $register): array
    {
        $importedIds = [];
        
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $schemaSlug = $worksheet->getTitle();
            $schema = $this->getSchemaBySlug($schemaSlug);
            
            if ($schema === null) {
                continue; // Skip sheets that don't correspond to a valid schema
            }
            
            $sheetIds = $this->processSpreadsheet($spreadsheet, $register, $schema);
            $importedIds = array_merge($importedIds, $sheetIds);
        }
        
        return $importedIds;
    }

    /**
     * Process spreadsheet data and create objects
     *
     * @param Spreadsheet   $spreadsheet The spreadsheet to process
     * @param Register|null $register    Optional register to associate with imported objects
     * @param Schema|null   $schema      Optional schema to associate with imported objects
     *
     * @return array Array of imported object IDs
     */
    private function processSpreadsheet(Spreadsheet $spreadsheet, ?Register $register = null, ?Schema $schema = null): array
    {
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        // Get headers from first row
        $headers = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headers[$col] = $sheet->getCell($col . '1')->getValue();
        }
        
        $importedIds = [];
        
        // Process each row
        for ($row = 2; $row <= $highestRow; $row++) {
            $objectData = [];
            
            // Collect data for each column
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $header = $headers[$col];
                $value = $sheet->getCell($col . $row)->getValue();
                
                // Skip empty values
                if ($value === null || $value === '') {
                    continue;
                }
                
                // Handle special fields
                switch ($header) {
                    case 'id':
                        // Skip ID as it will be generated
                        continue 2;
                    case 'uuid':
                        $objectData['uuid'] = $value;
                        break;
                    case 'uri':
                        $objectData['uri'] = $value;
                        break;
                    case 'register':
                        // Skip register as it's provided as parameter
                        continue 2;
                    case 'schema':
                        // Skip schema as it's provided as parameter
                        continue 2;
                    case 'created':
                    case 'updated':
                        // Skip timestamp fields as they will be set automatically
                        continue 2;
                    default:
                        $objectData[$header] = $value;
                }
            }
            
            // Add register and schema if provided
            if ($register !== null) {
                $objectData['register'] = $register->getId();
            }
            if ($schema !== null) {
                $objectData['schema'] = $schema->getId();
            }
            
            // Generate UUID if not provided
            if (!isset($objectData['uuid'])) {
                $objectData['uuid'] = Uuid::v4();
            }
            
            // Create object
            $object = $this->objectEntityMapper->createFromArray($objectData);
            $importedIds[] = $object->getId();
        }
        
        return $importedIds;
    }

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
    }
} 