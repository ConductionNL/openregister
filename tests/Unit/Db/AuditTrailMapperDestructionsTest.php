<?php

/**
 * Destruction entries read for the deleted list's tombstones, run as SQL against the migrated audit trail table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Kernel\Contracts\Value\DestructionQuery;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Only destruction entries, at or after the moment, within register, schema and actor, oldest first.
 *
 * @coversDefaultClass \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\DestructionQuery
 */
class AuditTrailMapperDestructionsTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	private int $id = 0;

	/**
	 * Build the table and the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			services: new AuditTrailMapperServices(container: $this->createMock(ContainerInterface::class), logger: new NullLogger(), userSession: $this->createMock(IUserSession::class), request: $this->createMock(IRequest::class))
		);
	}//end setUp()

	/**
	 * One audit row.
	 *
	 * @param string $object   The object's UUID suffix.
	 * @param string $action   The action.
	 * @param string $created  The moment, UTC.
	 * @param int    $register The register id.
	 * @param int    $schema   The schema id.
	 * @param string $user     The actor.
	 *
	 * @return void
	 */
	private function row(string $object, string $action, string $created, int $register = 4, int $schema = 12, string $user = 'alice'): void {
		$this->id++;
		$this->database->insert(
			'openregister_audit_trails',
			[
				'id' => $this->id,
				'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $this->id),
				'object' => 7,
				'object_uuid' => $object,
				'register' => $register,
				'schema' => $schema,
				'action' => $action,
				'user' => $user,
				'created' => $created,
				'changed' => '{}',
			]
		);
	}//end row()

	/**
	 * The object UUIDs of a list of entries.
	 *
	 * @param array<int, AuditTrail> $entries The entries.
	 *
	 * @return array<int, string|null>
	 */
	private static function objects(array $entries): array {
		return array_map(static fn (AuditTrail $entry): ?string => $entry->getObjectUuid(), $entries);
	}//end objects()

	/**
	 * @covers ::findDestructions
	 * @covers ::countDestructions
	 *
	 * @return void
	 */
	public function testOnlyDestructionsAtOrAfterTheMomentAreReadOldestFirst(): void {
		$this->row(object: 'before', action: 'object.destroyed', created: '2026-10-01 23:59:59');
		$this->row(object: 'later', action: 'object.destroyed', created: '2026-10-04 09:00:00');
		$this->row(object: 'at', action: 'object.destroyed', created: '2026-10-02 00:00:00');
		$this->row(object: 'deleted', action: 'delete', created: '2026-10-03 00:00:00');

		$query = new DestructionQuery(since: new DateTimeImmutable('2026-10-02T02:00:00+02:00'));

		$this->assertSame(['at', 'later'], self::objects($this->mapper->findDestructions(query: $query)));
		$this->assertSame(2, $this->mapper->countDestructions(query: $query));
	}//end testOnlyDestructionsAtOrAfterTheMomentAreReadOldestFirst()

	/**
	 * @covers ::findDestructions
	 * @covers ::countDestructions
	 *
	 * @return void
	 */
	public function testRegisterSchemaAndActorNarrowTheEntries(): void {
		$this->row(object: 'match', action: 'object.destroyed', created: '2026-10-04 09:00:00');
		$this->row(object: 'other-register', action: 'object.destroyed', created: '2026-10-04 09:00:00', register: 5);
		$this->row(object: 'other-schema', action: 'object.destroyed', created: '2026-10-04 09:00:00', schema: 13);
		$this->row(object: 'other-actor', action: 'object.destroyed', created: '2026-10-04 09:00:00', user: 'bob');

		$query = new DestructionQuery(since: new DateTimeImmutable('2026-10-01T00:00:00+00:00'), registerId: 4, schemaId: 12, actor: 'alice');

		$this->assertSame(['match'], self::objects($this->mapper->findDestructions(query: $query)));
		$this->assertSame(1, $this->mapper->countDestructions(query: $query));
	}//end testRegisterSchemaAndActorNarrowTheEntries()

	/**
	 * @covers ::findDestructions
	 * @covers ::countDestructions
	 *
	 * @return void
	 */
	public function testAPageSkipsAndLimitsButTheCountDoesNot(): void {
		foreach (['a', 'b', 'c', 'd'] as $i => $object) {
			$this->row(object: $object, action: 'object.destroyed', created: '2026-10-04 09:00:0' . $i);
		}

		$query = (new DestructionQuery(since: new DateTimeImmutable('2026-10-01T00:00:00+00:00')))->page(limit: 2, offset: 1);

		$this->assertSame(['b', 'c'], self::objects($this->mapper->findDestructions(query: $query)));
		$this->assertSame(4, $this->mapper->countDestructions(query: $query));
	}//end testAPageSkipsAndLimitsButTheCountDoesNot()

	/**
	 * @covers ::oldestDestruction
	 *
	 * @return void
	 */
	public function testTheOldestDestructionHeldIsTheCoverage(): void {
		$this->assertNull($this->mapper->oldestDestruction());

		$this->row(object: 'read-earlier', action: 'read', created: '2026-09-01 00:00:00');
		$this->row(object: 'b', action: 'object.destroyed', created: '2026-10-04 09:00:00');
		$this->row(object: 'a', action: 'object.destroyed', created: '2026-10-03 08:30:00');

		$this->assertSame('2026-10-03T08:30:00+00:00', $this->mapper->oldestDestruction()?->format(DATE_ATOM));
	}//end testTheOldestDestructionHeldIsTheCoverage()
}//end class
