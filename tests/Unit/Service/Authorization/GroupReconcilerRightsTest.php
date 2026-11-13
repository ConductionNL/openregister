<?php

/**
 * A product-owned group's rights are restored to what its app declares (REQ-RAH-003).
 *
 * The reconciler runs for real over a tampered live schema and register: the
 * mappers, the app config, the group manager and the event dispatcher are the
 * boundaries, and the stored declaration is what the import writes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Authorization\DeclaredGroupRights;
use OCA\OpenRegister\Service\Authorization\GroupDeclarationSources;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCA\OpenRegister\Service\Authorization\GroupRightsReconciler;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Authorization\GroupRightsReconciler
 * @covers \OCA\OpenRegister\Service\Authorization\DeclaredGroupRights
 * @covers \OCA\OpenRegister\Service\Authorization\GroupReconciler
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupDeclarationSources
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupProvisioner
 * @uses   \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class GroupReconcilerRightsTest extends TestCase {

	/**
	 * What opencatalogi's configuration declares, as the import stores it.
	 */
	private const DOCUMENT = [
		'components' => [
			'schemas'   => [
				'publicatie' => [
					'slug'          => 'publicatie',
					'authorization' => [
						'read'   => ['public'],
						'update' => ['woo-redactie', ['group' => 'woo-beheer', 'match' => ['status' => 'concept']]],
					],
				],
			],
			'registers' => ['publicaties' => ['authorization' => ['create' => ['woo-beheer']]]],
		],
	];

	/**
	 * The live schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * The live register.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The audit rows written.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $audited = [];

	protected function setUp(): void {
		$this->schema = new Schema();
		$this->schema->setSlug('publicatie');
		$this->schema->setApplication('opencatalogi');
		$this->register = new Register();
		$this->register->setSlug('publicaties');
		$this->register->setApplication('opencatalogi');
		$this->register->setAuthorization(['create' => ['woo-beheer']]);

		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('findAll')->willReturn([$this->schema]);
		$this->schemas->method('findByApplicationAndSlug')->willReturnCallback(
			function (string $slug, string $app): ?Schema {
				if ($slug === $this->schema->getSlug() && $app === $this->schema->getApplication()) {
					return $this->schema;
				}

				return null;
			}
		);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('findAll')->willReturn([$this->register]);

		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('groupExists')->willReturn(true);
	}

	private function reconciler(): GroupReconciler {
		$rights = new DeclaredGroupRights(new RbacGroupCollector());
		$stored = [
			'declared_groups_opencatalogi'       => json_encode((new RbacGroupCollector())->fromDocument(self::DOCUMENT)),
			'declared_group_rights_opencatalogi' => json_encode($rights->fromDocument(self::DOCUMENT)),
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn(array_keys($stored));
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (CriticalActionPerformedEvent $event): void {
				$this->audited[] = $event->getParameters();
			}
		);

		$sources = $this->sources($config);

		return new GroupReconciler(
			$sources,
			new RbacGroupCollector(),
			new GroupProvisioner($this->groups, new NullLogger()),
			new NullLogger(),
			new GroupRightsReconciler($sources, $rights, $dispatcher, new NullLogger())
		);
	}

	private function sources(IAppConfig $config): GroupDeclarationSources {
		return new GroupDeclarationSources(new SchemaCatalog($this->registers, $this->schemas, $this->createMock(MagicMapperInterface::class)), $config);
	}

	public function testARemovedRightIsRestored(): void {
		// An administrator took woo-redactie's update away.
		$this->schema->setAuthorization(['read' => ['public'], 'update' => [['match' => ['status' => 'concept'], 'group' => 'woo-beheer']]]);
		$this->schemas->expects($this->once())->method('update')->with($this->schema);

		$result = $this->reconciler()->reconcile();

		$this->assertSame(
			['read' => ['public'], 'update' => [['match' => ['status' => 'concept'], 'group' => 'woo-beheer'], 'woo-redactie']],
			$this->schema->getAuthorization()
		);
		$expected = [
			'app'       => 'opencatalogi',
			'kind'      => 'schema',
			'slug'      => 'publicatie',
			'group'     => 'woo-redactie',
			'action'    => 'update',
			'direction' => 'restored',
		];
		$this->assertSame([$expected], $result['rights']);
		$this->assertSame(
			[
				['group' => 'woo-redactie', 'action' => 'update', 'direction' => 'restored', 'kind' => 'schema', 'slug' => 'publicatie', 'app' => 'opencatalogi'],
			],
			$this->audited
		);
	}

	public function testAnAddedRightIsRemoved(): void {
		// The scenario: update removed and delete added on publicatie, both reverted with two audit rows.
		$this->schema->setAuthorization(
			['read' => ['public'], 'update' => [['group' => 'woo-beheer', 'match' => ['status' => 'concept']]], 'delete' => ['woo-redactie']]
		);
		$this->register->setAuthorization(['create' => ['woo-beheer'], 'delete' => ['woo-beheer']]);

		$result = $this->reconciler()->reconcile();

		$this->assertSame(
			['read' => ['public'], 'update' => [['group' => 'woo-beheer', 'match' => ['status' => 'concept']], 'woo-redactie']],
			$this->schema->getAuthorization()
		);
		$this->assertSame(['create' => ['woo-beheer']], $this->register->getAuthorization());
		$this->assertSame(
			[
				['schema', 'woo-redactie', 'update', 'restored'],
				['schema', 'woo-redactie', 'delete', 'removed'],
				['register', 'woo-beheer', 'delete', 'removed'],
			],
			array_map(static fn (array $c): array => [$c['kind'], $c['group'], $c['action'], $c['direction']], $result['rights'])
		);
		$this->assertCount(3, $this->audited);
	}

	public function testAnUndeclaredGroupIsNotTouched(): void {
		$live = [
			'read'   => ['public', 'team-noord'],
			'update' => ['woo-redactie', ['group' => 'woo-beheer', 'match' => ['status' => 'concept']], 'team-noord'],
			'delete' => ['team-noord'],
		];
		$this->schema->setAuthorization($live);
		$this->schemas->expects($this->never())->method('update');
		$this->registers->expects($this->never())->method('update');

		$result = $this->reconciler()->reconcile();

		$this->assertSame($live, $this->schema->getAuthorization());
		$this->assertSame([], $result['rights']);
		$this->assertSame([], $this->audited);
	}

	public function testMembershipIsNeverChanged(): void {
		$this->schema->setAuthorization(['update' => ['woo-beheer'], 'delete' => ['woo-redactie']]);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('addUser');
		$group->expects($this->never())->method('removeUser');
		$this->groups->method('get')->willReturn($group);
		$this->groups->expects($this->never())->method('createGroup');

		$result = $this->reconciler()->reconcile();

		$this->assertNotSame([], $result['rights']);
	}

	public function testASameSlugSchemaOfAnotherAppIsNotTouched(): void {
		$this->schema->setApplication('dossiq');
		$this->schema->setAuthorization(['delete' => ['woo-redactie']]);
		$this->schemas->expects($this->never())->method('update');

		$this->assertSame([], $this->reconciler()->reconcile()['rights']);
		$this->assertSame(['delete' => ['woo-redactie']], $this->schema->getAuthorization());
	}

	public function testAFailingSaveIsLoggedAndTheRestStillRepaired(): void {
		$this->schema->setAuthorization(['delete' => ['woo-redactie']]);
		$this->register->setAuthorization(['create' => []]);
		$this->schemas->method('update')->willThrowException(new \RuntimeException('locked'));
		$this->registers->expects($this->once())->method('update')->with($this->register);

		$result = $this->reconciler()->reconcile();

		$this->assertSame(['create' => ['woo-beheer']], $this->register->getAuthorization());
		$this->assertSame([['register', 'restored']], array_map(static fn (array $c): array => [$c['kind'], $c['direction']], $result['rights']));
	}

	public function testAnAppWhoseRightsWereNeverStoredIsLeftAlone(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn(['declared_groups_opencatalogi']);
		$config->method('getValueString')->willReturn('["woo-redactie"]');
		$this->schemas->expects($this->never())->method('update');
		$sources = $this->sources($config);
		$rights = new DeclaredGroupRights(new RbacGroupCollector());
		$reconciler = new GroupRightsReconciler($sources, $rights, $this->createMock(IEventDispatcher::class), new NullLogger());

		$this->assertSame([], $reconciler->reconcile());
	}
}
