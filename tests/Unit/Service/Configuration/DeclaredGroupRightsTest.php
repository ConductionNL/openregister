<?php

/**
 * The import stores the rights a configuration declares for its groups (REQ-RAH-003, task 3.1).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\DeclaredGroupRights;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\GroupReconcilerInterface;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Authorization\DeclaredGroupRights
 */
class DeclaredGroupRightsTest extends TestCase {

	private const DOCUMENT = [
		'components' => [
			'schemas'   => [
				'Publicatie' => [
					'authorization' => [
						'read'   => ['public', 'authenticated', 'admin'],
						'update' => ['woo-redactie', ['group' => 'woo-beheer', 'match' => ['status' => 'concept']], ['match' => ['x' => 1]]],
						'public' => true,
						'roles'  => ['editor' => ['woo-redactie']],
					],
				],
				'bijlage'    => ['slug' => 'Bijlage-Slug'],
			],
			'registers' => ['publicaties' => ['authorization' => ['create' => ['woo-beheer']]], 'kapot' => 'not a definition'],
		],
	];

	public function testTheImportStoresTheDeclaredRights(): void {
		$stored = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = json_decode($value, true);
				return true;
			}
		);
		$handler = $this->importHandler($config);
		$provisioner = $this->createMock(GroupProvisioner::class);
		$provisioner->method('provision')->willReturn(['created' => []]);
		$handler->setGroupProvisioner($provisioner);

		(new ReflectionMethod(ImportHandler::class, 'provisionDeclaredGroups'))->invoke($handler, self::DOCUMENT, 'opencatalogi');

		$this->assertSame(['woo-beheer', 'woo-redactie'], $stored['declared_groups_opencatalogi']);
		$this->assertSame(
			[
				'schemas'   => [
					'publicatie'   => ['update' => ['woo-redactie', ['group' => 'woo-beheer', 'match' => ['status' => 'concept']]]],
					'bijlage-slug' => [],
				],
				'registers' => ['publicaties' => ['create' => ['woo-beheer']]],
			],
			$stored['declared_group_rights_opencatalogi']
		);
	}

	public function testTheEndOfAnImportRunsTheReconciler(): void {
		$handler = $this->importHandler($this->createMock(IAppConfig::class));
		$reconciler = $this->createMock(GroupReconcilerInterface::class);
		$reconciler->expects($this->once())->method('reconcile')->willReturn(['declared' => [], 'created' => []]);
		$handler->setGroupReconciler($reconciler);

		(new ReflectionMethod(ImportHandler::class, 'reconcileGroups'))->invoke($handler);
	}

	public function testReservedPrincipalsAndGrouplessRulesAreNoRights(): void {
		$rights = new DeclaredGroupRights(new RbacGroupCollector());

		$this->assertSame([], $rights->fromBlock(['read' => ['public', 'authenticated', 'admin', ['match' => []]]]));
		$this->assertSame([], $rights->fromBlock(null));
		$this->assertNull($rights->groupOf('public'));
		$this->assertSame('woo-beheer', $rights->groupOf(['group' => 'woo-beheer']));
	}

	private function importHandler(IAppConfig $config): ImportHandler {
		return new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $config
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: new NullLogger(),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}
}
