<?php

/**
 * Accepting the shipped definition for a part an app upgrade kept local.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConflictService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A municipality tightened `level` locally; release 1.5.0 changed it too and the upgrade kept the local one.
 *
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\ShippedConflictService
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\SchemaPartWriter
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\LeafReset
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\LiveDescriptor
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\GuardedAnnotations
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\PartReset
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\AuthorizationBlocks
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 */
class ShippedConflictServiceTest extends TestCase {

	/**
	 * The definition 1.5.0 shipped for `level`.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = ['type' => 'string', 'enum' => ['junior', 'medior', 'senior', 'lead']];

	/**
	 * The definition the instance kept for `level`.
	 *
	 * @var array<string, mixed>
	 */
	private const LOCAL = ['type' => 'string', 'enum' => ['junior', 'senior'], 'title' => 'Local title'];

	/**
	 * Audit rows the guard wrote.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $rows = [];

	/**
	 * Schemas handed to SchemaMapper::update().
	 *
	 * @var array<int, Schema>
	 */
	private array $written = [];

	/**
	 * Baselines recorded, by subject.
	 *
	 * @var array<string, ShippedDefinition>
	 */
	private array $baselines = [];

	/**
	 * Conflict lists recorded, by slug.
	 *
	 * @var array<string, ShippedDefinition>
	 */
	private array $conflictRecords = [];

	/**
	 * The stored schema, with the local `level` and an untouched `code`.
	 *
	 * @return Schema
	 */
	private function storedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(329);
		$schema->setSlug('learner-profile');
		$schema->setProperties(['level' => self::LOCAL, 'code' => ['type' => 'string', 'enum' => ['b', 'a']]]);
		$schema->setRequired(['level']);
		$schema->setAuthorization(['read' => ['hr']]);

