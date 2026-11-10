<?php

/**
 * Golden record of the entity EntityRelationMapper builds from a raw row for insertBatch().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 * @uses \OCA\OpenRegister\Db\EntityRelation
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
class EntityRelationBuildGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/entity-relation-build-golden.json';

	public static function cases(): array {
		$cases = [
			'minimal' => ['entityId' => '7'],
			'every field' => [
				'entityId' => 7, 'fileId' => '3', 'chunkId' => 4, 'objectId' => '5', 'emailId' => 6,
				'positionStart' => '10', 'positionEnd' => 20, 'confidence' => '0.75', 'detectionMethod' => 'regex',
				'context' => 'ctx', 'role' => 9, 'anonymized' => 1, 'skipAnonymization' => '0', 'bases' => ['b' => 1],
				'createdAt' => new DateTime('2026-01-02T03:04:05+00:00'),
			],
			'nulls skip the optional ids' => ['entityId' => 1, 'fileId' => null, 'chunkId' => null, 'objectId' => null, 'emailId' => null, 'detectionMethod' => null],
			'nulls set the nullable texts' => ['entityId' => 1, 'context' => null, 'role' => null],
			'nulls cast for positions and flags' => ['entityId' => 1, 'positionStart' => null, 'positionEnd' => null, 'confidence' => null, 'anonymized' => null, 'skipAnonymization' => null, 'bases' => null],
			'null createdAt keeps now' => ['entityId' => 1, 'createdAt' => null],
			'unknown keys ignored' => ['entityId' => 1, 'nope' => 'x', 'registerId' => 3],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	#[DataProvider('cases')]
	public function testBuildMatchesGolden(array $row): void {
		$mapper = new EntityRelationMapper(
			db: $this->createMock(IDBConnection::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			userSession: $this->createMock(IUserSession::class),
			entityEvents: new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
		$before = new DateTime();
		$relation = (new \ReflectionMethod($mapper, 'buildRelationFromRow'))->invoke($mapper, $row);

		$data = $relation->jsonSerialize();
		$createdAt = $relation->getCreatedAt();
		$data['createdAt'] = ($createdAt >= $before && $createdAt <= new DateTime()) ? 'now' : $data['createdAt'];
		$actual = json_decode((string)json_encode(['data' => $data, 'updated' => array_keys($relation->getUpdatedFields())]), true);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testBuildMatchesGolden()
}//end class
