<?php

/**
 * The reset to the shipped baseline, reached through the service the occ
 * command and the admin route call, with the real guard behind it.
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
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
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
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * learniq D13, in miniature: learner-profile lost the learner's own read rule.
 */
class ShippedBaselineResetServiceTest extends TestCase {

	/**
	 * The rule the instance lacks.
	 *
	 * @var array<string, mixed>
	 */
	private const SELF_READ = ['group' => 'authenticated', 'match' => ['ncUserId' => '$userId']];

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
	 * The stored schema: the shipped read rule is missing, and an enum carries a
	 * local order that a normalising write would sort.
	 *
	 * @return Schema
	 */
	private function storedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(329);
		$schema->setSlug('learner-profile');
		$schema->setProperties(
			[
				'level' => ['type' => 'string', 'enum' => ['senior', 'junior', 'medior'], 'title' => 'Local title'],
			]
		);
		$schema->setRequired(['level']);
		$schema->setAuthorization(
			[
				'read' => ['compliance-officers', 'hr', 'instructors'],
				'update' => ['hr'],
			]
		);

		return $schema;
	}//end storedSchema()

	/**
	 * The service over the real guard; the baseline store and the mappers are the seams.
	 *
	 * @param Schema    $schema The schema the mapper finds.
	 * @param IUser|null $actor  Who is signed in.
	 *
	 * @return ShippedBaselineResetService
	 */
	private function service(Schema $schema, ?IUser $actor): ShippedBaselineResetService {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(
			static fn (string $slug): string => ('schema:' . $slug)
		);
		$store->method('read')->willReturnCallback(
			static function (string $subject): ?array {
				if ($subject !== 'schema:learner-profile') {
					return null;
				}

				return [
					'definition' => [
						'properties' => [
							'level' => ['type' => 'string', 'enum' => ['junior', 'medior', 'senior'], 'title' => 'Level'],
						],
						'required' => ['level'],
						'authorization' => [
							'read' => ['instructors', 'hr', 'compliance-officers', self::SELF_READ],
							'update' => ['hr'],
						],
					],
					'app' => 'learniq',
					'appVersion' => '1.4.0',
					'recordedAt' => '2026-10-05T08:22:30+00:00',
				];
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->rows[] = $entry;
				}

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
				if ($id === 'learner-profile' || $id === 329 || $id === '329') {
					return $schema;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no schema ' . $id);
			}
		);
		$schemas->method('update')->willReturnCallback(
			function (Schema $entity): Schema {
				$this->written[] = $entity;
				return $entity;
			}
		);

		return new ShippedBaselineResetService(schemas: $schemas, guard: $guard);
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
	 * The D13 heal: the rule is back, the local enum order and title are not touched,
	 * and the trail names who did it.
	 *
	 * @return void
	 */
	public function testResettingTheReadRuleRestoresItAndLeavesOtherLocalEditsAlone(): void {
		$schema = $this->storedSchema();
		$result = $this->service(schema: $schema, actor: $this->admin())->reset(schema: 'learner-profile', path: 'authorization.read');

		$this->assertTrue($result['applied'], $result['reason']);
		$this->assertCount(1, $this->written);

		$read = $schema->getAuthorization()['read'];
		$this->assertContains(self::SELF_READ, $read);
		$this->assertContains('instructors', $read);
		$this->assertSame(['hr'], $schema->getAuthorization()['update']);

		// The other local edits are exactly as stored, enum order included.
		$this->assertSame(['senior', 'junior', 'medior'], $schema->getProperties()['level']['enum']);
		$this->assertSame('Local title', $schema->getProperties()['level']['title']);

		$this->assertCount(1, $this->rows);
		$this->assertSame(ShippedConfigurationGuard::ACTION_RESET, $this->rows[0]->getAction());
		$this->assertSame('admin', $this->rows[0]->getUser());
		$changed = $this->rows[0]->getChanged();
		$this->assertSame('learner-profile', $changed['schema']);
		$this->assertSame('authorization.read', $changed['path']);
		$this->assertNotContains(self::SELF_READ, $changed['from']);
		$this->assertContains(self::SELF_READ, $changed['to']);
	}//end testResettingTheReadRuleRestoresItAndLeavesOtherLocalEditsAlone()

	/**
	 * The preview names the change and writes nothing.
	 *
	 * @return void
	 */
	public function testAPreviewNamesTheChangeAndWritesNothing(): void {
		$schema = $this->storedSchema();
		$preview = $this->service(schema: $schema, actor: $this->admin())->preview(schema: 'learner-profile', path: 'authorization.read');

		$this->assertTrue($preview['applicable']);
		$this->assertSame('learner-profile', $preview['schema']);
		$this->assertSame('authorization.read', $preview['path']);
		$this->assertContains(self::SELF_READ, $preview['to']);
		$this->assertNotContains(self::SELF_READ, $preview['from']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->rows);
		$this->assertNotContains(self::SELF_READ, $schema->getAuthorization()['read']);
	}//end testAPreviewNamesTheChangeAndWritesNothing()

	/**
	 * No part named is no reset: there is no "everything".
	 *
	 * @return void
	 */
	public function testAnEmptyPartIsRefused(): void {
		$result = $this->service(schema: $this->storedSchema(), actor: $this->admin())->reset(schema: 'learner-profile', path: '  ');

		$this->assertFalse($result['applied']);
		$this->assertStringContainsString('name one part', $result['reason']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->rows);
	}//end testAnEmptyPartIsRefused()

	/**
	 * Without a signed-in actor nothing is written, and the guard says why.
	 *
	 * @return void
	 */
	public function testNoActorMeansNoWrite(): void {
		$result = $this->service(schema: $this->storedSchema(), actor: null)->reset(schema: 'learner-profile', path: 'authorization.read');

		$this->assertFalse($result['applied']);
		$this->assertStringContainsString('needs an actor', $result['reason']);
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->rows);
	}//end testNoActorMeansNoWrite()

	/**
	 * A part that already matches is refused, not silently "applied".
	 *
	 * @return void
	 */
	public function testAPartThatAlreadyMatchesIsRefused(): void {
		$result = $this->service(schema: $this->storedSchema(), actor: $this->admin())->reset(schema: 'learner-profile', path: 'authorization.update');

		$this->assertFalse($result['applied']);
		$this->assertStringContainsString('already matches', $result['reason']);
		$this->assertSame([], $this->written);
	}//end testAPartThatAlreadyMatchesIsRefused()
}//end class
