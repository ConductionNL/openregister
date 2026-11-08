<?php

declare(strict_types=1);

/**
 * RetentionSweepService golden test.
 *
 * Pins every outcome of one retention pass: not expired (missing,
 * unparseable or future `retainUntil`) is left alone; expired and under legal
 * hold or immutable is skipped BEFORE the archival question; expired on an
 * archival schema is withheld; the rest is scrubbed then deleted (or only
 * reported on a dry run). A scrub or delete failure is logged and does not
 * stop the sweep. The clock is fixed.
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
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepSources;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for RetentionSweepService.
 */
class RetentionSweepServiceGoldenTest extends TestCase {

	/**
	 * Fixed clock: 2026-06-01T00:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1780272000;

	/**
	 * Calls made, in order (`erase:<subject>`, `delete:<uuid>`, `info:..`, `error:..`).
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * A case entity.
	 *
	 * @param string $uuid Uuid.
	 * @param string|null $retainUntil Retain-until value (null = absent).
	 * @param string $schema Schema slug.
	 * @param string $subjectId Subject id.
	 *
	 * @return ObjectEntity
	 */
	private function caseEntity(string $uuid, ?string $retainUntil, string $schema = 'plain', string $subjectId = 's@example.org'): ObjectEntity {
		$data = ['subjectId' => $subjectId];
		if ($retainUntil !== null) {
			$data['retainUntil'] = $retainUntil;
		}

		$case = new ObjectEntity();
		$case->setUuid($uuid);
		$case->setSchema($schema);
		$case->setObject($data);
		return $case;

	}//end caseEntity()

