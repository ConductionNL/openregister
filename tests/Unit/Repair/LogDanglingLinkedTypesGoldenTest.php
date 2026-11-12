<?php

/**
 * Golden test for LogDanglingLinkedTypes::run().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- test doubles.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- test doubles.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\LogDanglingLinkedTypes;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Db\Entity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins every output and log line run() produces for a fixed set of schema shapes.
 *
 * @covers \OCA\OpenRegister\Repair\LogDanglingLinkedTypes
 */
class LogDanglingLinkedTypesGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	private function act(mixed $mapper): array {
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->method('listIds')->willReturn(['known', 'other']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper): mixed {
				if ($id !== 'OCA\\OpenRegister\\Db\\SchemaMapper' || $mapper instanceof RuntimeException) {
					throw new RuntimeException('no mapper');
				}

				return $mapper;
			}
		);

		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				$this->sink[] = ['log', $level, (string) $message, array_keys($context)];
			}
		};

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = ['info', $message];
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = ['warning', $message];
			}
		);

		(new LogDanglingLinkedTypes($registry, $container, $logger))->run($output);
		return $this->trace;
	}

	private function mapper(mixed $result): object {
		return new class($result) {
			public function __construct(private mixed $result) {
			}

			public function findAll(): mixed {
				return $this->result;
			}
		};
	}

	private const START = ['info', '[OpenRegister] Scanning schemas for dangling linkedTypes...'];

	private const SKIPPED = ['info', '[OpenRegister] Schema mapper unavailable — scan skipped (this is normal on first install).'];

	private const CLEAN = ['info', '[OpenRegister] All schemas linkedTypes are covered by registered integrations.'];

	private function dangling(string $slug, string $id, string $type): array {
		$message = '[OpenRegister] Schema "' . $slug . '" (id=' . $id . ') declares linkedType "' . $type . '"'
			. ' which is not registered. Add the matching IntegrationProvider'
			. ' before the legacy linked-type fallback is removed.';
		return [['log', 'warning', $message, []], ['warning', $message]];
	}

	public function testMapperUnavailable(): void {
		$this->assertSame(
			[
				self::START,
				['log', 'debug', '[OpenRegister] LogDanglingLinkedTypes could not load schemas — skipping scan', ['exception']],
				self::SKIPPED,
			],
			$this->act(new RuntimeException())
		);
	}

	public function testMapperWithoutFindAll(): void {
		$this->assertSame([self::START, self::SKIPPED], $this->act(new \stdClass()));
	}

	public function testFindAllNotAnArray(): void {
		$this->assertSame([self::START, self::SKIPPED], $this->act($this->mapper('nope')));
	}

	public function testNoSchemas(): void {
		$this->assertSame([self::START, self::CLEAN], $this->act($this->mapper([])));
	}

	public function testAllCovered(): void {
		$schema = new class {
			public function getLinkedTypes(): array {
				return ['known', 7, 'other'];
			}

			public function getSlug(): string {
				return 's';
			}
		};
		$this->assertSame([self::START, self::CLEAN], $this->act($this->mapper([$schema, 'not-an-object', null])));
	}

	public function testEveryShape(): void {
		$schemas = [
			// Direct linkedTypes, slug and int id.
			new class {
				public function getLinkedTypes(): array {
					return ['known', 'ghost', 3, 'phantom'];
				}

				public function getSlug(): string {
					return 'first';
				}

				public function getId(): int {
					return 42;
				}
			},
			// A throwing getLinkedTypes: configuration used; empty slug falls to name; id throws, uuid used.
			new class {
				public function getLinkedTypes(): array {
					throw new RuntimeException('x');
				}

				public function getConfiguration(): array {
					return ['linkedTypes' => ['cfg-type']];
				}

				public function getSlug(): string {
					return '';
				}

				public function getName(): string {
					return 'second-name';
				}

				public function getId(): int {
					throw new RuntimeException('x');
				}

				public function getUuid(): string {
					return 'uuid-2';
				}
			},
			// A non-array getLinkedTypes; configuration linkedTypes not an array: skipped.
			new class {
				public function getLinkedTypes(): string {
					return 'x';
				}

				public function getConfiguration(): array {
					return ['linkedTypes' => 'ghost'];
				}
			},
			// No accessors at all except configuration: slug unknown, id empty.
			new class {
				public function getConfiguration(): array {
					return ['linkedTypes' => ['lonely']];
				}
			},
			// Non-string, non-int accessor values are ignored.
			new class {
				public function getConfiguration(): array {
					return ['linkedTypes' => ['floaty']];
				}

				public function getSlug(): float {
					return 1.5;
				}

				public function getName(): string {
					return 'name-wins';
				}

				public function getId(): string {
					return '';
				}
			},
			// An Entity whose slug is only reachable through the magic getter.
			new class extends Entity {
				protected ?string $slug = 'entity-slug';

				public function getLinkedTypes(): array {
					return ['entity-type'];
				}
			},
			// Configuration that is not an array.
			new class {
				public function getConfiguration(): ?array {
					return null;
				}
			},
		];

		$this->assertSame(
			array_merge(
				[self::START],
				$this->dangling('first', '42', 'ghost'),
				$this->dangling('first', '42', 'phantom'),
				$this->dangling('second-name', 'uuid-2', 'cfg-type'),
				$this->dangling('unknown', '', 'lonely'),
				$this->dangling('name-wins', '', 'floaty'),
				$this->dangling('entity-slug', '', 'entity-type')
			),
			$this->act($this->mapper($schemas))
		);
	}
}
