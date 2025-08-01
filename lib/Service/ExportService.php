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

        // Write headers using property keys for consistency with imports.
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
     * @return array Headers indexed by column letter with property key as value
     */
    private function getHeaders(?Register $register=null, ?Schema $schema=null): array
    {
        // Start with id as the first column
        $headers = [
            'A' => 'id',  // Will contain the uuid
        ];

        // Add schema fields from the schema properties
        if ($schema !== null) {
            $col = 'B';  // Start after id column
            $properties = $schema->getProperties();
            
            // Sort properties by their order in the schema
            foreach ($properties as $fieldName => $fieldDefinition) {
                // Skip fields that are already in the default headers
                if (in_array($fieldName, ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated'])) {
                    continue;
                }
                
                // Always use the property key as the header to ensure consistent data access
                $headers[$col] = $fieldName;
                $col++;
            }
        }

        // Add other metadata fields at the end with _ prefix
        $metadataFields = [
            'created',
            'updated',
            'published',
            'depublished',
            'deleted',
            'locked',
            'owner',
            'organisation',
            'application',
            'folder',
            'size',
            'version',
            'schemaVersion',
            'uri',
            'register',
            'schema',
            'name',
            'description',
            'validation',
            'geo',
            'retention',
            'authorization',
            'groups',
        ];

        foreach ($metadataFields as $field) {
            $headers[$col] = '_' . $field;
            $col++;
        }

        return $headers;
    }




    /**
     * Get value from object for given header
     *
     * @param ObjectEntity $object The object to get value from
     * @param string       $header The header to get value for
     *
     * @return string|null
     */
    private function getObjectValue(ObjectEntity $object, string $header): ?string
    {
        // Handle metadata fields with _ prefix
        if (str_starts_with($header, '_')) {
            $fieldName = substr($header, 1); // Remove the _ prefix
            
            // Get the object array which contains all metadata
            $objectArray = $object->getObjectArray();
            
            // Check if the field exists in the object array
            if (isset($objectArray[$fieldName])) {
                $value = $objectArray[$fieldName];
                
                // Handle DateTime objects (they come as ISO strings from getObjectArray)
                if (is_string($value) && str_contains($value, 'T') && str_contains($value, 'Z')) {
                    // Convert ISO 8601 to our preferred format
                    try {
                        $date = new \DateTime($value);
                        return $date->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        return $value; // Return as-is if parsing fails
                    }
                }
                
                // Handle arrays and objects
                if (is_array($value) || is_object($value)) {
                    return $this->convertValueToString($value);
                }
                
                // Handle scalar values
                return $value !== null ? (string) $value : null;
            }
            
            // Fallback for fields that might not exist
            return null;
        }

        // Handle regular fields
        switch ($header) {
            case 'id':
                return $object->getUuid();  // Return uuid for id column
            default:
                // Get value from object data and convert to string
                $objectData = $object->getObject();
                $value = $objectData[$header] ?? null;
                return $this->convertValueToString($value);
        }
    }

    /**
     * Convert a value to a string representation
     *
     * @param mixed $value The value to convert
     *
     * @return string|null
     */
    private function convertValueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // Convert array to JSON string
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            // Convert object to JSON string
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Fallback for any other type
        return (string) $value;
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

    }//end getSchemasForRegister()


}//end class