	/**
	 * Build the service over the given cases.
	 *
	 * @param ObjectEntity[] $cases Cases the accessor enumerates.
	 *
	 * @return RetentionSweepService
	 */
	private function build(array $cases): RetentionSweepService {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('findAllCaseEntities')->willReturn($cases);
		$accessor->method('deleteForSweep')->willReturnCallback(
			function (string $caseUuid) {
				$this->calls[] = 'delete:' . $caseUuid;
				if ($caseUuid === 'undeletable') {
					throw new RuntimeException('locked');
				}

				return true;
			}
		);

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('hasActiveLegalHold')->willReturnCallback(
			static fn (ObjectEntity $o) => str_starts_with((string)$o->getUuid(), 'hold')
		);
		$retention->method('validateNotImmutable')->willReturnCallback(
			static fn (ObjectEntity $o) => str_starts_with((string)$o->getUuid(), 'frozen') ? 'OBJECT_DESTROYED' : null
		);

		$dsr = $this->createMock(DataSubjectRequestService::class);
		$dsr->method('erase')->willReturnCallback(
			function (...$args) {
				$this->calls[] = 'erase:' . $args[0] . ':' . $args[2];
				if ($args[0] === 'boom@example.org') {
					throw new RuntimeException('scrub broke');
				}

				return [];
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function ($message) {
				$this->calls[] = 'info:' . $message;
			}
		);
		$logger->method('error')->willReturnCallback(
			function ($message) {
				$this->calls[] = 'error:' . $message;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id) {
				$schema = new Schema();
				$schema->setSlug((string)$id);
				$configuration = [];
				if (str_starts_with((string)$id, 'archival') === true) {
					$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
				}

				$schema->setConfiguration($configuration);
				return $schema;
			}
		);

		return new RetentionSweepService(new RetentionSweepSources($accessor, $dsr, $time), $retention, $logger, new ArchivalRetentionGuard($schemaMapper, $this->createMock(LoggerInterface::class)));

	}//end build()

	/**
	 * The full set of cases every test sweeps.
	 *
	 * @return ObjectEntity[]
	 */
	private function cases(): array {
		return [
			$this->caseEntity('absent', null),
			$this->caseEntity('garbage', 'not a date'),
			$this->caseEntity('future', '2027-01-01T00:00:00Z'),
			$this->caseEntity('expired', '2026-01-01T00:00:00Z'),
			$this->caseEntity('hold-archival', '2026-01-01T00:00:00Z', 'archival'),
			$this->caseEntity('frozen-1', '2026-01-01T00:00:00Z'),
			$this->caseEntity('kept', '2026-01-01T00:00:00Z', 'archival-2'),
			$this->caseEntity('nosubject', '2026-01-01T00:00:00Z', 'plain', ''),
			$this->caseEntity('scrubfail', '2026-01-01T00:00:00Z', 'plain', 'boom@example.org'),
			$this->caseEntity('undeletable', '2026-01-01T00:00:00Z'),
		];

	}//end cases()

	/**
	 * A real sweep: buckets, call order, failures that do not stop it.
	 *
	 * @return void
	 */
	public function testSweep(): void {
		$summary = $this->build($this->cases())->runSweep();

		$this->assertSame(['dryRun', 'evaluated', 'purged', 'failed', 'skippedHeld', 'withheld', 'withinWindow', 'withheldCount'], array_keys($summary));
		$this->assertFalse($summary['dryRun']);
		$this->assertSame(10, $summary['evaluated']);
		$this->assertSame(3, $summary['withinWindow']);
		$this->assertSame(['hold-archival', 'frozen-1'], $summary['skippedHeld']);
		$this->assertSame(['kept'], array_column($summary['withheld'], 'uuid'));
		$this->assertSame([ArchivalRetentionGuard::GROUND_ARCHIVAL], array_column($summary['withheld'], 'ground'));
		$this->assertSame(1, $summary['withheldCount']);
		$this->assertSame(['expired', 'nosubject', 'scrubfail'], $summary['purged']);
		$this->assertSame(['undeletable'], $summary['failed']);

		$pseudo = DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE;
		$this->assertSame(
			[
				'erase:s@example.org:' . $pseudo,
				'delete:expired',
				'info:[RetentionSweep] case "expired" past window — dossier hard-deleted, evidence scrubbed',
				'info:[RetentionSweep] case "hold-archival" past window but under legal hold/immutable — skipped',
				'info:[RetentionSweep] case "frozen-1" past window but under legal hold/immutable — skipped',
				'delete:nosubject',
				'info:[RetentionSweep] case "nosubject" past window — dossier hard-deleted, evidence scrubbed',
				'erase:boom@example.org:' . $pseudo,
				'error:[RetentionSweep] evidence scrub failed for case "scrubfail": scrub broke',
				'delete:scrubfail',
				'info:[RetentionSweep] case "scrubfail" past window — dossier hard-deleted, evidence scrubbed',
				'erase:s@example.org:' . $pseudo,
				'delete:undeletable',
				'error:[RetentionSweep] dossier delete failed for case "undeletable": locked',
			],
			$this->calls
		);

	}//end testSweep()

	/**
	 * A dry run reports the same buckets and destroys nothing.
	 *
	 * @return void
	 */
	public function testDryRun(): void {
		$summary = $this->build($this->cases())->runSweep(dryRun: true);

		$this->assertTrue($summary['dryRun']);
		$this->assertSame(['expired', 'nosubject', 'scrubfail', 'undeletable'], $summary['purged']);
		$this->assertSame(['hold-archival', 'frozen-1'], $summary['skippedHeld']);
		$this->assertSame(1, $summary['withheldCount']);
		$this->assertSame(
			[
				'info:[RetentionSweep] case "hold-archival" past window but under legal hold/immutable — skipped',
				'info:[RetentionSweep] case "frozen-1" past window but under legal hold/immutable — skipped',
			],
			$this->calls
		);

	}//end testDryRun()

	/**
	 * An empty register yields an empty summary.
	 *
	 * @return void
	 */
	public function testEmpty(): void {
		$this->assertSame(
			['dryRun' => false, 'evaluated' => 0, 'purged' => [], 'failed' => [], 'skippedHeld' => [], 'withheld' => [], 'withinWindow' => 0, 'withheldCount' => 0],
			$this->build([])->runSweep()
		);

	}//end testEmpty()
}//end class
