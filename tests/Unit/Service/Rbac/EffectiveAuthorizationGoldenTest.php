<?php

/**
 * Golden test for EffectiveAuthorization::forSchema() role expansion.
 *
 * Pins, on the code before expandRoles() was split, the effective block for
 * every branch: a schema block without roles, a non-array roles value, a
 * register that cannot be found or read, missing or empty role definitions,
 * malformed definitions, unknown roles, scalar group lists, de-duplication,
 * merging into an existing action list, and the register fallback. A refused
 * outcome here is the "grant nothing" block (every verb an empty list).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Rbac\EffectiveAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 */
final class EffectiveAuthorizationGoldenTest extends TestCase {

	private const NOTHING = ['read' => [], 'create' => [], 'update' => [], 'delete' => []];

	/**
	 * A plain object answering getId/getAuthorization/getConfiguration.
	 *
	 * @param mixed $authorization What getAuthorization() returns.
	 * @param mixed $configuration What getConfiguration() returns.
	 *
	 * @return object
	 */
	private static function entity(mixed $authorization, mixed $configuration = null): object {
		return new class($authorization, $configuration) {
			/**
			 * @param mixed $authorization Authorization.
			 * @param mixed $configuration Configuration.
			 */
			public function __construct(private mixed $authorization, private mixed $configuration) {
			}

			/**
			 * @return integer
			 */
			public function getId(): int {
				return 5;
			}

			/**
			 * @return mixed
			 */
			public function getAuthorization(): mixed {
				return $this->authorization;
			}

			/**
			 * @return mixed
			 */
			public function getConfiguration(): mixed {
				return $this->configuration;
			}
		};
	}//end entity()

	/**
	 * A register answering getAuthorization/getConfiguration as given.
	 *
	 * @param mixed $authorization What getAuthorization() returns.
	 * @param array $configuration What getConfiguration() returns.
	 *
	 * @return Register
	 */
	private static function register(mixed $authorization, array $configuration): Register {
		return new class($authorization, $configuration) extends Register {
			/**
			 * @param mixed $stubAuthorization Authorization.
			 * @param array $stubConfiguration Configuration.
			 */
			public function __construct(private mixed $stubAuthorization, private array $stubConfiguration) {
				parent::__construct();
			}

			/**
			 * @return mixed
			 */
			public function getAuthorization(): mixed {
				return $this->stubAuthorization;
			}

			/**
			 * @return array
			 */
			public function getConfiguration(): array {
				return $this->stubConfiguration;
			}
		};
	}//end register()

