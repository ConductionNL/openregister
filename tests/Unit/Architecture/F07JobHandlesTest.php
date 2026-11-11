<?php

/**
 * The queued jobs other components enqueue are named through JobHandles.
 *
 * Callers outside lib/BackgroundJob hand JobHandles constants to the job list
 * instead of the job classes. Each constant must stay exactly the value the
 * caller used before, or a job is queued under a name nothing runs; and no
 * lib file outside lib/BackgroundJob may name the job class again.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\BackgroundJob\AuditTrailExportJob;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob;
use OCA\OpenRegister\BackgroundJob\CacheClearAndWarmJob;
use OCA\OpenRegister\BackgroundJob\ConsistencyCheckJob;
use OCA\OpenRegister\BackgroundJob\DeferredObjectEventJob;
use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\BackgroundJob\ImportPreviewRunner;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\BackgroundJob\ObjectCleanupJob;
use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob;
use OCA\OpenRegister\BackgroundJob\SchemaRunJob;
use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\BackgroundJob\SubjectExportJob;
use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\BackgroundJob\TranslationProjectionJob;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F07JobHandlesTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Handle => the job class it names.
	 *
	 * @var array<string, class-string>
	 */
	private const JOBS = [
		JobHandles::AUDIT_TRAIL_EXPORT => AuditTrailExportJob::class,
		JobHandles::BULK_JOB_RUNNER => BulkJobRunner::class,
		JobHandles::BULK_LEGAL_HOLD => BulkLegalHoldJob::class,
		JobHandles::CACHE_CLEAR_AND_WARM => CacheClearAndWarmJob::class,
		JobHandles::CONSISTENCY_CHECK => ConsistencyCheckJob::class,
		JobHandles::DEFERRED_OBJECT_EVENT => DeferredObjectEventJob::class,
		JobHandles::FILE_TEXT_EXTRACTION => FileTextExtractionJob::class,
		JobHandles::IMPORT_PREVIEW_RUNNER => ImportPreviewRunner::class,
		JobHandles::OBJECT_CLEANUP => ObjectCleanupJob::class,
		JobHandles::OBJECT_TEXT_EXTRACTION => ObjectTextExtractionJob::class,
		JobHandles::RELATED_RETENTION_RECALCULATION => RelatedRetentionRecalculationJob::class,
		JobHandles::SCHEMA_RUN => SchemaRunJob::class,
		JobHandles::SOURCE_RECORD_RECOMPUTE => SourceRecordRecomputeJob::class,
		JobHandles::SUBJECT_EXPORT => SubjectExportJob::class,
		JobHandles::TRANSFER_EXECUTION => TransferExecutionJob::class,
		JobHandles::TRANSLATION_PROJECTION => TranslationProjectionJob::class,
	];

	/**
	 * One case per job.
	 *
	 * @return array<string, array{string, class-string}>
	 */
	public static function jobs(): array {
		$cases = [];
		foreach (self::JOBS as $handle => $class) {
			$cases[$class] = [$handle, $class];
		}

		return $cases;
	}//end jobs()

	/**
	 * The handle is the job's class string, and that class is a job.
	 *
	 * @param string       $handle The JobHandles value.
	 * @param class-string $class  The job class.
	 *
	 * @return void
	 */
	#[DataProvider('jobs')]
	public function testTheHandleIsTheJobClass(string $handle, string $class): void {
		$this->assertSame(expected: $class, actual: $handle);
		$this->assertContains(needle: IJob::class, haystack: class_implements($class), message: $class . ' is a job');
	}//end testTheHandleIsTheJobClass()

	/**
	 * The argument constants keep the job's own values.
	 *
	 * @return void
	 */
	public function testTheArgumentConstantsKeepTheJobsValues(): void {
		$this->assertSame(expected: AuditTrailExportJob::INLINE_LIMIT, actual: JobHandles::AUDIT_TRAIL_EXPORT_INLINE_LIMIT);
		$this->assertSame(expected: AuditTrailExportJob::FOLDER, actual: JobHandles::AUDIT_TRAIL_EXPORT_FOLDER);
		$this->assertSame(expected: ImportPreviewRunner::PHASE_PREVIEW, actual: JobHandles::IMPORT_PREVIEW_PHASE_PREVIEW);
		$this->assertSame(expected: ImportPreviewRunner::PHASE_COMMIT, actual: JobHandles::IMPORT_PREVIEW_PHASE_COMMIT);
	}//end testTheArgumentConstantsKeepTheJobsValues()

	/**
	 * No lib file outside lib/BackgroundJob names the job class in code.
	 *
	 * @param string       $handle The JobHandles value.
	 * @param class-string $class  The job class.
	 *
	 * @return void
	 */
	#[DataProvider('jobs')]
	public function testNoFileOutsideBackgroundJobNamesTheJob(string $handle, string $class): void {
		$short = substr(string: $class, offset: (strrpos(haystack: $class, needle: '\\') + 1));
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = str_replace(search: '\\', replace: '/', subject: $file->getPathname());
			if ($file->isFile() === false || str_ends_with(haystack: $path, needle: '.php') === false
				|| str_contains(haystack: $path, needle: '/lib/BackgroundJob/') === true
			) {
				continue;
			}

			$code = preg_replace(pattern: '#/\*.*?\*/|//[^\n]*#s', replacement: '', subject: (string) file_get_contents($path));
			if (preg_match(pattern: '/\b' . $short . '\b/', subject: (string) $code) === 1) {
				$found[] = substr(string: $path, offset: (strpos(haystack: $path, needle: '/lib/') + 5));
			}
		}

		$this->assertSame(expected: [], actual: $found, message: 'Use ' . $handle . ' through JobHandles instead.');
	}//end testNoFileOutsideBackgroundJobNamesTheJob()
}//end class
