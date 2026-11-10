<?php

/**
 * A configuration import writes its schemas with an unlinkable notification url action as a warning.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 */
final class ImportHandlerUnlinkableActionsWarnTest extends TestCase {

	/**
	 * The modes the mapper was set to, in order, with the write in between.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	private function handler(bool $wasWarning=false): ImportHandler {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('warnOnUnlinkableNotificationActions')->willReturnCallback(
			function () use ($wasWarning): bool {
				$this->calls[] = 'warn';
				return $wasWarning;
			}
		);
		$mapper->method('refuseUnlinkableNotificationActions')->willReturnCallback(
			function (): void {
				$this->calls[] = 'refuse';
			}
		);
		$handler = (new ReflectionClass(ImportHandler::class))->newInstanceWithoutConstructor();
		(function (SchemaMapperInterface $mapper): void {
			$this->schemaMapper = $mapper;
		})->call($handler, $mapper);

		return $handler;
	}//end handler()

	private function write(ImportHandler $handler, callable $save): Schema {
		return (fn (callable $save): Schema => $this->withUnlinkableActionsAsWarnings(save: $save))->call($handler, $save);
	}//end write()

	public function testTheWriteRunsWithWarningsAndTheSettingComesBack(): void {
		$schema = new Schema();
		$stored = $this->write($this->handler(), function () use ($schema): Schema {
			$this->calls[] = 'write';
			return $schema;
		});

		$this->assertSame($schema, $stored);
		$this->assertSame(['warn', 'write', 'refuse'], $this->calls);
	}//end testTheWriteRunsWithWarningsAndTheSettingComesBack()

	public function testAFailedWriteStillRestoresTheSetting(): void {
		try {
			$this->write($this->handler(), function (): Schema {
				$this->calls[] = 'write';
				throw new RuntimeException('refused');
			});
			$this->fail('The failure was swallowed.');
		} catch (RuntimeException $e) {
			$this->assertSame('refused', $e->getMessage());
		}

		$this->assertSame(['warn', 'write', 'refuse'], $this->calls);
	}//end testAFailedWriteStillRestoresTheSetting()

	public function testAnOuterWarningIsLeftInPlace(): void {
		$this->write($this->handler(wasWarning: true), fn (): Schema => new Schema());

		$this->assertSame(['warn'], $this->calls);
	}//end testAnOuterWarningIsLeftInPlace()

	public function testImportSchemaWritesThroughTheWarningScope(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Configuration/ImportHandler.php');
		preg_match('/private function importSchemaDefinition\(.*?\/\/end importSchemaDefinition\(\)/s', $source, $body);

		$this->assertSame(2, substr_count($body[0] ?? '', 'withUnlinkableActionsAsWarnings(save: fn (): Schema => $this->'));
		$this->assertStringNotContainsString('return $this->createImportedSchema(', $body[0] ?? '');
		$this->assertStringNotContainsString('return $this->updateImportedSchema(', $body[0] ?? '');
	}//end testImportSchemaWritesThroughTheWarningScope()
}//end class
