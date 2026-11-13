<?php

/**
 * Tests which app owns each MCP opted-in schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\McpDiscoveryRun;
use OCA\OpenRegister\Mcp\McpSchemaOwnership;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only enabled schemas are grouped, under their own application or else their register's; unowned ones are skipped.
 *
 * @covers \OCA\OpenRegister\Mcp\McpSchemaOwnership
 * @uses \OCA\OpenRegister\Mcp\McpDiscoveryRun
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class McpSchemaOwnershipTest extends TestCase {

	/**
	 * The register mapper the discovery serves.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The ownership over the mocked register mapper.
	 *
	 * @return McpSchemaOwnership
	 */
	private function ownership(): McpSchemaOwnership {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(RegisterMapperInterface::class)->willReturn($this->registers);

		return new McpSchemaOwnership(run: new McpDiscoveryRun(container: $container, logger: $this->logger));
	}//end ownership()

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registers = $this->createMock(originalClassName: RegisterMapperInterface::class);
		$this->logger    = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A schema with that id, configuration and application.
	 *
	 * @param int                  $id            The id.
	 * @param array<string, mixed> $configuration The configuration.
	 * @param string|null          $application   The owning application.
	 *
	 * @return Schema
	 */
	private static function schema(int $id, array $configuration, ?string $application = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration($configuration);
		$schema->setApplication($application);

		return $schema;
	}//end schema()

	/**
	 * A register with that application.
	 *
	 * @param string|null $application The application.
	 *
	 * @return Register
	 */
	private static function register(?string $application): Register {
		$register = new Register();
		$register->setId(5);
		$register->setApplication($application);

		return $register;
	}//end register()

	/**
	 * Schemas without an enabled block, or with a malformed one, are not grouped and their register is not looked up.
	 *
	 * @return void
	 */
	public function testOnlyEnabledSchemasAreGrouped(): void {
		$this->registers->expects($this->never())->method('getFirstRegisterWithSchema');

		$grouped = $this->ownership()->groupOptedInSchemasByApp(
			[
				self::schema(1, [], 'pipelinq'),
				self::schema(2, ['x-openregister-mcp' => ['enabled' => false]], 'pipelinq'),
				self::schema(3, ['x-openregister-mcp' => ['enabled' => 'true']], 'pipelinq'),
				self::schema(4, ['x-openregister-mcp' => true], 'pipelinq'),
			]
		);

		$this->assertSame([], $grouped);
	}//end testOnlyEnabledSchemasAreGrouped()

	/**
	 * The schema's own application wins over its register's, and the register is paired with it.
	 *
	 * @return void
	 */
	public function testTheSchemasOwnApplicationWins(): void {
		$register = self::register('procest');
		$this->registers->method('getFirstRegisterWithSchema')->with(10)->willReturn(5);
		$this->registers->expects($this->once())->method('find')->with(5, false, false)->willReturn($register);
		$schema = self::schema(10, ['x-openregister-mcp' => ['enabled' => true]], 'pipelinq');

		$grouped = $this->ownership()->groupOptedInSchemasByApp([$schema]);

		$this->assertSame(['pipelinq'], array_keys($grouped));
		$this->assertSame($schema, $grouped['pipelinq'][0]['schema']);
		$this->assertSame($register, $grouped['pipelinq'][0]['register']);
	}//end testTheSchemasOwnApplicationWins()

	/**
	 * Without its own application a schema falls back to its register's, and schemas of one app are grouped together.
	 *
	 * @return void
	 */
	public function testTheRegistersApplicationIsTheFallback(): void {
		$this->registers->method('getFirstRegisterWithSchema')->willReturn(5);
		$this->registers->method('find')->willReturn(self::register('procest'));
		$first  = self::schema(11, ['x-openregister-mcp' => ['enabled' => true]], '');
		$second = self::schema(12, ['x-openregister-mcp' => ['enabled' => true]], null);

		$grouped = $this->ownership()->groupOptedInSchemasByApp([$first, $second]);

		$this->assertSame(['procest'], array_keys($grouped));
		$this->assertSame([$first, $second], array_column($grouped['procest'], 'schema'));
	}//end testTheRegistersApplicationIsTheFallback()

	/**
	 * An orphaned schema keeps a null register but still groups under its own application.
	 *
	 * @return void
	 */
	public function testAnOrphanedSchemaGroupsUnderItsOwnApplication(): void {
		$this->registers->method('getFirstRegisterWithSchema')->willReturn(null);
		$this->registers->expects($this->never())->method('find');

		$grouped = $this->ownership()->groupOptedInSchemasByApp([self::schema(13, ['x-openregister-mcp' => ['enabled' => true]], 'decidiq')]);

		$this->assertNull($grouped['decidiq'][0]['register']);
	}//end testAnOrphanedSchemaGroupsUnderItsOwnApplication()

	/**
	 * A schema with no owning app anywhere is skipped and logged; a failing register lookup counts as none.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAnOwningAppIsSkipped(): void {
		$this->registers->method('getFirstRegisterWithSchema')->willReturnCallback(
			static function (int $schemaId): ?int {
				if ($schemaId === 14) {
					throw new RuntimeException('table missing');
				}

				return 5;
			}
		);
		$this->registers->method('find')->willReturn(self::register(''));
		$messages = [];
		$this->logger->method('debug')->willReturnCallback(
			static function (string $message, array $context = []) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		$grouped = $this->ownership()->groupOptedInSchemasByApp(
			[
				self::schema(14, ['x-openregister-mcp' => ['enabled' => true]]),
				self::schema(15, ['x-openregister-mcp' => ['enabled' => true]]),
			]
		);

		$this->assertSame([], $grouped);
		$this->assertSame(
			[
				['[Application] Owning-register resolution failed for schema 14: table missing', []],
				['[Application] Opted-in schema has no resolvable owning app — skipped', ['schemaId' => 14]],
				['[Application] Opted-in schema has no resolvable owning app — skipped', ['schemaId' => 15]],
			],
			$messages
		);
	}//end testASchemaWithoutAnOwningAppIsSkipped()
}//end class
