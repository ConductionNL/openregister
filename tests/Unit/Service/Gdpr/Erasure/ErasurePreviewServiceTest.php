<?php

/**
 * Unit tests for the erasure preview.
 *
 * Covers the two scenarios the spec delta names — the gemeente that can answer
 * the subject honestly, and the unresolvable hold that is protected rather than
 * erased — plus the three properties the counts rest on: a preview writes
 * nothing, a record that also holds another person's identifier is scrubbed
 * rather than destroyed, and the digest is stable across two readings of an
 * unchanged world.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Erasure
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Gdpr\Erasure;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureBucket;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\SubjectPartyCounter;
use OCA\OpenRegister\Service\RetentionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ErasurePreviewServiceTest extends TestCase {
	private const SUBJECT = 'jan@example.org';

	/**
	 * One object carrying the subject's email, plus whatever payload is given.
	 *
	 * @param string       $uuid    The object uuid.
	 * @param array<mixed> $payload The object payload.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, array $payload = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($payload);

		return $object;
	}//end object()

	/**
	 * The discovery hit shape findSubjectObjects() hands back.
	 *
	 * @param ObjectEntity $object The object the subject was found on.
	 *
	 * @return array<string, mixed> One discovery hit.
	 */
	private function hit(ObjectEntity $object): array {
		return [
			'object' => $object,
			'gdprEntities' => [
				[
					'type' => 'email',
					'value' => self::SUBJECT,
					'category' => 'contact',
					'detectedAt' => '2026-09-01T00:00:00+00:00',
				],
			],
		];
	}//end hit()

	/**
	 * A db whose co-subject probe returns exactly the staged rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows the probe returns.
	 *
	 * @return IDBConnection The staged connection.
	 */
	private function db(array $rows): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()

	/**
	 * Assemble the service over staged collaborators.
	 *
	 * @param array<int, array>    $hits      What discovery returns.
	 * @param callable|null        $refusal   erasureRefusal() stand-in.
	 * @param callable|null        $immutable validateNotImmutable() stand-in.
	 * @param array<int, array>    $coRows    Rows the co-subject probe returns.
	 * @param array<string, int>   $scope     Destruction scope counts.
	 *
	 * @return ErasurePreviewService The assembled service.
	 */
	private function service(
		array $hits,
		?callable $refusal = null,
		?callable $immutable = null,
		array $coRows = [],
		array $scope = ['files' => 0, 'timeline' => 0],
	): ErasurePreviewService {
		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('findSubjectObjects')->willReturn($hits);

		$guard = $this->createMock(ArchivalRetentionGuard::class);
		$guard->method('erasureRefusal')->willReturnCallback(
			$refusal ?? static fn (ObjectEntity $object): ?array => null
		);

		$retention = $this->createMock(RetentionService::class);
		$retention->method('validateNotImmutable')->willReturnCallback(
			$immutable ?? static fn (ObjectEntity $object): ?string => null
		);

		$scopeService = $this->createMock(DestructionScopeService::class);
		$scopeService->method('preview')->willReturn(['counts' => $scope, 'total' => array_sum($scope)]);

		return new ErasurePreviewService(
			$subjects,
			$guard,
			$retention,
			$scopeService,
			$this->createMock(SchemaMapper::class),
			new SubjectPartyCounter(),
			$this->db($coRows),
			new NullLogger()
		);
	}//end service()

	public function testTheGemeenteCanAnswerTheSubjectHonestly(): void {
		$objects = [];
		for ($i = 1; $i <= 12; $i++) {
			$objects[] = $this->object('zaak-' . $i);
		}

		$held = ['zaak-9', 'zaak-10', 'zaak-11', 'zaak-12'];
		$service = $this->service(
			hits: array_map(fn (ObjectEntity $o): array => $this->hit($o), $objects),
			refusal: static function (ObjectEntity $object) use ($held): ?array {
				if (in_array($object->getUuid(), $held, true) === false) {
					return null;
				}

				return [
					'uuid' => $object->getUuid(),
					'ground' => ArchivalRetentionGuard::GROUND_ARCHIVAL,
					'message' => 'The law requires us to keep this record, so we did not erase it.',
					'basis' => 'GDPR art. 17(3)(b) and the Archiefwet.',
					'action' => 'Name this record in your answer to the requester.',
				];
			},
			scope: ['files' => 2, 'timeline' => 3]
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(12, $preview['matchedCount']);
		self::assertSame(8, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
		self::assertSame(4, $preview['counts'][ErasureBucket::PROTECTED][ErasureBucket::OBJECTS]);
		self::assertSame(0, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::OBJECTS]);

		// The four kinds are counted, not just the objects: eight erasable
		// objects at two files and three timeline rows each.
		self::assertSame(16, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::FILES]);
		self::assertSame(24, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::TIMELINE]);

		// A protected record's files are NOT counted as going, because they are
		// not going. Counting them would read as the opposite of protected.
		self::assertSame(0, $preview['counts'][ErasureBucket::PROTECTED][ErasureBucket::FILES]);

		self::assertCount(4, $preview['protected']);
		foreach ($preview['protected'] as $entry) {
			self::assertContains($entry['uuid'], $held);
			self::assertSame(ArchivalRetentionGuard::GROUND_ARCHIVAL, $entry['ground']);
			self::assertNotSame('', $entry['message']);
		}
	}//end testTheGemeenteCanAnswerTheSubjectHonestly()

	public function testThePreviewWritesNothing(): void {
		$object = $this->object('zaak-1', ['email' => self::SUBJECT, 'naam' => 'Jan']);
		$service = $this->service(hits: [$this->hit($object)]);

		$before = $object->getObject();
		$service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		// The payload the preview walked is byte-for-byte what it was. The
		// object was never handed to a mapper either: the collaborators that can
		// write (ObjectService, MagicMapper) are not wired into this service at
		// all, which is the structural half of the same guarantee.
		self::assertSame($before, $object->getObject());
		// `getDeleted()` defaults to `[]` on a live row, so asking it whether
		// anything was deleted answers about the default. isSoftDeleted() is the
		// question.
		self::assertFalse($object->isSoftDeleted());
	}//end testThePreviewWritesNothing()

	public function testAnUnresolvableHoldIsProtectedNotErased(): void {
		$service = $this->service(
			hits: [$this->hit($this->object('zaak-onbekend'))],
			refusal: static function (ObjectEntity $object): ?array {
				throw new RuntimeException('the retention rules could not be read');
			}
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(0, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
		self::assertSame(1, $preview['counts'][ErasureBucket::PROTECTED][ErasureBucket::OBJECTS]);

		// NAMED, not just counted. A count alone cannot be acted on.
		self::assertCount(1, $preview['protected']);
		self::assertSame('zaak-onbekend', $preview['protected'][0]['uuid']);
		self::assertSame(ErasureBucket::GROUND_UNRESOLVABLE, $preview['protected'][0]['ground']);
	}//end testAnUnresolvableHoldIsProtectedNotErased()

	public function testAnUnresolvableImmutabilityCheckIsAlsoProtected(): void {
		$service = $this->service(
			hits: [$this->hit($this->object('zaak-status'))],
			immutable: static function (ObjectEntity $object): ?string {
				throw new RuntimeException('the archival status could not be read');
			}
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(ErasureBucket::GROUND_UNRESOLVABLE, $preview['protected'][0]['ground']);
	}//end testAnUnresolvableImmutabilityCheckIsAlsoProtected()

	public function testAnImmutableArchivalStatusIsProtectedAndNamed(): void {
		$service = $this->service(
			hits: [$this->hit($this->object('zaak-vernietigd'))],
			immutable: static fn (ObjectEntity $object): ?string => 'vernietigd'
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(ErasureBucket::GROUND_IMMUTABLE, $preview['protected'][0]['ground']);
		self::assertStringContainsString('vernietigd', $preview['protected'][0]['basis']);
	}//end testAnImmutableArchivalStatusIsProtectedAndNamed()

	public function testARecordHoldingAnotherPersonIsScrubbedNotDestroyed(): void {
		$service = $this->service(
			hits: [$this->hit($this->object('zaak-gedeeld'))],
			coRows: [
				['type' => 'email', 'value' => self::SUBJECT],
				['type' => 'email', 'value' => 'marieke@example.org'],
			]
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(0, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
		self::assertSame(1, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::OBJECTS]);
		self::assertSame(ErasureBucket::GROUND_SHARED_RECORD, $preview['items'][0]['ground']);
		self::assertSame(['email'], $preview['items'][0]['sharedWith']);
	}//end testARecordHoldingAnotherPersonIsScrubbedNotDestroyed()

	public function testTheSubjectsOwnSecondIdentifierIsNotASecondPerson(): void {
		// One person routinely carries an email AND a phone number. Counting
		// those as two subjects would downgrade every record to a scrub, which
		// is why the probe is narrowed to the matched TYPE.
		$service = $this->service(
			hits: [$this->hit($this->object('zaak-alleen-jan'))],
			coRows: [['type' => 'email', 'value' => strtoupper(self::SUBJECT)]]
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(1, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
	}//end testTheSubjectsOwnSecondIdentifierIsNotASecondPerson()

	public function testPartyRecordsNamingTheSubjectAreCounted(): void {
		$payload = [
			'omschrijving' => 'Bezwaar',
			'rollen' => [
				['rol' => 'initiator', 'betrokkene' => ['email' => self::SUBJECT]],
				['rol' => 'gemachtigde', 'betrokkene' => ['email' => 'advocaat@example.org']],
			],
			'deelzaak' => [
				'betrokkenen' => [
					['email' => self::SUBJECT],
				],
			],
		];

		$service = $this->service(hits: [$this->hit($this->object('zaak-rollen', $payload))]);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		self::assertSame(2, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::PARTIES]);
	}//end testPartyRecordsNamingTheSubjectAreCounted()

	public function testTheDigestAgreesAcrossTwoReadingsOfAnUnchangedWorld(): void {
		$service = $this->service(hits: [$this->hit($this->object('zaak-1'))]);

		$first = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);
		$second = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		self::assertSame($first['digest'], $second['digest']);
		// The generatedAt differs between the two and is deliberately outside the
		// digest, or every approval would be stale the instant it was given.
		self::assertNotSame('', $first['digest']);
	}//end testTheDigestAgreesAcrossTwoReadingsOfAnUnchangedWorld()

	public function testTheDigestMovesWhenTheWorldDoes(): void {
		$one = $this->service(hits: [$this->hit($this->object('zaak-1'))]);
		$two = $this->service(
			hits: [
				$this->hit($this->object('zaak-1')),
				$this->hit($this->object('zaak-2')),
			]
		);

		self::assertNotSame(
			$one->preview(self::SUBJECT)['digest'],
			$two->preview(self::SUBJECT)['digest']
		);
	}//end testTheDigestMovesWhenTheWorldDoes()

	public function testAnUnreadableCoSubjectIndexAssumesTheRecordIsShared(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no database'));

		$subjects = $this->createMock(DataSubjectRequestService::class);
		$subjects->method('findSubjectObjects')->willReturn([$this->hit($this->object('zaak-1'))]);

		$scopeService = $this->createMock(DestructionScopeService::class);
		$scopeService->method('preview')->willReturn(['counts' => [], 'total' => 0]);

		$service = new ErasurePreviewService(
			$subjects,
			$this->createMock(ArchivalRetentionGuard::class),
			$this->createMock(RetentionService::class),
			$scopeService,
			$this->createMock(SchemaMapper::class),
			new SubjectPartyCounter(),
			$db,
			new NullLogger()
		);

		$preview = $service->preview(self::SUBJECT, null, DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT);

		self::assertSame(1, $preview['counts'][ErasureBucket::PSEUDONYMISED][ErasureBucket::OBJECTS]);
		self::assertSame(0, $preview['counts'][ErasureBucket::ERASABLE][ErasureBucket::OBJECTS]);
	}//end testAnUnreadableCoSubjectIndexAssumesTheRecordIsShared()
}//end class