	/**
	 * Scenarios: schema block, register lookup mode, register auth, register config, expected.
	 *
	 * Lookup modes: 'none' (no register holds the schema), 'throws' (lookup fails),
	 * 'find-throws' (find fails), 'found' (the register is returned).
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$defs = [
			['name' => 'viewer', 'actions' => ['read']],
			['name' => 'editor', 'actions' => ['read', 'update', 'create']],
			['name' => 'nameless-actions'],
			['actions' => ['delete']],
			'not-an-array',
			['name' => 'remover', 'actions' => ['delete']],
		];

		return [
			'schema block without roles is returned as is' => [
				['read' => ['public'], 'update' => ['staff']], 'throws', null, null,
				['read' => ['public'], 'update' => ['staff']],
			],
			'non-array roles is left in place' => [
				['roles' => 'viewer', 'read' => ['x']], 'throws', null, null,
				['roles' => 'viewer', 'read' => ['x']],
			],
			'null roles is left in place' => [
				['roles' => null, 'read' => ['x']], 'throws', null, null,
				['roles' => null, 'read' => ['x']],
			],
			'no register holds the schema grants nothing' => [
				['roles' => ['viewer' => ['staff']]], 'none', null, null, self::NOTHING,
			],
			'no register keeps the rest of the block' => [
				['roles' => ['viewer' => ['staff']], 'read' => ['public']], 'none', null, null, ['read' => ['public']],
			],
			'lookup failure grants nothing' => [
				['roles' => ['viewer' => ['staff']]], 'throws', null, null, self::NOTHING,
			],
			'find failure grants nothing' => [
				['roles' => ['viewer' => ['staff']]], 'find-throws', null, null, self::NOTHING,
			],
			'configuration without roles grants nothing' => [
				['roles' => ['viewer' => ['staff']]], 'found', null, null, self::NOTHING,
			],
			'empty role definitions grant nothing' => [
				['roles' => ['viewer' => ['staff']]], 'found', null, ['roles' => []], self::NOTHING,
			],
			'roles expand, malformed definitions and unknown roles skipped' => [
				[
					'roles' => [
						'viewer' => ['staff', 'guests'],
						'editor' => 'editors',
						'nameless-actions' => ['x'],
						'ghost' => ['y'],
						'remover' => ['staff', 'staff'],
					],
					'read' => ['public', 'staff'],
				],
				'found', null, ['roles' => $defs],
				[
					'read' => ['public', 'staff', 'guests', 'editors'],
					'update' => ['editors'],
					'create' => ['editors'],
					'delete' => ['staff'],
				],
			],
			'definitions that name nothing assigned grant nothing' => [
				['roles' => ['ghost' => ['y']]], 'found', null, ['roles' => $defs], self::NOTHING,
			],
			'a role with no groups yields an empty action list' => [
				['roles' => ['viewer' => []]], 'found', null, ['roles' => $defs], ['read' => []],
			],
			'register fallback, with its own role definitions' => [
				null, 'found', ['roles' => ['viewer' => 'staff'], 'delete' => ['admin']], ['roles' => $defs],
				['delete' => ['admin'], 'read' => ['staff']],
			],
			'register fallback for an empty schema block' => [
				[], 'found', ['read' => ['public']], null, ['read' => ['public']],
			],
			'register fallback with roles but no definitions' => [
				null, 'found', ['roles' => ['viewer' => 'staff']], ['roles' => []], self::NOTHING,
			],
			'no schema block and an empty register block is null' => [
				null, 'found', [], null, null,
			],
			'no schema block and a non-array register block is null' => [
				null, 'found', 'x', null, null,
			],
			'no schema block and no register is null' => [
				null, 'none', null, null, null,
			],
			'no schema block and a failing lookup is null' => [
				null, 'throws', null, null, null,
			],
			'no schema block and a failing find is null' => [
				'not-an-array', 'find-throws', null, null, null,
			],
		];
	}//end scenarios()

	/**
	 * The effective block for each scenario.
	 *
	 * @param mixed      $schemaAuth     The schema's block.
	 * @param string     $lookup         Lookup mode.
	 * @param mixed      $registerAuth   The register's block.
	 * @param mixed      $registerConfig The register's configuration.
	 * @param array|null $expected       The effective block.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheEffectiveBlockIsPinned(
		mixed $schemaAuth,
		string $lookup,
		mixed $registerAuth,
		mixed $registerConfig,
		?array $expected
	): void {
		$mapper = $this->createMock(RegisterMapper::class);
		switch ($lookup) {
			case 'none':
				$mapper->method('getFirstRegisterWithSchema')->willReturn(null);
				$mapper->expects($this->never())->method('find');
				break;
			case 'throws':
				$mapper->method('getFirstRegisterWithSchema')->willThrowException(new RuntimeException('x'));
				break;
			case 'find-throws':
				$mapper->method('getFirstRegisterWithSchema')->willReturn(1);
				$mapper->method('find')->willThrowException(new RuntimeException('x'));
				break;
			default:
				$mapper->method('getFirstRegisterWithSchema')->willReturn(1);
				$mapper->method('find')->willReturn(self::register($registerAuth, ($registerConfig ?? [])));
		}

		$schema = self::entity($schemaAuth);
		$this->assertSame($expected, (new EffectiveAuthorization($mapper))->forSchema($schema));
	}//end testTheEffectiveBlockIsPinned()

	/**
	 * The register found for the fallback is the one whose definitions are read: one lookup, not two.
	 *
	 * @return void
	 */
	public function testTheFallbackRegisterIsLookedUpOnce(): void {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->expects($this->once())->method('getFirstRegisterWithSchema')->willReturn(1);
		$mapper->expects($this->once())->method('find')->willReturn(
			self::register(['roles' => ['viewer' => ['staff']]], ['roles' => [['name' => 'viewer', 'actions' => ['read']]]])
		);

		$this->assertSame(['read' => ['staff']], (new EffectiveAuthorization($mapper))->forSchema(self::entity(null)));
	}//end testTheFallbackRegisterIsLookedUpOnce()
}//end class
