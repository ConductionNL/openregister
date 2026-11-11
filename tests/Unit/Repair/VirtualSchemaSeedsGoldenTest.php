<?php

/**
 * Characterisation: every catalog call, payload, output line and log line of
 * the app and directory virtual schema seeds, pinned before they share their
 * register handling.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Repair\SeedAppVirtualSchemas;
use OCA\OpenRegister\Repair\SeedDirectoryVirtualSchemas;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs both seeds over recording doubles and compares the call log with a golden file.
 */
class VirtualSchemaSeedsGoldenTest extends TestCase {

	/**
	 * Every call the seed made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Run one seed over recording doubles.
	 *
	 * @param string $step     `app` or `directory`.
	 * @param string $scenario `fresh`, `seeded` or `failing`.
	 *
	 * @return array<int, string> The call log.
	 */
	private function record(string $step, string $scenario): array {
		$this->calls = [];
		$nextId = 100;

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string $slug) use ($scenario): Register {
				$this->calls[] = 'registers.find ' . $slug;
				if ($scenario === 'failing') {
					throw new RuntimeException('database gone');
				}

				if ($scenario === 'fresh') {
					throw new RuntimeException('not found');
				}

				$register = new Register();
				$register->setSlug($slug);
				$register->setSchemas([100, '101']);
				return $register;
			}
		);
		$registers->method('createFromArray')->willReturnCallback(
			function (array $object) use ($scenario): Register {
				$this->calls[] = 'registers.create ' . json_encode($object);
				if ($scenario === 'failing') {
					throw new RuntimeException('cannot create');
				}

				$register = new Register();
				$register->setSlug($object['slug']);
				$register->setSchemas($object['schemas']);
				return $register;
			}
		);
		$registers->method('update')->willReturnCallback(
			function (Register $register): Register {
				$this->calls[] = 'registers.update ' . $register->getSlug() . ' ' . json_encode($register->getSchemas());
				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string $slug) use ($scenario, &$nextId): Schema {
				$this->calls[] = 'schemas.find ' . $slug;
				if ($scenario !== 'seeded') {
					throw new RuntimeException('not found');
				}

				$schema = new Schema();
				$schema->setId($nextId++);
				$schema->setSlug($slug);
				$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'p', 'readOnly' => true]]);
				return $schema;
			}
		);
		$schemas->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$nextId): Schema {
				$this->calls[] = 'schemas.create ' . json_encode($object);
				$schema = new Schema();
				$schema->setId($nextId++);
				$schema->setSlug($object['slug']);
				return $schema;
			}
		);
		$schemas->method('update')->willReturnCallback(
			function (Schema $schema): Schema {
				$this->calls[] = 'schemas.update ' . $schema->getSlug() . ' ' . json_encode($schema->getConfiguration());
				return $schema;
			}
		);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($registers);
		$catalog->method('schemas')->willReturn($schemas);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = 'log.' . $level . ' ' . $message;
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = 'output.' . $level . ' ' . $message;
				}
			);
		}

		if ($step === 'app') {
			(new SeedAppVirtualSchemas(catalog: $catalog, logger: $logger))->run($output);
		} else {
			(new SeedDirectoryVirtualSchemas(catalog: $catalog, logger: $logger))->run($output);
		}

		return $this->calls;
	}//end record()

	/**
	 * Every step and scenario.
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['app', 'directory'] as $step) {
			foreach (['fresh', 'seeded', 'failing'] as $scenario) {
				$cases[$step . '-' . $scenario] = [$step, $scenario];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The call log matches the golden one.
	 *
	 * @param string $step     `app` or `directory`.
	 * @param string $scenario `fresh`, `seeded` or `failing`.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheSeedMakesTheGoldenCalls(string $step, string $scenario): void {
		$golden = json_decode(
			(string)file_get_contents(__DIR__ . '/fixtures/virtual-schema-seeds-golden.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame($golden[$step . '-' . $scenario], $this->record($step, $scenario));
	}//end testTheSeedMakesTheGoldenCalls()
}//end class
