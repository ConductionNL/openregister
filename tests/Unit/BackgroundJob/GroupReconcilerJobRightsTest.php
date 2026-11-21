<?php

/**
 * The hourly job reverts an edit to a product-owned group's rights (REQ-RAH-003, task 3.3).
 *
 * The real job runs the real reconciler over a tampered schema; the mappers,
 * the app config, the group manager and the dispatcher are the boundaries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
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

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\GroupReconcilerJob;
use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\Db\MagicMapperInterface;
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\GroupReconcilerJob
 * @uses   \OCA\OpenRegister\BackgroundJob\JobRuntime
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupRightsReconciler
 * @uses   \OCA\OpenRegister\Service\Authorization\DeclaredGroupRights
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupReconciler
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupDeclarationSources
 * @uses   \OCA\OpenRegister\Service\Authorization\GroupProvisioner
 * @uses   \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class GroupReconcilerJobRightsTest extends TestCase {

	public function testTheJobRevertsATamperedProductGroup(): void {
		$document = ['components' => ['schemas' => ['publicatie' => ['authorization' => ['update' => ['woo-redactie']]]]]];
		$schema = new Schema();
		$schema->setSlug('publicatie');
		$schema->setApplication('opencatalogi');
		$schema->setAuthorization(['update' => ['team-noord'], 'delete' => ['woo-redactie']]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$schema]);
		$schemas->method('findByApplicationAndSlug')->willReturn($schema);
		$schemas->expects($this->once())->method('update')->with($schema);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturn([]);

		$rights = new DeclaredGroupRights(new RbacGroupCollector());
		$stored = [
			'declared_groups_opencatalogi'       => '["woo-redactie"]',
			'declared_group_rights_opencatalogi' => json_encode($rights->fromDocument($document)),
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn(array_keys($stored));
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(true);

		$sources = new GroupDeclarationSources(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $config);
		$reconciler = new GroupReconciler(
			$sources,
			new RbacGroupCollector(),
			new GroupProvisioner($groups, new NullLogger()),
			new NullLogger(),
			new GroupRightsReconciler($sources, $rights, $this->createMock(IEventDispatcher::class), new NullLogger())
		);
		$job = new GroupReconcilerJob(new JobRuntime($this->createMock(ITimeFactory::class), new NullLogger()), $reconciler);

		(new ReflectionMethod(GroupReconcilerJob::class, 'run'))->invoke($job, null);

		$this->assertSame(['update' => ['team-noord', 'woo-redactie']], $schema->getAuthorization());
	}
}
