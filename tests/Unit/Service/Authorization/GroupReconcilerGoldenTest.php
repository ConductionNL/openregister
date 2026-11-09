<?php

/**
 * Golden outcomes of the declared-group sweep, pinned before its refactor.
 *
 * The reconciler decides which Nextcloud groups exist for authorization rules
 * to point at. A group it provisions is one a rule can admit through (once an
 * administrator fills it); a name it refuses (reserved principals, empty or
 * non-string names, malformed stored declarations) must never be created.
 * The expected answers were recorded from the code as it stood before the
 * three declaration sources were bundled.
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
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Authorization\GroupDeclarationSources;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins which declared groups are provisioned and which are refused.
 */
class GroupReconcilerGoldenTest extends TestCase {

	/**
	 * Groups the group manager was asked to create.
	 *
	 * @var string[]
	 */
	private array $created = [];

	/**
	 * Build the reconciler over the given sources.
	 *
	 * @param RegisterMapperInterface $registers The registers.
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 * @param IAppConfig              $appConfig The stored declarations.
	 * @param string[]                $existing  Groups that already exist.
	 *
	 * @return GroupReconciler The reconciler.
	 */
	private function reconciler(
		RegisterMapperInterface $registers,
		SchemaMapperInterface $schemas,
		IAppConfig $appConfig,
		array $existing
	): GroupReconciler {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => in_array($gid, $existing, true)
		);
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) {
				if ($gid === 'unmakeable') {
					throw new RuntimeException('backend refused');
				}

				$this->created[] = $gid;
				return null;
			}
		);

		return new GroupReconciler(
			new GroupDeclarationSources(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $appConfig),
			new RbacGroupCollector(),
			new GroupProvisioner($groups, new NullLogger()),
			new NullLogger()
		);
	}//end reconciler()

	/**
	 * The live and stored declarations used by most scenarios.
	 *
	 * @return array{0: RegisterMapperInterface, 1: SchemaMapperInterface, 2: IAppConfig} The sources.
	 */
	private function sources(): array {
		$register = new Register();
		$register->setAuthorization(
			[
				'read' => ['public', 'Behandelaars', ['group' => 'Lezers'], ['group' => 5], 7],
				'public' => true,
				'roles' => ['editor' => ['Redactie', 'admin'], 'viewer' => 'Lezers'],
			]
		);
		$empty = new Register();
		$empty->setAuthorization(null);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturn([$register, $empty]);

		$schema = new Schema();
		$schema->setAuthorization(['update' => ['  Redactie  ', '', 'authenticated', 'unmakeable']]);
		$schema->setProperties(['title' => ['type' => 'string']]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$schema]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn(
			['declared_groups_dossiq', 'declared_groups_broken', 'declared_groups_scalar', 'other_key']
		);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key): string {
				return [
					'declared_groups_dossiq' => '["Dossierbeheer", "public", " Behandelaars "]',
					'declared_groups_broken' => '{not json',
					'declared_groups_scalar' => '"Loose"',
					'other_key' => '["Never"]',
				][$key];
			}
		);

		return [$registers, $schemas, $config];
	}//end sources()

	/**
	 * The declared set, in order, with reserved and malformed names refused.
	 *
	 * @return void
	 */
	public function testTheDeclaredSetIsPinned(): void {
		[$registers, $schemas, $config] = $this->sources();

		$declared = $this->reconciler($registers, $schemas, $config, [])->collectDeclared();

		$this->assertSame(
			['Behandelaars', 'Lezers', 'Redactie', 'unmakeable', 'Dossierbeheer'],
			$declared
		);
	}//end testTheDeclaredSetIsPinned()

	/**
	 * The sweep creates only missing declared groups and reports them.
	 *
	 * @return void
	 */
	public function testTheSweepIsPinned(): void {
		[$registers, $schemas, $config] = $this->sources();

		$result = $this->reconciler($registers, $schemas, $config, ['Lezers'])->reconcile();

		$this->assertSame(
			[
				'declared' => ['Behandelaars', 'Lezers', 'Redactie', 'unmakeable', 'Dossierbeheer'],
				'created' => ['Behandelaars', 'Redactie', 'Dossierbeheer'],
			],
			$result
		);
		$this->assertSame(['Behandelaars', 'Redactie', 'Dossierbeheer'], $this->created);
	}//end testTheSweepIsPinned()

	/**
	 * Nothing declared creates nothing.
	 *
	 * @return void
	 */
	public function testNothingDeclaredCreatesNothing(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturn([]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturn([]);

		$result = $this->reconciler($registers, $schemas, $config, [])->reconcile();

		$this->assertSame(['declared' => [], 'created' => []], $result);
		$this->assertSame([], $this->created);
	}//end testNothingDeclaredCreatesNothing()

	/**
	 * A source that fails stops the sweep before anything is created.
	 *
	 * @return void
	 */
	public function testAFailingSourceCreatesNothing(): void {
		[, $schemas, $config] = $this->sources();
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willThrowException(new RuntimeException('db down'));

		$result = $this->reconciler($registers, $schemas, $config, [])->reconcile();

		$this->assertSame(['declared' => [], 'created' => []], $result);
		$this->assertSame([], $this->created);
	}//end testAFailingSourceCreatesNothing()

	/**
	 * Live registers and schemas are read without RBAC or tenancy, so a group
	 * declared on any tenant's schema is provisioned.
	 *
	 * @return void
	 */
	public function testTheLiveReadsBypassRbacAndTenancy(): void {
		[, , $config] = $this->sources();
		$seen = [];
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen['registers'] = array_slice($args, -2);
				return [];
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen['schemas'] = array_slice($args, -2);
				return [];
			}
		);

		$this->reconciler($registers, $schemas, $config, [])->collectDeclared();

		$this->assertSame(['registers' => [false, false], 'schemas' => [false, false]], $seen);
	}//end testTheLiveReadsBypassRbacAndTenancy()
}//end class
