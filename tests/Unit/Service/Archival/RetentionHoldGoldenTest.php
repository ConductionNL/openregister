<?php

declare(strict_types=1);

/**
 * Golden tests pinning legal holds, the erasure/cascade retention guard and retention evaluation.
 *
 * Written against the code before a maintainability refactor, so the refactor
 * can prove that what is held, refused, released, and computed did not move.
 * Clock- and random-dependent values are checked for their shape and masked.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\Archival\HoldStamp;
use OCA\OpenRegister\Service\Archival\LegalHoldLedger;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\LegalHoldRecords;
use OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of legal holds, the retention guard and retention evaluation.
 */
class RetentionHoldGoldenTest extends TestCase {

	/** @var array<int, array<string, mixed>> Retention blocks written. */
	private array $updates = [];

	/** @var array<int, array{0: string, 1: mixed}> Jobs queued. */
	private array $jobs = [];

	/**
	 * Mask hold ids and moments so the structure can be compared whole.
	 *
	 * @param mixed $value The structure.
	 *
	 * @return mixed The masked structure.
	 */
	private function mask(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === 'id' && is_string($item) === true && preg_match('/^[0-9a-f-]{36}$/', $item) === 1) {
				$value[$key] = '<uuid>';
				continue;
			}

			if (in_array($key, ['placedDate', 'releasedDate'], true) === true && is_string($item) === true && preg_match('/^\d{4}-\d{2}-\d{2}T/', $item) === 1) {
				$value[$key] = '<now>';
				continue;
			}