		return $schema;
	}//end storedSchema()

	/**
	 * The service over the real guard; the store, the mapper and the audit mapper are the seams.
	 *
	 * @param Schema     $schema    The schema the mapper finds.
	 * @param IUser|null $actor     Who is signed in.
	 * @param array|null $conflicts The pending conflicts, null for no record.
	 *
	 * @return ShippedConflictService
	 */
	private function service(Schema $schema, ?IUser $actor, ?array $conflicts): ShippedConflictService {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => ('schema:' . $slug));
		$store->method('readConflicts')->willReturnCallback(
			static function (string $slug) use ($conflicts): ?array {
				if ($conflicts === null || $slug !== 'learner-profile') {
					return null;
				}

				return ['definition' => ['conflicts' => $conflicts], 'app' => 'learniq', 'appVersion' => '1.5.0', 'recordedAt' => '2026-11-14T08:00:00+00:00'];
			}
		);
		$store->method('read')->willReturnCallback(
			static fn (string $subject): ?array => [
				'definition' => ['properties' => ['level' => ['type' => 'string', 'enum' => ['junior', 'medior', 'senior']], 'code' => ['type' => 'string']], 'required' => ['level']],
				'app' => 'learniq',
				'appVersion' => '1.5.0',
				'recordedAt' => '2026-11-14T08:00:00+00:00',
			]
		);
		$store->method('record')->willReturnCallback(
			function (string $subject, ShippedDefinition $shipped): bool {
				$this->baselines[$subject] = $shipped;
				return true;
			}
		);
		$store->method('recordConflicts')->willReturnCallback(
			function (string $slug, ShippedDefinition $conflicts): bool {
				$this->conflictRecords[$slug] = $conflicts;
				return true;
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				array_push($this->rows, ...$entries);
				return $entries;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($actor);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);
		$guard = new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($schema): Schema {
				if ($id === 'learner-profile') {
					return $schema;
				}

				throw new DoesNotExistException('no schema ' . $id);
			}
		);
		$schemas->method('update')->willReturnCallback(
			function (Schema $entity): Schema {
				$this->written[] = $entity;
				return $entity;
			}
		);

		return new ShippedConflictService(schemas: $schemas, baselines: $store, guard: $guard, session: $session);
	}//end service()

	/**
	 * An administrator.
	 *
	 * @return IUser
	 */
	private function admin(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Admin');
		return $user;
	}//end admin()

	/**
	 * The two conflicts the 1.5.0 upgrade kept local.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function pendingConflicts(): array {
		return [
			['path' => 'properties.level', 'shipped' => self::SHIPPED, 'live' => self::LOCAL],
			['path' => 'authorization', 'shipped' => ['read' => ['hr', 'instructors']], 'live' => ['read' => ['hr']]],
		];
	}//end pendingConflicts()

	/**
	 * The pending list names the release and both definitions of each part.
	 *
	 * @return void
	 */
	public function testThePendingConflictsNameTheReleaseAndBothDefinitions(): void {
		$pending = $this->service(schema: $this->storedSchema(), actor: null, conflicts: self::pendingConflicts())->pending(schema: 'learner-profile');

		$this->assertSame(expected: 'learner-profile', actual: $pending['schema']);
		$this->assertSame(expected: 329, actual: $pending['schemaId']);
		$this->assertSame(expected: 'learniq', actual: $pending['app']);
		$this->assertSame(expected: '1.5.0', actual: $pending['appVersion']);
		$this->assertSame(expected: self::pendingConflicts(), actual: $pending['conflicts']);
	}//end testThePendingConflictsNameTheReleaseAndBothDefinitions()

	/**
	 * A schema no upgrade reported on has no conflicts, and an entry without a path is not one.
	 *
	 * @return void
	 */
	public function testWithoutARecordThereIsNothingPending(): void {
		$none = $this->service(schema: $this->storedSchema(), actor: null, conflicts: null)->pending(schema: 'learner-profile');
		$this->assertSame(expected: [], actual: $none['conflicts']);
		$this->assertSame(expected: '', actual: $none['app']);

		$junk = $this->service(schema: $this->storedSchema(), actor: null, conflicts: ['nonsense', ['shipped' => 1]])->pending(schema: 'learner-profile');
		$this->assertSame(expected: [], actual: $junk['conflicts']);
	}//end testWithoutARecordThereIsNothingPending()

	/**
	 * Accepting writes the shipped definition into that part only, moves the baseline, drops the conflict and names the actor.
	 *
	 * @return void
	 */
	public function testAcceptingTakesTheShippedDefinitionAndPutsItOnTheRecord(): void {
		$schema = $this->storedSchema();
		$outcome = $this->service(schema: $schema, actor: $this->admin(), conflicts: self::pendingConflicts())
			->accept(schema: 'learner-profile', path: ' properties.level ');

		$this->assertTrue(condition: $outcome['applied']);
		$this->assertSame(expected: 'properties.level', actual: $outcome['path']);
		$this->assertSame(expected: self::LOCAL, actual: $outcome['from']);
		$this->assertSame(expected: self::SHIPPED, actual: $outcome['to']);

		// Only that leaf: `code` keeps its local enum order.
		$this->assertSame(expected: [$schema], actual: $this->written);
		$this->assertSame(expected: ['level' => self::SHIPPED, 'code' => ['type' => 'string', 'enum' => ['b', 'a']]], actual: $schema->getProperties());

		$baseline = $this->baselines['schema:learner-profile'];
		$this->assertSame(expected: self::SHIPPED, actual: $baseline->definition['properties']['level']);
		$this->assertSame(expected: ['type' => 'string'], actual: $baseline->definition['properties']['code']);
		$this->assertSame(expected: '1.5.0', actual: $baseline->appVersion);

		$remaining = $this->conflictRecords['learner-profile'];
		$this->assertSame(expected: [self::pendingConflicts()[1]], actual: $remaining->definition['conflicts']);
		$this->assertSame(expected: 'learniq', actual: $remaining->app);

		$this->assertCount(expectedCount: 1, haystack: $this->rows);
		$this->assertSame(expected: ShippedConfigurationGuard::ACTION_DECIDED, actual: $this->rows[0]->getAction());
		$this->assertSame(expected: 'admin', actual: $this->rows[0]->getUser());
		$this->assertSame(
			expected: ['schema' => 'learner-profile', 'path' => 'properties.level', 'decision' => 'accepted-shipped', 'from' => self::LOCAL, 'to' => self::SHIPPED],
			actual: $this->rows[0]->getChanged()
		);
	}//end testAcceptingTakesTheShippedDefinitionAndPutsItOnTheRecord()

	/**
	 * An upstream removal that was a conflict is accepted by removing the part.
	 *
	 * @return void
	 */
	public function testAcceptingAnUpstreamRemovalRemovesThePart(): void {
		$schema = $this->storedSchema();
		$conflicts = [['path' => 'properties.code', 'shipped' => null, 'live' => ['type' => 'string', 'enum' => ['b', 'a']]]];

		$outcome = $this->service(schema: $schema, actor: $this->admin(), conflicts: $conflicts)->accept(schema: 'learner-profile', path: 'properties.code');

		$this->assertTrue(condition: $outcome['applied']);
		$this->assertNull(actual: $outcome['to']);
		$this->assertSame(expected: ['level' => self::LOCAL], actual: $schema->getProperties());
		$this->assertArrayNotHasKey(key: 'code', array: $this->baselines['schema:learner-profile']->definition['properties']);
		$this->assertSame(expected: [], actual: $this->conflictRecords['learner-profile']->definition['conflicts']);
	}//end testAcceptingAnUpstreamRemovalRemovesThePart()

	/**
	 * Without a session, without a path or for a path that is not pending, nothing is written or recorded.
	 *
	 * @return void
	 */
	public function testNothingIsAcceptedWithoutAnActorOrAPendingConflict(): void {
		$cases = [
			['actor' => null, 'path' => 'properties.level', 'reason' => 'needs an actor'],
			['actor' => $this->admin(), 'path' => '', 'reason' => 'name one conflicting part'],
			['actor' => $this->admin(), 'path' => 'properties.code', 'reason' => '"properties.code" is not a conflict'],
		];

		foreach ($cases as $case) {
			$schema = $this->storedSchema();
			$outcome = $this->service(schema: $schema, actor: $case['actor'], conflicts: self::pendingConflicts())
				->accept(schema: 'learner-profile', path: $case['path']);

			$this->assertFalse(condition: $outcome['applied']);
			$this->assertStringContainsString(needle: $case['reason'], haystack: $outcome['reason']);
			$this->assertNull(actual: $outcome['to']);
			$this->assertSame(expected: self::LOCAL, actual: $schema->getProperties()['level']);
		}

		$this->assertSame(expected: [], actual: $this->written);
		$this->assertSame(expected: [], actual: $this->baselines);
		$this->assertSame(expected: [], actual: $this->conflictRecords);
		$this->assertSame(expected: [], actual: $this->rows);
	}//end testNothingIsAcceptedWithoutAnActorOrAPendingConflict()

	/**
	 * An unknown schema is the mapper's not-found, for the controller to answer 404.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);

		$this->service(schema: $this->storedSchema(), actor: $this->admin(), conflicts: [])->accept(schema: 'nope', path: 'properties.level');
	}//end testAnUnknownSchemaIsNotFound()
}//end class
