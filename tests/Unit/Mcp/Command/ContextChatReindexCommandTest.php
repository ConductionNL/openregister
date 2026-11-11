<?php

/**
 * ContextChatReindexCommand unit tests.
 *
 * Drives the `openregister:contextchat:reindex` command through Symfony's
 * public run() entry point: scope-option resolution, the not-found refusals,
 * the lazy ContentProvider resolve and the reindex failure path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Command;

use OCA\OpenRegister\ContextChat\ContentProviderInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Mcp\Command\ContextChatReindexCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \OCA\OpenRegister\Mcp\Command\ContextChatReindexCommand
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ContextChatReindexCommandTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private RegisterMapperInterface&MockObject $registerMapper;

	private SchemaMapperInterface&MockObject $schemaMapper;

	private ContentProviderInterface&MockObject $contentProvider;

	private ContextChatReindexCommand $command;

	/**
	 * Build the command with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container       = $this->createMock(ContainerInterface::class);
		$this->registerMapper  = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper    = $this->createMock(SchemaMapperInterface::class);
		$this->contentProvider = $this->createMock(ContentProviderInterface::class);
		$this->command         = new ContextChatReindexCommand(
			$this->container,
			new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->createMock(MagicMapperInterface::class))
		);
	}//end setUp()

	/**
	 * Run the command with the given options.
	 *
	 * @param array<string, string> $options Option map, e.g. ['--register' => 'x'].
	 *
	 * @return array{0: int, 1: string} Exit code and captured output.
	 */
	private function runCommand(array $options): array {
		$output = new BufferedOutput();
		$code   = $this->command->run(new ArrayInput($options), $output);

		return [$code, $output->fetch()];
	}//end runCommand()

	/**
	 * A register entity carrying the given id.
	 *
	 * @param int $id Id.
	 *
	 * @return Register The entity.
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);

		return $register;
	}//end register()

	/**
	 * A schema entity carrying the given id.
	 *
	 * @param int $id Id.
	 *
	 * @return Schema The entity.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	/**
	 * The command registers its name and both scope options.
	 *
	 * @return void
	 */
	public function testConfigureDeclaresNameAndScopeOptions(): void {
		$this->assertSame('openregister:contextchat:reindex', $this->command->getName());
		$this->assertTrue($this->command->getDefinition()->hasOption('register'));
		$this->assertTrue($this->command->getDefinition()->hasOption('schema'));
	}//end testConfigureDeclaresNameAndScopeOptions()

	/**
	 * Without options, the provider is resolved lazily and reindexes everything.
	 *
	 * @return void
	 */
	public function testUnscopedRunReindexesEverything(): void {
		$this->registerMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('find');
		$this->container->expects($this->once())->method('get')
			->with(ContentProviderInterface::class)
			->willReturn($this->contentProvider);
		$this->contentProvider->expects($this->once())->method('reindex')
			->with(null, null)
			->willReturn(7);

		[$code, $text] = $this->runCommand([]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Submitted 7 object(s).', $text);
	}//end testUnscopedRunReindexesEverything()

	/**
	 * Register and schema references are resolved to ids without RBAC/multitenancy.
	 *
	 * @return void
	 */
	public function testScopedRunPassesResolvedIds(): void {
		$this->registerMapper->expects($this->once())->method('find')
			->with('my-register', false, false)
			->willReturn($this->register(12));
		$this->schemaMapper->expects($this->once())->method('find')
			->with('my-schema', [], false, false)
			->willReturn($this->schema(34));
		$this->container->method('get')->willReturn($this->contentProvider);
		$this->contentProvider->expects($this->once())->method('reindex')
			->with(12, 34)
			->willReturn(0);

		[$code, $text] = $this->runCommand(['--register' => 'my-register', '--schema' => 'my-schema']);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Submitted 0 object(s).', $text);
	}//end testScopedRunPassesResolvedIds()

	/**
	 * An unknown register aborts before the provider is ever loaded.
	 *
	 * @return void
	 */
	public function testUnknownRegisterFailsBeforeReindex(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('nope-reg'));
		$this->schemaMapper->expects($this->never())->method('find');
		$this->container->expects($this->never())->method('get');

		[$code, $text] = $this->runCommand(['--register' => 'ghost']);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Register not found: nope-reg', $text);
	}//end testUnknownRegisterFailsBeforeReindex()

	/**
	 * An unknown schema aborts before the provider is ever loaded.
	 *
	 * @return void
	 */
	public function testUnknownSchemaFailsBeforeReindex(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('nope-schema'));
		$this->container->expects($this->never())->method('get');

		[$code, $text] = $this->runCommand(['--schema' => 'ghost']);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Schema not found: nope-schema', $text);
	}//end testUnknownSchemaFailsBeforeReindex()

	/**
	 * A reindex that throws is reported as a failure, not a success.
	 *
	 * @return void
	 */
	public function testReindexExceptionIsReportedAsFailure(): void {
		$this->container->method('get')->willReturn($this->contentProvider);
		$this->contentProvider->method('reindex')->willThrowException(new RuntimeException('backend down'));

		[$code, $text] = $this->runCommand([]);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Reindex failed: backend down', $text);
		$this->assertStringNotContainsString('Submitted', $text);
	}//end testReindexExceptionIsReportedAsFailure()

	/**
	 * A container that cannot resolve the provider is reported as a failure.
	 *
	 * @return void
	 */
	public function testUnresolvableProviderIsReportedAsFailure(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no provider'));

		[$code, $text] = $this->runCommand([]);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Reindex failed: no provider', $text);
	}//end testUnresolvableProviderIsReportedAsFailure()
}//end class
