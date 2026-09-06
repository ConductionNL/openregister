<?php

declare(strict_types=1);

/**
 * RetentionSweepService Unit Tests
 *
 * Verifies the legal-hold-aware sweep: expired non-held cases are scrubbed
 * (erase pseudonymise) + hard-deleted, within-window cases are untouched,
 * dry-run reports without destroying, and a case under legal hold is skipped
 * intact.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for RetentionSweepService.
 */
class RetentionSweepServiceTest extends TestCase {

	/**
	 * Fixed "now" for deterministic window comparisons.
	 *
	 * @var int
	 */
	private int $now = 1751500000;

	/**
	 * Build a case entity with a given uuid + retainUntil + subjectId.
	 *
	 * @param string $uuid Case uuid.
	 * @param string $retainUntil ISO-8601 retain-until stamp (or '' for none).
	 * @param string $subjectId Subject id.
	 *
	 * @return ObjectEntity
	 */
	private function caseEntity(
		string $uuid,
		string $retainUntil,
		string $subjectId = 's@example.org',
		string $schema = 'dsar-case',
	): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema($schema);
		$object->setObject(['subjectId' => $subjectId, 'retainUntil' => $retainUntil]);
		return $object;
	}//end caseEntity()

	/**
	 * Build a REAL Schema, archival or not.
	 *
	 * The annotation is written as data and read back by
	 * Schema::hasArchivalAnnotation(). Nothing here restates the rule.
	 *
	 * @param string $identifier Schema slug.
	 * @param bool $archival Whether to declare `x-openregister-archival`.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $identifier, bool $archival): Schema {
		$schema = new Schema();
		$schema->setSlug($identifier);
		$configuration = [];
		if ($archival === true) {
			$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end makeSchema()

	/**
	 * Build the SUT with a fixed clock and the supplied cases + hold verdicts.
	 *
	 * @param ObjectEntity[] $cases Case entities to sweep.
	 * @param callable $holdResolver fn(ObjectEntity):bool legal-hold verdict.
	 * @param array<int, string> $archivalSchemas Schema slugs that declare `x-openregister-archival`.
	 *
	 * @return array{0: RetentionSweepService, 1: \PHPUnit\Framework\MockObject\MockObject}
	 */
	private function build(array $cases, callable $holdResolver, array $archivalSchemas = []): array {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('findAllCaseEntities')->willReturn($cases);

		$retention = $this->createMock(RetentionService::class);
		$retention->method('hasActiveLegalHold')->willReturnCallback($holdResolver);
		$retention->method('validateNotImmutable')->willReturn(null);

		$dsr = $this->createMock(DataSubjectRequestService::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($this->now);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			fn ($id) => $this->makeSchema(
				identifier: (string)$id,
				archival: in_array((string)$id, $archivalSchemas, true)
			)
		);

		$service = new RetentionSweepService(
			$accessor,
			$retention,
			$dsr,
			$time,
			$this->createMock(LoggerInterface::class),
			new ArchivalRetentionGuard($schemaMapper, $this->createMock(LoggerInterface::class))
		);

		return [$service, $accessor, $dsr];
	}//end build()

	/**
	 * An expired, non-held case is scrubbed (erase pseudonymise) + deleted.
	 *
	 * @return void
	 */
	public function testExpiredCaseIsScrubbedAndDeleted(): void {
		$expired = $this->caseEntity('case-expired', '2020-01-01T00:00:00+00:00');
		[$service, $accessor, $dsr] = $this->build([$expired], static fn (): bool => false);

		$dsr->expects($this->once())
			->method('erase')
			->with('s@example.org', null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE)
			->willReturn([]);

		$accessor->expects($this->once())
			->method('deleteForSweep')
			->with('case-expired')
			->willReturn(true);

		$summary = $service->runSweep(dryRun: false);
		$this->assertSame(['case-expired'], $summary['purged']);

	}//end testExpiredCaseIsScrubbedAndDeleted()

	/**
	 * A case still within its window is untouched.
	 *
	 * @return void
	 */
	public function testWithinWindowUntouched(): void {
		$future = $this->caseEntity('case-future', '2099-01-01T00:00:00+00:00');
		[$service, $accessor, $dsr] = $this->build([$future], static fn (): bool => false);

		$dsr->expects($this->never())->method('erase');
		$accessor->expects($this->never())->method('deleteForSweep');

		$summary = $service->runSweep(dryRun: false);
		$this->assertSame([], $summary['purged']);
		$this->assertSame(1, $summary['withinWindow']);

	}//end testWithinWindowUntouched()

	/**
	 * Dry-run reports the expired case without destroying anything.
	 *
	 * @return void
	 */
	public function testDryRunReportsWithoutDestroying(): void {
		$expired = $this->caseEntity('case-expired', '2020-01-01T00:00:00+00:00');
		[$service, $accessor, $dsr] = $this->build([$expired], static fn (): bool => false);

		$dsr->expects($this->never())->method('erase');
		$accessor->expects($this->never())->method('deleteForSweep');

		$summary = $service->runSweep(dryRun: true);
		$this->assertTrue($summary['dryRun']);
		$this->assertSame(['case-expired'], $summary['purged']);

	}//end testDryRunReportsWithoutDestroying()

	/**
	 * An expired case under an active legal hold is skipped intact.
	 *
	 * @return void
	 */
	public function testExpiredButHeldCaseSkipped(): void {
		$held = $this->caseEntity('case-held', '2020-01-01T00:00:00+00:00');
		[$service, $accessor, $dsr] = $this->build([$held], static fn (): bool => true);

		$dsr->expects($this->never())->method('erase');
		$accessor->expects($this->never())->method('deleteForSweep');

		$summary = $service->runSweep(dryRun: false);
		$this->assertSame(['case-held'], $summary['skippedHeld']);
		$this->assertSame([], $summary['purged']);

	}//end testExpiredButHeldCaseSkipped()
	/**
	 * RETENTION WINS OVER ERASURE, EVEN THROUGH THIS SWEEP'S OWN BYPASS.
	 *
	 * The dossier delete hands `_retentionSweep: true` to ObjectService, the flag
	 * whose whole purpose is to wave a row past the archival gate. So an expired
	 * case on an archival schema was hard-deleted here with no refusal anywhere
	 * in the chain. The gate is asked before the bypass is reached now.
	 *
	 * The refused case is named in `withheld` and the rest of the sweep runs.
	 *
	 * @return void
	 */
	public function testExpiredArchivalCaseIsWithheldWhileTheRestOfTheSweepRuns(): void {
		$ordinary = $this->caseEntity('case-ordinary', '2020-01-01T00:00:00+00:00', 'a@example.org', 'dsar-case');
		$retained = $this->caseEntity('case-retained', '2020-01-01T00:00:00+00:00', 'b@example.org', 'besluit');

		[$service, $accessor, $dsr] = $this->build(
			[$ordinary, $retained],
			static fn (): bool => false,
			['besluit']
		);

		// EXACTLY ONE DELETE, and it is the non-archival case. The retained case
		// never reaches deleteForSweep, so the bypass flag is never used on it.
		$accessor->expects($this->once())
			->method('deleteForSweep')
			->with('case-ordinary')
			->willReturn(true);

		// The retained case's evidence is not scrubbed either: withholding the
		// record means leaving it as it stands, not quietly redacting it.
		$dsr->expects($this->once())->method('erase')->willReturn([]);

		$summary = $service->runSweep(dryRun: false);

		$this->assertSame(['case-ordinary'], $summary['purged']);
		$this->assertCount(1, $summary['withheld']);
		$this->assertSame(1, $summary['withheldCount']);
		$this->assertSame('case-retained', $summary['withheld'][0]['uuid']);
		$this->assertSame(
			ArchivalRetentionGuard::GROUND_ARCHIVAL,
			$summary['withheld'][0]['ground']
		);
		$this->assertStringContainsString('Archiefwet', $summary['withheld'][0]['basis']);

	}//end testExpiredArchivalCaseIsWithheldWhileTheRestOfTheSweepRuns()
}//end class
