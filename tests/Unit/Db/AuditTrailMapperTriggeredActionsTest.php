<?php

/**
 * The cascade evidence of one delete, read by its indexed trigger column against the migrated audit trail table.
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
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Only the rows the trigger wrote, only the asked actions, never purged ones, oldest first, capped.
 *
 * @coversDefaultClass \OCA\OpenRegister\Db\AuditTrailMapper
 */
class AuditTrailMapperTriggeredActionsTest extends TestCase {
	private const ROOT = 'root-0000-0000-0000-000000000001';

	private const ACTIONS = [
		'referential_integrity.cascade_delete',
		'referential_integrity.set_null',
		'referential_integrity.set_default',
	];

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
	 * @param string      $object  The object's UUID.
	 * @param string      $action  The action.
	 * @param string|null $trigger The trigger_object column.
	 * @param string|null $purged  The purged_at stamp.
	 *
	 * @return void
	 */
	private function row(string $object, string $action, ?string $trigger=self::ROOT, ?string $purged=null): void {
		$this->id++;
		$this->database->insert(
			'openregister_audit_trails',
			[
				'id' => $this->id,
				'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $this->id),
				'object' => 7,
				'object_uuid' => $object,
				'register' => 4,
				'schema' => 12,
				'action' => $action,
				'user' => 'alice',
				'created' => '2026-11-20 10:00:00',
				'changed' => '{}',
				'trigger_object' => $trigger,
				'purged_at' => $purged,
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
	 * @covers ::findTriggeredActions
	 *
	 * @return void
	 */
	public function testOnlyTheTriggersAskedActionsAreReadOldestFirst(): void {
		$this->row(object: 'activity-1', action: 'referential_integrity.cascade_delete');
		$this->row(object: 'quote-1', action: 'referential_integrity.set_null');
		$this->row(object: 'other-delete', action: 'referential_integrity.cascade_delete', trigger: 'another-root');
		$this->row(object: 'untriggered', action: 'referential_integrity.cascade_delete', trigger: null);
		$this->row(object: 'blocked', action: 'referential_integrity.restrict_blocked');
		$this->row(object: 'purged', action: 'referential_integrity.set_default', purged: '2026-11-21 00:00:00');
		$this->row(object: 'quote-2', action: 'referential_integrity.set_default');

		$rows = $this->mapper->findTriggeredActions(triggerObject: self::ROOT, options: new ActionLookupOptions(actions: self::ACTIONS, limit: 100));

		$this->assertSame(['activity-1', 'quote-1', 'quote-2'], self::objects($rows));
	}//end testOnlyTheTriggersAskedActionsAreReadOldestFirst()

	/**
	 * @covers ::findTriggeredActions
	 *
	 * @return void
	 */
	public function testTheCapLimitsTheRowsAndNoActionsMeansEveryAction(): void {
		$this->row(object: 'a', action: 'referential_integrity.cascade_delete');
		$this->row(object: 'b', action: 'referential_integrity.restrict_blocked');
		$this->row(object: 'c', action: 'referential_integrity.cascade_delete');

		$this->assertSame(['a', 'b'], self::objects($this->mapper->findTriggeredActions(triggerObject: self::ROOT, options: new ActionLookupOptions(limit: 2))));
		$this->assertSame(['a', 'b', 'c'], self::objects($this->mapper->findTriggeredActions(triggerObject: self::ROOT, options: new ActionLookupOptions(limit: 10))));
	}//end testTheCapLimitsTheRowsAndNoActionsMeansEveryAction()
}//end class