			$value[$key] = $this->mask($item);
		}

		return $value;
	}//end mask()

	/**
	 * A legal hold service with a recording mapper and job list.
	 *
	 * @param string|null              $uid     The current user, or null for none.
	 * @param RetentionRowScanner|null $scanner The scanner, if any.
	 *
	 * @return LegalHoldService The service.
	 */
	private function holds(?string $uid = 'officer', ?RetentionRowScanner $scanner = null): LegalHoldService {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->updates[] = $object->getRetention();
				return $object;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->jobs[] = [$job, $argument];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new LegalHoldService(
			records: new LegalHoldRecords(objectMapper: $mapper, auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class), scanner: $scanner),
			userSession: $session,
			jobList: $jobs,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end holds()

	public function testHoldsArePlacedPerMatterAndReleasedPerMatterOrAll(): void {
		$object = new ObjectEntity();
		$object->setUuid('rec');
		$object->setRetention(['archiefactiedatum' => '2030-01-01']);

		$service = $this->holds();
		$service->placeHold($object, 'WOO-1', 'case:a');
		$service->placeHold($object, 'manual');
		$service->placeHold($object, 'WOO-1 restated', 'case:a');

		$this->assertTrue($service->hasActiveHold($object));
		$this->assertTrue($service->hasActiveHoldFromRetention($object->getRetention()));
		$placed = [
			'id' => '<uuid>', 'ownerKey' => 'case:a', 'reason' => 'WOO-1 restated', 'placedBy' => 'officer', 'placedDate' => '<now>',
		];
		$manual = [
			'id' => '<uuid>', 'ownerKey' => 'openregister:manual', 'reason' => 'manual', 'placedBy' => 'officer', 'placedDate' => '<now>',
		];
		$this->assertSame(
			[
				'archiefactiedatum' => '2030-01-01',
				'legalHold' => [
					'active' => true, 'reason' => 'manual', 'placedBy' => 'officer', 'placedDate' => '<now>',
					'holds' => [$placed, $manual], 'history' => [],
				],
			],
			$this->mask($object->getRetention())
		);

		$this->holds(uid: null)->releaseHold($object, 'case closed', 'case:a');
		$this->assertSame(
			[
				'active' => true, 'reason' => 'manual', 'placedBy' => 'officer', 'placedDate' => '<now>',
				'holds' => [$manual],
				'history' => [$placed + ['releasedBy' => 'system', 'releasedDate' => '<now>', 'releaseReason' => 'case closed']],
			],
			$this->mask($object->getRetention()['legalHold'])
		);

		$service->releaseHold($object, 'all done');
		$this->assertFalse($service->hasActiveHold($object));
		$this->assertFalse($service->hasActiveHoldFromRetention($object->getRetention()));
		$this->assertSame(
			[
				'active' => false, 'reason' => null, 'placedBy' => null, 'placedDate' => null, 'holds' => [],
				'history' => [
					$placed + ['releasedBy' => 'system', 'releasedDate' => '<now>', 'releaseReason' => 'case closed'],
					$manual + ['releasedBy' => 'officer', 'releasedDate' => '<now>', 'releaseReason' => 'all done'],
				],
			],
			$this->mask($object->getRetention()['legalHold'])
		);
		$this->assertCount(5, $this->updates);
	}//end testHoldsArePlacedPerMatterAndReleasedPerMatterOrAll()

	public function testTheLedgerReadsALegacySlotAndOddShapes(): void {
		$ledger = new LegalHoldLedger();
		$legacy = ['legalHold' => ['active' => true, 'reason' => 'old', 'placedBy' => 'p', 'placedDate' => 'd']];
		$this->assertSame(
			[['id' => 'legacy', 'ownerKey' => 'openregister:manual', 'reason' => 'old', 'placedBy' => 'p', 'placedDate' => 'd']],
			$ledger->activeHolds($legacy)
		);
		$this->assertSame([], $ledger->activeHolds(['legalHold' => ['active' => 'yes']]));
		$this->assertSame([], $ledger->activeHolds(['legalHold' => 'x']));
		$this->assertSame([['ownerKey' => 'k']], $ledger->activeHolds(['legalHold' => ['holds' => ['junk', ['ownerKey' => 'k']]]]));

		$this->assertSame(
			[
				'legalHold' => [
					'active' => true, 'reason' => 'old2', 'placedBy' => 'p', 'placedDate' => 'd',
					'holds' => [['id' => 'legacy', 'ownerKey' => 'openregister:manual', 'reason' => 'old2', 'placedBy' => 'p', 'placedDate' => 'd']],
					'history' => [],
				],
			],
			$ledger->place($legacy, 'old2', '  ', new HoldStamp('u', 'now'))
		);

		$this->assertSame(
			['legalHold' => ['active' => true, 'reason' => 'old', 'placedBy' => 'p', 'placedDate' => 'd', 'holds' => $ledger->activeHolds($legacy), 'history' => []]],
			$ledger->release($legacy, 'case:none', 'r', new HoldStamp('u', 'now'))
		);
		$this->assertSame([], $ledger->release($legacy, ' openregister:manual', 'r', new HoldStamp('u', 'now'))['legalHold']['history']);
		$this->assertSame(
			['active' => false, 'reason' => null, 'placedBy' => null, 'placedDate' => null, 'holds' => [], 'history' => [['h' => 1], ['ownerKey' => 'k', 'releasedBy' => 'u', 'releasedDate' => 'now', 'releaseReason' => 'r']]],
			$ledger->release(['legalHold' => ['holds' => [['ownerKey' => 'k']], 'history' => [['h' => 1]]]], null, 'r', new HoldStamp('u', 'now'))['legalHold']
		);
		$this->assertSame(
			['active' => false, 'reason' => null, 'placedBy' => null, 'placedDate' => null, 'holds' => [], 'history' => []],
			$ledger->release(['legalHold' => ['history' => 'bad']], null, 'r', new HoldStamp('u', 'now'))['legalHold']
		);
	}//end testTheLedgerReadsALegacySlotAndOddShapes()

	public function testBulkHoldsAreQueuedAndHeldRecordsListed(): void {
		$this->holds()->bulkPlaceHold(4, 2, 'WOO');
		$this->assertSame([[BulkLegalHoldJob::class, ['schemaId' => 4, 'registerId' => 2, 'reason' => 'WOO', 'placedBy' => 'officer']]], $this->jobs);

		$this->assertSame(['results' => [], 'total' => 0, 'truncated' => false], $this->holds()->listActiveHolds());

		$held = new ObjectEntity();
		$held->setUuid('h');
		$held->setRegister('2');
		$held->setSchema('4');
		$held->setName('Dossier');
		$held->setRetention(['legalHold' => ['holds' => [['ownerKey' => 'k', 'reason' => 'r']]]]);
		$free = new ObjectEntity();
		$free->setUuid('f');

		$scanner = $this->createMock(RetentionRowScanner::class);
		$scanner->method('scan')->willReturnCallback(
			function (callable $accept) use ($held, $free): array {
				$this->assertFalse($accept($free));
				return ['objects' => [$held], 'scanned' => 2, 'truncated' => true];
			}
		);

		$this->assertSame(
			[
				'results' => [['id' => 'h', 'register' => '2', 'schema' => '4', 'name' => 'Dossier', 'holds' => [['ownerKey' => 'k', 'reason' => 'r']]]],
				'total' => 1,
				'truncated' => true,
			],
			$this->holds(scanner: $scanner)->listActiveHolds()
		);
	}//end testBulkHoldsAreQueuedAndHeldRecordsListed()

	/**
	 * A retention guard over a mapper that knows these schemas.
	 *
	 * @param array<string, Schema> $schemas Schemas by identifier.
	 * @param array<int, string>    $asked   Identifiers the mapper was asked for.
	 *
	 * @return ArchivalRetentionGuard The guard.
	 */
	private function guard(array $schemas, array &$asked): ArchivalRetentionGuard {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id) use ($schemas, &$asked): Schema {
				$asked[] = (string)$id;
				if (isset($schemas[(string)$id]) === false) {
					throw new RuntimeException('gone');
				}

				return $schemas[(string)$id];
			}
		);

		return new ArchivalRetentionGuard(schemaMapper: $mapper, logger: $this->createMock(LoggerInterface::class));
	}//end guard()

	public function testTheGuardRefusesHeldArchivalAndUnreadableRecordsOnly(): void {
		$schema = function (bool $archival, int $id, ?string $slug): Schema {
			$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['hasArchivalAnnotation'])->getMock();
			$schema->method('hasArchivalAnnotation')->willReturn($archival);
			$schema->setId($id);
			$schema->setSlug($slug);
			return $schema;
		};
		$archival = $schema(true, 12, null);
		$plain = $schema(false, 13, 'plain');
		$sluggy = $schema(true, 14, 'zaken');

		$asked = [];
		$guard = $this->guard(['12' => $archival, '13' => $plain, '14' => $sluggy], $asked);

		$record = static function (string $uuid, ?string $schema, ?string $register, bool $held): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setSchema($schema);
			$object->setRegister($register);
			if ($held === true) {
				$object->setRetention(['legalHold' => ['active' => true]]);
			}

			return $object;
		};

		$this->assertSame(
			[
				'uuid' => 'a', 'schema' => '13', 'ground' => 'ACTIVE_LEGAL_HOLD', 'operation' => 'erasure',
				'message' => 'This record is under a legal hold, so we did not erase it.',
				'basis' => 'A hold placed by a records officer, usually for a case or an investigation.',
				'action' => 'Name this record in your answer to the requester. It can be erased once the hold is released.',
				'register' => '5',
			],
			$guard->erasureRefusal($record('a', '13', '5', true))
		);
		$this->assertSame([], $asked);
		$this->assertNull($guard->erasureRefusal($record('b', '13', '5', false)));
		$this->assertSame(
			[
				'uuid' => 'c', 'schema' => '12', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'erasure',
				'message' => 'The law requires us to keep this record, so we did not erase it.',
				'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
				'action' => 'Name this record in your answer to the requester. A records officer decides when it may be destroyed.',
			],
			$guard->erasureRefusal($record('c', '12', '', false))
		);
		$this->assertSame(
			[
				'uuid' => 'd', 'schema' => '99', 'ground' => 'SCHEMA_UNRESOLVED', 'operation' => 'erasure',
				'message' => 'We could not read the retention rules for this record, so we left it alone.',
				'basis' => 'Precaution, because a retention rule we cannot read may be a legal one.',
				'action' => 'Ask an administrator to repair the schema, then run the request again.',
				'register' => '7',
			],
			$guard->erasureRefusal($record('d', '99', '7', false))
		);
		$this->assertSame('SCHEMA_UNRESOLVED', $guard->erasureRefusal($record('e', null, null, false))['ground']);
		$this->assertSame('unknown', $guard->erasureRefusal($record('e', null, null, false))['schema']);

		$this->assertSame(
			[
				'uuid' => 'k', 'schema' => 'zaken', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION', 'operation' => 'cascade',
				'message' => 'The law requires us to keep this record. Its parent is gone, this record stays.',
				'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
				'action' => 'Point this record at a live parent, or record why the reference may dangle.',
			],
			$guard->cascadeRefusal('k', 14)
		);
		$this->assertNull($guard->cascadeRefusal('k', '13'));
		$this->assertSame(
			'Ask an administrator to repair the schema, then clean up this record by hand.',
			$guard->cascadeRefusal('k', '99')['action']
		);
		$this->assertSame('0', $guard->cascadeRefusal('k', 0)['schema']);
		$this->assertSame('unknown', $guard->cascadeRefusal('k', null)['schema']);
		$this->assertSame('', $guard->cascadeRefusal('k', '')['schema']);

		// One lookup per identifier, a miss memoised too; blank identifiers never asked.
		$this->assertSame(['13', '12', '99', '14'], $asked);
	}//end testTheGuardRefusesHeldArchivalAndUnreadableRecordsOnly()

	public function testEvaluationPicksTheFirstMatchingRuleAndTheDeclaredFacts(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$evaluator = new RetentionEvaluator(logger: $logger);
		$created = new DateTimeImmutable('2020-01-31T12:00:00+00:00');

		$annotation = [
			'retention' => [
				'default' => 'P10Y',
				'rules' => [
					'junk',
					['condition' => 'x', 'retention' => 5],
					['condition' => 'kind = 1', 'retention' => 'P1Y'],
					['condition' => 'kind == "b"', 'retention' => 'P1M'],
					['condition' => 'age >= 18', 'retention' => 'P2Y'],
					['condition' => 'age >= 1', 'retention' => 'P3Y'],
				],
			],
			'aggregationLevel' => 'dossier',
			'useRestriction' => ['type' => 'none', 'description' => ''],
			'temporalCoverage' => ['type' => 'period', 'startProperty' => 'from', 'endProperty' => 'to'],
		];

		$this->assertSame(
			[
				'effectiveRetention' => 'P2Y',
				'matchedRule' => 4,
				'expiresAt' => '2022-01-31T12:00:00+00:00',
				'aggregationLevel' => 'dossier',
				'useRestriction' => ['type' => 'none'],
				'temporalCoverage' => ['type' => 'period', 'start' => '2019', 'end' => '2019-03-04'],
			],
			$evaluator->evaluate($annotation, ['kind' => 'a', 'age' => 20, 'from' => '2019xyz', 'to' => '2019-03-04T00:00:00Z'], $created)
		);
		$this->assertSame([['Skipping malformed retention condition: Malformed retention condition: "kind = 1". Expected "<field> <op> <literal>".', ['rule_index' => 2]]], $warnings);

		$this->assertSame(
			['effectiveRetention' => 'P1M', 'matchedRule' => 3, 'expiresAt' => '2020-03-02T12:00:00+00:00', 'aggregationLevel' => 'dossier', 'useRestriction' => ['type' => 'none']],
			$evaluator->evaluate($annotation, ['kind' => 'b', 'from' => ''], $created)
		);

		$bare = ['retention' => ['default' => 'P1D', 'rules' => 'none'], 'aggregationLevel' => '', 'useRestriction' => ['type' => 'x', 'description' => 'why'], 'temporalCoverage' => ['type' => '', 'startProperty' => 'from']];
		$this->assertSame(
			['effectiveRetention' => 'P1D', 'matchedRule' => null, 'expiresAt' => '2020-02-01T12:00:00+00:00', 'useRestriction' => ['type' => 'x', 'description' => 'why']],
			$evaluator->evaluate($bare, ['from' => '2019'], $created)
		);
		$this->assertSame(
			['effectiveRetention' => 'P1D', 'matchedRule' => null, 'expiresAt' => '2020-02-01T12:00:00+00:00'],
			$evaluator->evaluate(['retention' => ['default' => 'P1D'], 'useRestriction' => ['type' => 3], 'temporalCoverage' => ['type' => 't', 'startProperty' => 'from']], ['from' => 'no date'], $created)
		);
	}//end testEvaluationPicksTheFirstMatchingRuleAndTheDeclaredFacts()

	/**
	 * Each way evaluation refuses an annotation.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedAnnotations(): array {
		return [
			'no block' => [['retention' => 'P1Y'], 'RetentionEvaluator: annotation has no `retention` block; validate at schema-save time.'],
			'no default' => [['retention' => ['default' => '']], 'RetentionEvaluator: annotation.retention.default is missing or not a string.'],
			'bad duration' => [['retention' => ['default' => 'ten years']], 'RetentionEvaluator: cannot parse duration "ten years": '],
		];
	}//end refusedAnnotations()

	/**
	 * @dataProvider refusedAnnotations
	 *
	 * @param array<string, mixed> $annotation The annotation.
	 * @param string               $message    The start of the refusal.
	 */
	public function testEvaluationRefuses(array $annotation, string $message): void {
		try {
			(new RetentionEvaluator())->evaluate($annotation, [], new DateTimeImmutable('2020-01-01T00:00:00+00:00'));
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertStringStartsWith($message, $e->getMessage());
		}
	}//end testEvaluationRefuses()

	/**
	 * Literals and how they compare.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool|string}>
	 */
	public static function conditions(): array {
		return [
			'true' => ['f == true', ['f' => true], true],
			'false' => ['f == false', ['f' => false], true],
			'null' => ['f == null', ['f' => null], true],
			'double quoted' => ['f == "a b"', ['f' => 'a b'], true],
			'single quoted' => ["f == 'x'", ['f' => 'x'], true],
			'mismatched quotes' => ['f == "x\'', ['f' => 'x'], 'Unrecognised literal in retention condition: ""x\'".'],
			'lone quote' => ['f == "', ['f' => 'x'], 'Unrecognised literal in retention condition: """.'],
			'int' => ['f < -3', ['f' => -4], true],
			'float' => ['f > 1.5', ['f' => 1.6], true],
			'bare word' => ['f == abc', ['f' => 'abc'], 'Unrecognised literal in retention condition: "abc".'],
			'bad float' => ['f == 1.', ['f' => 1], 'Unrecognised literal in retention condition: "1.".'],
			'missing field' => ['g == 1', ['f' => 1], false],
		];
	}//end conditions()

	/**
	 * @dataProvider conditions
	 *
	 * @param string               $condition The condition.
	 * @param array<string, mixed> $row       The row.
	 * @param bool|string          $expected  The outcome, or the refusal message.
	 */
	public function testConditionLiterals(string $condition, array $row, bool|string $expected): void {
		$evaluator = new RetentionConditionEvaluator();
		if (is_string($expected) === true) {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage($expected);
		}

		$this->assertSame($expected, $evaluator->evaluate($condition, $row));
	}//end testConditionLiterals()

	public function testDependantsAreTheRelationDerivedSchemasPointingHere(): void {
		$schema = static function (int $id, array $archive, array $properties = []): Schema {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setArchive($archive);
			$schema->setProperties($properties);
			return $schema;
		};

		$relation = ['enabled' => true, 'afleidingswijze' => 'hoofdzaak', 'sourceRelation' => 'parent', 'sourceRelationProperty' => 'closedAt'];
		$schemas = [
			$schema(1, $relation, ['parent' => ['$ref' => '#/components/schemas/zaak/']]),
			$schema(2, $relation, ['parent' => ['items' => ['$ref' => 'other']]]),
			$schema(3, array_merge($relation, ['sourceRelationProperty' => 'openedAt']), ['parent' => 'flat']),
			$schema(4, ['enabled' => false] + $relation),
			$schema(5, ['afleidingswijze' => 'afgehandeld'] + $relation),
			$schema(6, ['sourceRelation' => ''] + $relation),
			$schema(7, ['sourceRelationProperty' => 3] + $relation),
			$schema(8, $relation, ['parent' => ['$ref' => '  ']]),
			$schema(9, ['enabled' => 'true'] + $relation),
		];

		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('findAll')->willReturn($schemas);
		$calculator = new ArchiveActionDateCalculator(
			objectMapper: $this->createMock(MagicMapperInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$index = new DependantRetentionIndex(schemaMapper: $mapper, calculator: $calculator);

		$source = new Schema();
		$source->setId(20);
		$source->setSlug('zaak');
		$other = new Schema();
		$other->setUuid('other');

		$shape = static fn (array $dependants): array => array_map(
			static fn (array $d): array => [$d['schema']->getId(), $d['relation'], $d['property']],
			$dependants
		);
		$this->assertSame([[1, 'parent', 'closedAt'], [3, 'parent', 'openedAt'], [8, 'parent', 'closedAt']], $shape($index->dependantsOf($source)));
		$this->assertSame([[2, 'parent', 'closedAt'], [3, 'parent', 'openedAt'], [8, 'parent', 'closedAt']], $shape($index->dependantsOf($other)));
		$this->assertSame(['closedAt', 'openedAt'], $index->watchedPropertiesOf($source));
	}//end testDependantsAreTheRelationDerivedSchemasPointingHere()
}//end class
