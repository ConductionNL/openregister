<?php

/**
 * Golden test for the erasure preview, run and store.
 *
 * Pins, on the code as it stood before the A15 maintainability refactor, every
 * allowed and every refused outcome of the erasure path: the three-way
 * classification and its grounds, the order in which the per-object refusals
 * are asked before anything is destroyed, the stale-preview refusal that runs
 * before any write, and the store's reach and lifecycle refusals. The only
 * code a refactor may touch here is the two assembling helpers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Erasure
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr\Erasure;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ErasurePreviewMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Erasure\CoSubjectProbe;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureBucket;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureDestroyer;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureDestructionSteps;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureDestroyGuard;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureCounters;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureProtectionResolver;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureSchemaResolver;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureScopeCounter;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewUnknownException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;
use OCA\OpenRegister\Service\Gdpr\Erasure\SubjectPartyCounter;
use OCA\OpenRegister\Service\Gdpr\GdprCallerMembership;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Service\Gdpr\SubjectMatch;

final class ErasureGoldenTest extends TestCase {
	private const SUBJECT = 'jan@example.org';

	/** @var array<int, string> Ordered log of collaborator calls. */
	private array $calls = [];

	/** @var array<string, string> Schema id handed to the scope preview, per uuid. */
	private array $scopeSchemas = [];

	private static function label(?Schema $schema): string {
		if ($schema === null) {
			return 'null';
		}

		return (string)$schema->getId();
	}//end label()

	private static function counts(int $objects, int $files, int $timeline, int $parties): array {
		return [
			ErasureBucket::OBJECTS => $objects,
			ErasureBucket::FILES => $files,
			ErasureBucket::TIMELINE => $timeline,
			ErasureBucket::PARTIES => $parties,
		];
	}//end counts()

	protected function setUp(): void {
		$this->calls = [];
		$this->scopeSchemas = [];
	}//end setUp()

	private function object(string $uuid, string $schema = '2', array $payload = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema($schema);
		$object->setObject(['email' => self::SUBJECT]);
		if ($payload !== []) {
			$object->setObject($payload);
		}

		return $object;
	}//end object()

	private function hit(ObjectEntity $object): array {
		return [
			'object' => $object,
			'gdprEntities' => [
				['type' => 'email', 'value' => self::SUBJECT],
				['type' => 'phone', 'value' => '0612345678'],
				['type' => '', 'value' => ''],
			],
		];
	}//end hit()

	/**
	 * A db whose co-subject probe answers per object uuid.
	 *
	 * @param array<string, array|null> $rowsByUuid Rows per uuid; null throws.
	 *
	 * @return IDBConnection The staged connection.
	 */
	private function db(array $rowsByUuid): IDBConnection {
		$current = null;
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$current): string {
				if (is_string($value) === true) {
					$current = $value;
				}

				return '?';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$current, $rowsByUuid): IResult {
				$this->calls[] = 'index:' . $current;
				if (array_key_exists((string)$current, $rowsByUuid) === true && $rowsByUuid[$current] === null) {
					throw new RuntimeException('index unreadable');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(($rowsByUuid[$current] ?? []));
				$result->method('closeCursor')->willReturn(true);

				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()

	/**
	 * The world the preview golden runs over.
	 *
	 * @return array<int, array> Discovery hits.
	 */
	private function world(): array {
		$parties = [
			'rollen' => [
				['naam' => 'Jan', 'email' => 'JAN@example.org'],
				['naam' => 'Piet', 'email' => 'piet@example.org'],
				['nested' => ['phone' => '0612345678']],
				'not-a-party',
			],
			'zaak' => ['betrokkenen' => [['contact' => ['email' => self::SUBJECT]]]],
			'flag' => true,
		];

		return [
			$this->hit($this->object('a-archival')),
			$this->hit($this->object('b-immutable')),
			$this->hit($this->object('c-guardthrows')),
			$this->hit($this->object('d-retthrows')),
			$this->hit($this->object('e-erasable', '2', $parties)),
			$this->hit($this->object('f-shared', '2', $parties)),
			$this->hit($this->object('g-ownvalue')),
			$this->hit($this->object('h-noschema', '')),
			$this->hit($this->object('i-scopethrows')),
			$this->hit($this->object('j-indexthrows')),
			$this->hit($this->object('k-badschema', '99')),
		];
	}//end world()

	/**
	 * Assemble the preview service. The ONLY part a refactor may edit.
	 *
	 * @param array<int, array> $hits Discovery hits.
	 *
	 * @return ErasurePreviewService The service.
	 */
	private function previewService(array $hits): ErasurePreviewService {
		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('findSubjectObjects')->willReturn($hits);

		$guard = $this->createMock(ArchivalRetentionGuard::class);
		$guard->method('erasureRefusal')->willReturnCallback(
			function (ObjectEntity $object): ?array {
				$this->calls[] = 'guard:' . $object->getUuid();
				if ($object->getUuid() === 'a-archival') {
					return [
						'ground' => ArchivalRetentionGuard::GROUND_ARCHIVAL,
						'message' => 'Held under the Archiefwet.',
						'basis' => 'Selectielijst 1.1',
						'action' => 'Explain.',
					];
				}

				if ($object->getUuid() === 'c-guardthrows') {
					throw new RuntimeException('guard down');
				}

				return null;
			}
		);

		$retention = $this->createMock(RetentionService::class);
		$retention->method('validateNotImmutable')->willReturnCallback(
			function (ObjectEntity $object): ?string {
				$this->calls[] = 'retention:' . $object->getUuid();
				if ($object->getUuid() === 'b-immutable') {
					return 'gearchiveerd';
				}

				if ($object->getUuid() === 'd-retthrows') {
					throw new RuntimeException('retention down');
				}

				return null;
			}
		);

		$scopeService = $this->createMock(DestructionScopeService::class);
		$scopeService->method('preview')->willReturnCallback(
			function (ObjectEntity $object, ?Schema $schema): array {
				$this->calls[] = 'scope:' . $object->getUuid();
				$this->scopeSchemas[(string)$object->getUuid()] = self::label($schema);
				if ($object->getUuid() === 'i-scopethrows') {
					throw new RuntimeException('scope down');
				}

				return ['counts' => ['files' => 3, 'timeline' => 4, 'notes' => 9], 'total' => 16];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				if ((int)$id !== 2) {
					throw new DoesNotExistException('no schema');
				}

				$schema = new Schema();
				$schema->setId(2);

				return $schema;
			}
		);

		$db = $this->db(
			[
				'f-shared' => [
					['type' => 'email', 'value' => self::SUBJECT],
					['type' => 'email', 'value' => 'piet@example.org'],
					['type' => 'phone', 'value' => ''],
				],
				'g-ownvalue' => [['type' => 'email', 'value' => '  JAN@EXAMPLE.ORG ']],
				'j-indexthrows' => null,
			]
		);

		$parties = new SubjectPartyCounter();

		return new ErasurePreviewService($subjects, new ErasureProtectionResolver($guard, $retention, new NullLogger()), new ErasureCounters(new CoSubjectProbe($db, $parties, new NullLogger()), new ErasureScopeCounter($scopeService, new ErasureSchemaResolver($schemaMapper), new NullLogger()), $parties));
	}//end previewService()

	public function testWholeObjectPreviewClassifiesEveryGroundExactly(): void {
		$preview = $this->previewService($this->world())->preview(new \OCA\OpenRegister\Service\Gdpr\SubjectQuery('  ' . self::SUBJECT . ' ', 'email'), 'whole-object');

		self::assertSame(self::SUBJECT, $preview['subject']);
		self::assertSame('email', $preview['type']);
		self::assertSame('whole-object', $preview['eraseMode']);
		self::assertSame(11, $preview['matchedCount']);
		self::assertSame(
			['subject', 'type', 'eraseMode', 'generatedAt', 'matchedCount', 'counts', 'items', 'protected', 'digest'],
			array_keys($preview)
		);

		$byUuid = [];
		foreach ($preview['items'] as $item) {
			$byUuid[$item['uuid']] = $item;
		}

		$expect = [
			'a-archival' => [ErasureBucket::PROTECTED, ArchivalRetentionGuard::GROUND_ARCHIVAL],
			'b-immutable' => [ErasureBucket::PROTECTED, ErasureBucket::GROUND_IMMUTABLE],
			'c-guardthrows' => [ErasureBucket::PROTECTED, ErasureBucket::GROUND_UNRESOLVABLE],
			'd-retthrows' => [ErasureBucket::PROTECTED, ErasureBucket::GROUND_UNRESOLVABLE],
			'e-erasable' => [ErasureBucket::ERASABLE, null],
			'f-shared' => [ErasureBucket::PSEUDONYMISED, ErasureBucket::GROUND_SHARED_RECORD],
			'g-ownvalue' => [ErasureBucket::ERASABLE, null],
			'h-noschema' => [ErasureBucket::ERASABLE, null],
			'i-scopethrows' => [ErasureBucket::ERASABLE, null],
			'j-indexthrows' => [ErasureBucket::PSEUDONYMISED, ErasureBucket::GROUND_SHARED_RECORD],
			'k-badschema' => [ErasureBucket::ERASABLE, null],
		];
		foreach ($expect as $uuid => [$bucket, $ground]) {
			self::assertSame($bucket, $byUuid[$uuid]['bucket'], $uuid);
			self::assertSame($ground, ($byUuid[$uuid]['ground'] ?? null), $uuid);
			self::assertSame(['email', 'phone'], $byUuid[$uuid]['matchedOn'], $uuid);
		}

		self::assertSame(
			['uuid', 'register', 'schema', 'matchedOn', 'ground', 'message', 'basis', 'action', 'bucket', 'counts'],
			array_keys($byUuid['a-archival'])
		);
		self::assertSame('Selectielijst 1.1', $byUuid['a-archival']['basis']);
		self::assertSame([ErasureBucket::OBJECTS => 1], $byUuid['a-archival']['counts']);
		self::assertSame('Archiefwet: status gearchiveerd.', $byUuid['b-immutable']['basis']);
		self::assertSame(
			'We could not establish whether this record is held, so we left it alone.',
			$byUuid['c-guardthrows']['message']
		);
		self::assertSame(
			[ErasureBucket::OBJECTS => 1, ErasureBucket::FILES => 3, ErasureBucket::TIMELINE => 4, ErasureBucket::PARTIES => 3],
			$byUuid['e-erasable']['counts']
		);
		self::assertSame(['uuid', 'register', 'schema', 'matchedOn', 'bucket', 'counts'], array_keys($byUuid['e-erasable']));
		self::assertSame(
			['uuid', 'register', 'schema', 'matchedOn', 'bucket', 'ground', 'message', 'sharedWith', 'counts'],
			array_keys($byUuid['f-shared'])
		);
		self::assertSame(['email'], $byUuid['f-shared']['sharedWith']);
		self::assertSame([ErasureBucket::OBJECTS => 1, ErasureBucket::PARTIES => 3], $byUuid['f-shared']['counts']);
		self::assertSame(['email', 'phone'], $byUuid['j-indexthrows']['sharedWith']);
		self::assertSame(
			[ErasureBucket::OBJECTS => 1, ErasureBucket::FILES => 0, ErasureBucket::TIMELINE => 0, ErasureBucket::PARTIES => 0],
			$byUuid['i-scopethrows']['counts']
		);
		self::assertSame(
			['e-erasable' => '2', 'g-ownvalue' => '2', 'h-noschema' => 'null', 'i-scopethrows' => '2', 'k-badschema' => 'null'],
			$this->scopeSchemas
		);

		self::assertSame(
			['a-archival', 'b-immutable', 'c-guardthrows', 'd-retthrows'],
			array_column($preview['protected'], 'uuid')
		);
		self::assertSame(
			[
				ErasureBucket::ERASABLE => self::counts(5, 12, 16, 3),
				ErasureBucket::PSEUDONYMISED => self::counts(2, 0, 0, 3),
				ErasureBucket::PROTECTED => self::counts(4, 0, 0, 0),
			],
			$preview['counts']
		);

		// The protected grounds are asked in order, and a refusal stops the
		// next question: the retention service is never asked about a record
		// the archival guard already holds, nor one whose guard threw.
		self::assertSame(
			[
				'guard:a-archival',
				'guard:b-immutable', 'retention:b-immutable',
				'guard:c-guardthrows',
				'guard:d-retthrows', 'retention:d-retthrows',
				'guard:e-erasable', 'retention:e-erasable', 'index:e-erasable', 'scope:e-erasable',
				'guard:f-shared', 'retention:f-shared', 'index:f-shared',
				'guard:g-ownvalue', 'retention:g-ownvalue', 'index:g-ownvalue', 'scope:g-ownvalue',
				'guard:h-noschema', 'retention:h-noschema', 'index:h-noschema', 'scope:h-noschema',
				'guard:i-scopethrows', 'retention:i-scopethrows', 'index:i-scopethrows', 'scope:i-scopethrows',
				'guard:j-indexthrows', 'retention:j-indexthrows', 'index:j-indexthrows',
				'guard:k-badschema', 'retention:k-badschema', 'index:k-badschema', 'scope:k-badschema',
			],
			$this->calls
		);

		unset($preview['generatedAt']);
		self::assertSame('2322e9b4550bf056ddc0279c4310b870bf6c494b9245108c8840fe92008836ee', hash('sha256', (string)json_encode($preview)));
		self::assertSame('b2efef61b7688101d31c1d2a5bdf567a440cfa5718d67632e921aa56fda6b722', $preview['digest']);
	}//end testWholeObjectPreviewClassifiesEveryGroundExactly()

	public function testPseudonymiseAndUnknownModesNeverDestroy(): void {
		foreach (['pseudonymise', 'garbage'] as $mode) {
			$this->calls = [];
			$preview = $this->previewService($this->world())->preview(new \OCA\OpenRegister\Service\Gdpr\SubjectQuery(self::SUBJECT), $mode);

			self::assertSame('pseudonymise', $preview['eraseMode']);
			self::assertSame(0, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
			self::assertSame(7, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::OBJECTS]);
			self::assertSame(6, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::PARTIES]);
			self::assertSame(4, $preview['counts'][ErasureBucket::PROTECTED][ErasureBucket::OBJECTS]);
			$touched = array_filter(
				$this->calls,
				static fn (string $c): bool => str_starts_with($c, 'scope:') || str_starts_with($c, 'index:')
			);
			self::assertSame([], array_values($touched));

			unset($preview['generatedAt']);
			self::assertSame('bc793ef9704731a3d355847c4342712adfc4f0ea77ccd0eca5b5ebb4f9f03c31', hash('sha256', (string)json_encode($preview)));
		}
	}//end testPseudonymiseAndUnknownModesNeverDestroy()

	public function testDigestIgnoresOrderAndTimeButNotOutcome(): void {
		$service = $this->previewService([]);
		$items = [
			['uuid' => 'b', 'bucket' => 'erasable', 'counts' => ['objects' => 1], 'extra' => 'ignored'],
			['uuid' => 'a', 'bucket' => 'protected', 'ground' => 'X'],
		];
		$base = ['subject' => 's', 'type' => null, 'eraseMode' => 'whole-object', 'items' => $items, 'generatedAt' => 'one'];
		$digest = $service->digest($base);

		self::assertSame('182a56c814fee7586b88768821ff53a87c95e0f42d4149132ed78beb1f399bbd', $digest);
		self::assertSame($digest, $service->digest(array_merge($base, ['items' => array_reverse($items), 'generatedAt' => 'two'])));
		self::assertNotSame($digest, $service->digest(array_merge($base, ['eraseMode' => 'pseudonymise'])));
		self::assertSame('0987fbfe16d7ea4bfd053aa03428afc5d419cd6b4853096c5c3b188f88476124', $service->digest([]));
	}//end testDigestIgnoresOrderAndTimeButNotOutcome()

	private function record(string $digest = 'abc'): ErasurePreview {
		$record = new ErasurePreview();
		$record->setUuid('preview-1');
		$record->setSubject(self::SUBJECT);
		$record->setSubjectType('email');
		$record->setEraseMode(DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);
		$record->setDigest($digest);
		$record->setRequestId('DSR-2026-14');
		$record->setStatus(ErasurePreview::STATUS_APPROVED);
		$record->setApprovedBy('anja');

		return $record;
	}//end record()

	private function item(string $uuid, string $bucket, ?string $ground = null): array {
		return ['uuid' => $uuid, 'bucket' => $bucket, 'ground' => $ground, 'counts' => [ErasureBucket::OBJECTS => 1]];
	}//end item()

	/**
	 * Assemble the runner. The ONLY part a refactor may edit.
	 *
	 * @param array<int, array> $items    Fresh preview items.
	 * @param string            $digest   Fresh digest.
	 * @param array|null        $consumed Receives the consumed outcome.
	 *
	 * @return ErasureRunner The runner.
	 */
	private function runner(array $items, string $digest, ?array &$consumed): ErasureRunner {
		$previewService = $this->createMock(ErasurePreviewService::class);
		$previewService->method('preview')->willReturnCallback(
			function (\OCA\OpenRegister\Service\Gdpr\SubjectQuery $subject, string $eraseMode) use ($items, $digest): array {
				$this->calls[] = 'preview:' . $subject->subjectId . ':' . $subject->type . ':' . $eraseMode;

				return ['items' => $items, 'digest' => $digest];
			}
		);

		$store = $this->createMock(ErasurePreviewStore::class);
		$store->method('consume')->willReturnCallback(
			function (ErasurePreview $preview, array $outcome) use (&$consumed): ErasurePreview {
				$this->calls[] = 'consume';
				$consumed = $outcome;

				return $preview;
			}
		);

		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('findSubjectObjects')->willReturnCallback(
			function (): array {
				$this->calls[] = 'discover';
				$hits = [];
				foreach (['p1', 's1', 's2', 'r1', 'r2', 'x1', 'x2', 'x3', 'x4', 'ok1', 'ns1'] as $uuid) {
					$hits[] = $this->hit($this->object($uuid, (['ns1' => ''][$uuid] ?? '2')));
				}

				return $hits;
			}
		);
		$subjects->method('pseudonymiseObject')->willReturnCallback(
			function (SubjectMatch $match, string $subjectId): ?array {
				$this->calls[] = 'scrub:' . $match->object->getUuid() . ':' . $subjectId . ':' . count($match->matched);

				if ($match->object->getUuid() === 's2') {
					return null;
				}

				return ['uuid' => $match->object->getUuid()];
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->calls[] = 'delete:' . $object->getUuid();
				if ($object->getUuid() === 'x4') {
					throw new RuntimeException('delete failed');
				}

				return $object;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);

				return $schema;
			}
		);

		$destroyRight = $this->createMock(DestroyRightService::class);
		$destroyRight->method('refusalFor')->willReturnCallback(
			function (ObjectEntity $object, ?Schema $schema): ?DestructionRefusedException {
				$this->calls[] = 'right:' . $object->getUuid() . ':' . self::label($schema);

				if ($object->getUuid() !== 'r1') {
					return null;
				}

				return new DestructionRefusedException(rule: 'destroy-right-missing', reason: 'No right.');
			}
		);

		$clock = $this->createMock(RetentionClockService::class);
		$clock->method('refusalFor')->willReturnCallback(
			function (ObjectEntity $object): ?DestructionRefusedException {
				$this->calls[] = 'clock:' . $object->getUuid();

				if ($object->getUuid() !== 'r2') {
					return null;
				}

				return new DestructionRefusedException(rule: 'legal-hold', reason: 'Held.', statusCode: 423);
			}
		);

		$scopeService = $this->createMock(DestructionScopeService::class);
		$scopeService->method('destroy')->willReturnCallback(
			function (ObjectEntity $object, ?Schema $schema): array {
				$this->calls[] = 'scope:' . $object->getUuid() . ':' . self::label($schema);
				if ($object->getUuid() === 'x1') {
					throw new DestructionRefusedException(rule: 'scope-refused', reason: 'Scope refused.');
				}

				if ($object->getUuid() === 'x2') {
					throw new RuntimeException('scope broke');
				}

				return ['destroyed' => ['files' => 2], 'total' => 2];
			}
		);

		$recorder = $this->createMock(DestructionRecorder::class);
		$recorder->method('record')->willReturnCallback(
			function (ObjectEntity $object, array $scope, string $rule, array $context): array {
				$this->calls[] = 'record:' . $object->getUuid() . ':' . $rule . ':' . json_encode($context);
				if ($object->getUuid() === 'x3') {
					throw new RuntimeException('record failed');
				}

				return $scope;
			}
		);

		$destroyer = new ErasureDestroyer(new ErasureDestructionSteps(new ErasureSchemaResolver($schemaMapper), $scopeService, $recorder, $objectMapper), new ErasureDestroyGuard($destroyRight, $clock), new NullLogger());

		return new ErasureRunner($previewService, $store, $subjects, $destroyer);
	}//end runner()

	public function testRunActsOnEveryItemAndRefusesBeforeDestroying(): void {
		$consumed = null;
		$items = [
			$this->item('p1', ErasureBucket::PROTECTED, 'ARCHIVAL_RETENTION_OBLIGATION'),
			$this->item('gone', ErasureBucket::ERASABLE),
			$this->item('s1', ErasureBucket::PSEUDONYMISED, ErasureBucket::GROUND_SHARED_RECORD),
			$this->item('s2', ErasureBucket::PSEUDONYMISED),
			$this->item('r1', ErasureBucket::ERASABLE),
			$this->item('r2', ErasureBucket::ERASABLE),
			$this->item('x1', ErasureBucket::ERASABLE),
			$this->item('x2', ErasureBucket::ERASABLE),
			$this->item('x3', ErasureBucket::ERASABLE),
			$this->item('x4', ErasureBucket::ERASABLE),
			$this->item('ok1', ErasureBucket::ERASABLE),
			$this->item('ns1', 'unknown-bucket'),
		];

		$outcome = $this->runner($items, 'abc', $consumed)->run($this->record());

		self::assertSame($outcome, $consumed);
		unset($outcome['ranAt']);
		$ctx = '{"dataSubjectRequest":"DSR-2026-14","erasurePreview":"preview-1","dataSubject":"jan@example.org","approvedBy":"anja","windowWaived":true}';
		self::assertSame(
			[
				'preview:jan@example.org:email:whole-object',
				'discover',
				'scrub:s1:jan@example.org:3',
				'scrub:s2:jan@example.org:3',
				'right:r1:2',
				'right:r2:2', 'clock:r2',
				'right:x1:2', 'clock:x1', 'scope:x1:2',
				'right:x2:2', 'clock:x2', 'scope:x2:2',
				'right:x3:2', 'clock:x3', 'scope:x3:2', 'record:x3:gdpr-erasure-approved:' . $ctx,
				'right:x4:2', 'clock:x4', 'scope:x4:2', 'record:x4:gdpr-erasure-approved:' . $ctx, 'delete:x4',
				'right:ok1:2', 'clock:ok1', 'scope:ok1:2', 'record:ok1:gdpr-erasure-approved:' . $ctx, 'delete:ok1',
				'right:ns1:null', 'clock:ns1', 'scope:ns1:null', 'record:ns1:gdpr-erasure-approved:' . $ctx, 'delete:ns1',
				'consume',
			],
			$this->calls
		);
		self::assertSame(
			[
				'preview' => 'preview-1',
				'request' => 'DSR-2026-14',
				'subject' => self::SUBJECT,
				'eraseMode' => 'whole-object',
				'destroyed' => [
					['uuid' => 'ok1', 'scope' => ['files' => 2], 'total' => 2],
					['uuid' => 'ns1', 'scope' => ['files' => 2], 'total' => 2],
				],
				'pseudonymised' => [['uuid' => 's1', 'ground' => ErasureBucket::GROUND_SHARED_RECORD]],
				'withheld' => [$items[0]],
				'refused' => [
					['uuid' => 'r1', 'rule' => 'destroy-right-missing', 'message' => 'No right.'],
					['uuid' => 'r2', 'rule' => 'legal-hold', 'message' => 'Held.'],
					['uuid' => 'x1', 'rule' => 'scope-refused', 'message' => 'Scope refused.'],
				],
				'failed' => [
					['uuid' => 'gone', 'error' => 'The object could not be loaded at run time.'],
					['uuid' => 's2', 'error' => 'The scrub could not be persisted.'],
					['uuid' => 'x2', 'error' => 'scope broke'],
					['uuid' => 'x3', 'error' => 'record failed'],
					['uuid' => 'x4', 'error' => 'delete failed'],
				],
				'complete' => false,
				'destroyedCount' => 2,
				'pseudonymisedCount' => 1,
				'withheldCount' => 1,
				'refusedCount' => 3,
				'failedCount' => 5,
			],
			$outcome
		);
	}//end testRunActsOnEveryItemAndRefusesBeforeDestroying()

	public function testACleanRunIsComplete(): void {
		$consumed = null;
		$outcome = $this->runner([$this->item('ok1', ErasureBucket::ERASABLE), $this->item('s1', ErasureBucket::PSEUDONYMISED)], 'abc', $consumed)
			->run($this->record());

		self::assertTrue($outcome['complete']);
		self::assertSame(1, $outcome['destroyedCount']);
		self::assertSame(1, $outcome['pseudonymisedCount']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $outcome['ranAt']);
	}//end testACleanRunIsComplete()

	public function testAStalePreviewRefusesBeforeAnyWrite(): void {
		$consumed = null;
		$runner = $this->runner([$this->item('ok1', ErasureBucket::ERASABLE)], 'moved', $consumed);

		try {
			$runner->run($this->record('abc'));
			self::fail('A stale preview must refuse.');
		} catch (ErasureRefusedException $e) {
			self::assertSame('erasure-preview-stale', $e->getRule());
			self::assertSame(409, $e->getStatusCode());
			self::assertSame(409, $e->getCode());
			self::assertSame(
				[
					'error' => 'ERASURE_REFUSED',
					'rule' => 'erasure-preview-stale',
					'message' => 'The records this subject appears on have changed since the preview was approved, '
						. 'so nothing was erased. Take a new preview and approve that one.',
					'approvedDigest' => 'abc',
					'currentDigest' => 'moved',
				],
				$e->toResponseBody()
			);
		}

		self::assertSame(['preview:jan@example.org:email:whole-object'], $this->calls);
		self::assertNull($consumed);
	}//end testAStalePreviewRefusesBeforeAnyWrite()

	public function testTheRefusalExceptionKeepsItsPublicShape(): void {
		$previous = new RuntimeException('cause');
		$e = new ErasureRefusedException(rule: 'r', reason: 'Because.', context: ['k' => 'v', 'rule' => 'override'], previous: $previous);

		self::assertSame('r', $e->getRule());
		self::assertSame(409, $e->getStatusCode());
		self::assertSame(409, $e->getCode());
		self::assertSame('Because.', $e->getMessage());
		self::assertSame($previous, $e->getPrevious());
		self::assertSame(['error' => 'ERASURE_REFUSED', 'rule' => 'override', 'message' => 'Because.', 'k' => 'v'], $e->toResponseBody());

		$e = new ErasurePreviewUnknownException();
		self::assertInstanceOf(ErasureRefusedException::class, $e);
		self::assertSame(404, $e->getStatusCode());
		self::assertSame(404, $e->getCode());
		self::assertNull($e->getPrevious());
		self::assertSame(
			['error' => 'ERASURE_REFUSED', 'rule' => 'erasure-preview-unknown', 'message' => 'No erasure preview with that identifier exists.'],
			$e->toResponseBody()
		);

		$e = new ErasurePreviewUnknownException(previous: $previous);
		self::assertSame($previous, $e->getPrevious());
	}//end testTheRefusalExceptionKeepsItsPublicShape()

	private function store(?ErasurePreview $row, ?string $uid, bool $admin = false): ErasurePreviewStore {
		$mapper = $this->createMock(ErasurePreviewMapper::class);
		if ($row === null) {
			$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no such row'));
		} else {
			$mapper->method('findByUuid')->willReturn($row);
		}

		$mapper->method('save')->willReturnCallback(
			function (ErasurePreview $preview): ErasurePreview {
				$this->calls[] = 'save:' . $preview->getStatus();

				return $preview;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $id) use ($admin): bool {
				$this->calls[] = 'isAdmin:' . $id;

				return $admin;
			}
		);

		return new ErasurePreviewStore($mapper, new GdprCallerMembership($session, $groups, new NullLogger()));
	}//end store()

	private function row(string $status, string $owner = 'handler', ?DateTime $consumedAt = null): ErasurePreview {
		$row = new ErasurePreview();
		$row->setUuid('preview-1');
		$row->setStatus($status);
		$row->setCreatedBy($owner);
		$row->setConsumedAt($consumedAt);

		return $row;
	}//end row()

	private function refusal(callable $act): ErasureRefusedException {
		try {
			$act();
		} catch (ErasureRefusedException $e) {
			return $e;
		}

		self::fail('Expected a refusal.');
	}//end refusal()

	public function testStoreReachRefusalsAreIndistinguishable(): void {
		$unknown = ['error' => 'ERASURE_REFUSED', 'rule' => 'erasure-preview-unknown', 'message' => 'No erasure preview with that identifier exists.'];

		$missing = $this->refusal(fn () => $this->store(null, 'handler')->load('x'));
		self::assertSame($unknown, $missing->toResponseBody());
		self::assertSame(404, $missing->getStatusCode());
		self::assertInstanceOf(DoesNotExistException::class, $missing->getPrevious());

		foreach ([[null, false], ['stranger', false]] as [$uid, $admin]) {
			$e = $this->refusal(fn () => $this->store($this->row(ErasurePreview::STATUS_PENDING), $uid, $admin)->load('preview-1'));
			self::assertSame($unknown, $e->toResponseBody());
			self::assertSame(404, $e->getStatusCode());
			self::assertNull($e->getPrevious());
		}

		$this->calls = [];
		self::assertSame('preview-1', $this->store($this->row(ErasurePreview::STATUS_PENDING), 'boss', true)->load('preview-1')->getUuid());
		self::assertSame('preview-1', $this->store($this->row(ErasurePreview::STATUS_PENDING), 'handler')->load('preview-1')->getUuid());
		self::assertSame(['isAdmin:boss', 'isAdmin:handler'], $this->calls);
	}//end testStoreReachRefusalsAreIndistinguishable()

	public function testStoreLifecycleRefusalsAndTheirOrder(): void {
		$at = new DateTime('2026-10-01T10:00:00+00:00');
		$spent = [
			'error' => 'ERASURE_REFUSED',
			'rule' => 'erasure-already-run',
			'message' => 'This preview has already been run. Take a new preview before erasing again.',
			'consumedAt' => '2026-10-01T10:00:00+00:00',
		];

		$this->calls = [];
		$e = $this->refusal(fn () => $this->store($this->row(ErasurePreview::STATUS_CONSUMED, 'handler', $at), 'handler')->approve('preview-1'));
		self::assertSame($spent, $e->toResponseBody());
		self::assertSame(409, $e->getStatusCode());
		self::assertSame(['isAdmin:handler'], $this->calls);

		// Consumed wins over not-approved, and over a consumed stamp missing.
		$e = $this->refusal(fn () => $this->store($this->row(ErasurePreview::STATUS_CONSUMED, 'handler', $at), 'handler')->requireRunnable('preview-1'));
		self::assertSame($spent, $e->toResponseBody());
		$e = $this->refusal(fn () => $this->store($this->row(ErasurePreview::STATUS_CONSUMED), 'handler')->requireRunnable('preview-1'));
		self::assertSame(array_merge($spent, ['consumedAt' => null]), $e->toResponseBody());

		foreach ([ErasurePreview::STATUS_PENDING, 'other'] as $status) {
			$e = $this->refusal(fn () => $this->store($this->row($status), 'handler')->requireRunnable('preview-1'));
			self::assertSame(
				[
					'error' => 'ERASURE_REFUSED',
					'rule' => 'erasure-not-approved',
					'message' => 'This erasure preview has not been approved, so nothing was erased. '
						. 'Approve the preview first, and check its protected records before you do.',
					'status' => $status,
				],
				$e->toResponseBody()
			);
			self::assertSame(409, $e->getStatusCode());
		}

		// Approved but stamped consumed: isRunnable() refuses.
		$e = $this->refusal(fn () => $this->store($this->row(ErasurePreview::STATUS_APPROVED, 'handler', $at), 'handler')->requireRunnable('preview-1'));
		self::assertSame('erasure-not-approved', $e->getRule());

		$runnable = $this->store($this->row(ErasurePreview::STATUS_APPROVED), 'handler')->requireRunnable('preview-1');
		self::assertSame(ErasurePreview::STATUS_APPROVED, $runnable->getStatus());

		$this->calls = [];
		$approved = $this->store($this->row(ErasurePreview::STATUS_PENDING), 'anja', true)->approve('preview-1');
		self::assertSame(ErasurePreview::STATUS_APPROVED, $approved->getStatus());
		self::assertSame('anja', $approved->getApprovedBy());
		self::assertNotNull($approved->getApprovedAt());
		self::assertSame(['isAdmin:anja', 'save:approved'], $this->calls);
	}//end testStoreLifecycleRefusalsAndTheirOrder()

	public function testPartyCounterCountsPartyListsAtAnyDepth(): void {
		$counter = new SubjectPartyCounter();
		$needles = $counter->needles(' Jan@Example.org ', [['value' => ' 0612345678 '], ['value' => ''], ['value' => 'jan@example.org']]);
		self::assertSame(['jan@example.org', '0612345678'], $needles);
		self::assertSame([], $counter->needles('  ', []));

		$payload = [
			'roles' => [['email' => 'JAN@EXAMPLE.ORG'], ['email' => 'x@y.z'], ['deep' => ['deeper' => [612345678]]], 'scalar', ['n' => null]],
			'wrapper' => ['parties' => [['tel' => '0612345678']], 'rollen' => 'not-a-list'],
			'other' => [['email' => 'jan@example.org']],
			'email' => 'jan@example.org',
		];
		self::assertSame(2, $counter->count($payload, $needles));
		self::assertSame(0, $counter->count($payload, []));
		self::assertSame(1, $counter->count(['betrokkenen' => [['a' => true, 'b' => '1']]], ['1']));
	}//end testPartyCounterCountsPartyListsAtAnyDepth()
}//end class
