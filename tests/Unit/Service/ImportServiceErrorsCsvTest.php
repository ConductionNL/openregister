<?php

declare(strict_types=1);

/**
 * ImportService Errors CSV Serializer Tests
 *
 * Validates the per-row error CSV serializer wired into the import response
 * envelope (data-import-export Phase 2 task: downloadable error CSV).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author   Conduction Development Team <dev@conductio.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link     https://OpenRegister.app
 *
 * @spec openspec/changes/data-import-export/tasks.md#task-error-csv
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the per-row error CSV serializer.
 */
class ImportServiceErrorsCsvTest extends TestCase
{

    /**
     * @var ImportService
     */
    private ImportService $service;


    protected function setUp(): void
    {
        /** @var SchemaMapper&MockObject $schemaMapper */
        $schemaMapper = $this->createMock(SchemaMapper::class);
        /** @var ObjectService&MockObject $objectService */
        $objectService = $this->createMock(ObjectService::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        /** @var IGroupManager&MockObject $groupManager */
        $groupManager = $this->createMock(IGroupManager::class);
        /** @var IJobList&MockObject $jobList */
        $jobList = $this->createMock(IJobList::class);
        /** @var TranslationCsvCodec&MockObject $translationCsvCodec */
        $translationCsvCodec = $this->createMock(TranslationCsvCodec::class);

        $this->service = new ImportService(
            $schemaMapper,
            $objectService,
            $logger,
            $groupManager,
            $jobList,
            $translationCsvCodec,
            $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class)
        );

    }//end setUp()


    /**
     * An empty summary returns an empty string so callers can skip attaching
     * the artefact entirely.
     */
    public function testReturnsEmptyStringWhenNoErrorsPresent(): void
    {
        $summary = [
            'people' => [
                'found'   => 5,
                'created' => [['id' => 1]],
                'errors'  => [],
            ],
        ];

        $this->assertSame('', $this->service->serializeErrorsToCsv(summary: $summary));

    }//end testReturnsEmptyStringWhenNoErrorsPresent()


    /**
     * The output starts with the UTF-8 BOM so Excel detects encoding.
     */
    public function testIncludesUtf8BomPrefix(): void
    {
        $summary = [
            'people' => [
                'errors' => [
                    [
                        'row'   => 4,
                        'error' => 'Something broke',
                    ],
                ],
            ],
        ];

        $csv = $this->service->serializeErrorsToCsv(summary: $summary);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

    }//end testIncludesUtf8BomPrefix()


    /**
     * The header row matches the documented column order.
     */
    public function testEmitsExpectedHeaderRow(): void
    {
        $summary = [
            'people' => [
                'errors' => [
                    [
                        'row'   => 4,
                        'error' => 'broken',
                    ],
                ],
            ],
        ];

        $csv  = $this->service->serializeErrorsToCsv(summary: $summary);
        $body = ltrim($csv, "\xEF\xBB\xBF");
        $this->assertStringStartsWith(
            "sheet,row,field,error_message,original_value\n",
            $body
        );

    }//end testEmitsExpectedHeaderRow()


    /**
     * Validation-shaped errors (with `object`/`error`/`type`) round-trip
     * cleanly: type collapses into `field`, the failing object becomes
     * the JSON-encoded `original_value`.
     */
    public function testValidationErrorShapeRendersCorrectly(): void
    {
        $summary = [
            'people' => [
                'errors' => [
                    [
                        'sheet'  => 'people',
                        'object' => ['name' => 'Alice', 'age' => 'not-a-number'],
                        'error'  => 'age must be integer',
                        'type'   => 'ValidationException',
                    ],
                ],
            ],
        ];

        $csv  = $this->service->serializeErrorsToCsv(summary: $summary);
        $body = ltrim($csv, "\xEF\xBB\xBF");

        $this->assertStringContainsString('ValidationException', $body);
        $this->assertStringContainsString('age must be integer', $body);
        $this->assertStringContainsString('"Alice"', $body);
        $this->assertStringContainsString('not-a-number', $body);

    }//end testValidationErrorShapeRendersCorrectly()


    /**
     * Row-shaped errors (header parse failures) keep their row number and
     * fall back to the sheet key when no `sheet` is set on the entry.
     */
    public function testRowParseErrorUsesSheetKeyAsFallback(): void
    {
        $summary = [
            'imports' => [
                'errors' => [
                    [
                        'row'    => 1,
                        'object' => [],
                        'error'  => 'No valid headers found in CSV file',
                    ],
                ],
            ],
        ];

        $csv  = $this->service->serializeErrorsToCsv(summary: $summary);
        $body = ltrim($csv, "\xEF\xBB\xBF");

        $this->assertStringContainsString(
            'imports,1,,"No valid headers found in CSV file"',
            $body
        );

    }//end testRowParseErrorUsesSheetKeyAsFallback()


    /**
     * Errors from multiple sheets are concatenated into a single CSV.
     */
    public function testCombinesErrorsAcrossSheets(): void
    {
        $summary = [
            'people' => [
                'errors' => [
                    ['row' => 2, 'error' => 'people row 2 broken'],
                ],
            ],
            'orgs'   => [
                'errors' => [
                    ['row' => 3, 'error' => 'orgs row 3 broken'],
                ],
            ],
        ];

        $csv  = $this->service->serializeErrorsToCsv(summary: $summary);
        $body = ltrim($csv, "\xEF\xBB\xBF");

        // 1 header row + 2 data rows + trailing newline.
        $this->assertSame(3, substr_count($body, "\n"));
        $this->assertStringContainsString('people row 2 broken', $body);
        $this->assertStringContainsString('orgs row 3 broken', $body);

    }//end testCombinesErrorsAcrossSheets()


    /**
     * Sheets with no `errors` key (or with non-array values) are silently
     * skipped — the serializer never throws on a partial summary.
     */
    public function testSkipsSheetsWithoutErrors(): void
    {
        $summary = [
            'people' => [
                'created' => [['id' => 1]],
            ],
            'orgs'   => [
                'errors' => 'not-an-array',
            ],
            'cities' => [
                'errors' => [
                    ['row' => 7, 'error' => 'cities row 7 broken'],
                ],
            ],
        ];

        $csv  = $this->service->serializeErrorsToCsv(summary: $summary);
        $body = ltrim($csv, "\xEF\xBB\xBF");

        $this->assertStringContainsString('cities row 7 broken', $body);
        $this->assertStringNotContainsString('orgs', $body);

    }//end testSkipsSheetsWithoutErrors()


}//end class
