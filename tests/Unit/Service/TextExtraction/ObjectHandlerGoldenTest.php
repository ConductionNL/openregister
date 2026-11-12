<?php

/**
 * ObjectHandler golden test.
 *
 * Records what the object text extraction handler returns, throws and logs for
 * a set of real object, schema and register entities, so a refactor of the
 * handler can be shown to keep its behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\ObjectHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\ObjectHandler
 */
class ObjectHandlerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-handler-golden.json';

	/**
	 * Calls made against the mappers, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$deep = ['leaf' => 'deep-value'];
		for ($i = 0; $i < 12; $i++) {
			$deep = ['level' . $i => $deep, 'at' . $i => $i];
		}

		$data = [
			'title' => 'Hello',
			'blank' => '   ',
			'empty' => '',
			'count' => 3,
			'ratio' => 1.5,
			'numericString' => '42',
			'yes' => true,
			'no' => false,
			'nothing' => null,
			'none' => [],
			'list' => ['a', ' ', 7, [], ['x' => 'y']],
			'nested' => ['inner' => ['deeper' => 'value', 'flag' => false], 'skip' => ''],
			'onlyEmpty' => ['a' => '', 'b' => []],
		];
		return [
			'full' => [
				'object' => ['uuid' => 'u-1', 'version' => '1.2.3', 'schema' => '5', 'register' => '3', 'owner' => 'alice', 'organisation' => 'Org', 'data' => $data],
				'schema' => ['title' => 'Person', 'description' => 'A person'],
				'register' => ['title' => 'People', 'description' => 'All people'],
			],
			'titles-missing' => [
				'object' => ['uuid' => 'u-2', 'schema' => '5', 'register' => '3', 'data' => ['k' => 'v']],
				'schema' => ['title' => null, 'description' => ''],
				'register' => ['title' => null, 'description' => null],
			],
			'lookups-fail' => [
				'object' => ['uuid' => 'u-3', 'schema' => '9', 'register' => '8', 'data' => []],
				'schema' => 'throw',
				'register' => 'throw',
			],
			'lookups-not-found' => [
				'object' => ['uuid' => 'u-4', 'schema' => '9', 'register' => '8', 'organisation' => ''],
				'schema' => 'missing',
				'register' => 'missing',
			],
			'bare' => ['object' => []],
			'deep' => ['object' => ['uuid' => 'u-5', 'data' => $deep]],
			'list-root' => ['object' => ['uuid' => 'u-6', 'data' => ['first', 'second', ['third']]]],
			'find-fails' => ['object' => 'throw'],
			'meta' => ['object' => ['uuid' => 'u-7', 'version' => '2', 'schema' => '5', 'register' => '3', 'owner' => 'bob', 'organisation' => 'Org', 'updated' => 1700000000], 'call' => 'meta'],
			'meta-fails' => ['object' => 'throw', 'call' => 'meta'],
			'timestamp' => ['object' => ['updated' => 1700000000], 'call' => 'timestamp'],
			'timestamp-no-updated' => ['object' => [], 'call' => 'timestamp'],
			'timestamp-missing' => ['object' => 'missing', 'call' => 'timestamp'],
			'timestamp-fails' => ['object' => 'throw', 'call' => 'timestamp'],
			'needs-forced' => ['latest' => 100, 'call' => 'needs', 'args' => [7, 200, true]],
			'needs-no-chunks' => ['latest' => null, 'call' => 'needs', 'args' => [7, 200, false]],
			'needs-stale' => ['latest' => 100, 'call' => 'needs', 'args' => [7, 200, false]],
			'needs-equal' => ['latest' => 200, 'call' => 'needs', 'args' => [7, 200, false]],
			'needs-fresh' => ['latest' => 300, 'call' => 'needs', 'args' => [7, 200, false]],
			'source-type' => ['call' => 'type'],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testObjectHandlerMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertNotEmpty($all[$name]['result']);
		$this->assertSame($all[$name], $recorded);
	}//end testObjectHandlerMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$this->calls = [];
		$lines = [];
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string)($context['file'] ?? ''));
				$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
			}
		};

		$objects = $this->objectMapper(scenario: $scenario);
		$schemas = $this->schemaMapper(spec: ($scenario['schema'] ?? null));
		$handler = new ObjectHandler(
			new TextExtractionObjects(
				new \OCA\OpenRegister\Db\SchemaCatalog($this->registerMapper(spec: ($scenario['register'] ?? null)), $schemas, $objects)
			),
			$this->chunkMapper(scenario: $scenario),
			$logger
		);

		$started = time();
		try {
			$result = ['returned' => $this->invoke(handler: $handler, scenario: $scenario)];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e) . ': ' . $e->getMessage()];
		}

		if (($scenario['call'] ?? null) === 'timestamp' && is_int($result['returned'] ?? null) === true && $result['returned'] >= $started && $result['returned'] <= time()) {
			$result['returned'] = 'now';
		}

		return ['result' => $result, 'calls' => $this->calls, 'log' => $lines];
	}//end record()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function invoke(ObjectHandler $handler, array $scenario): mixed {
		$call = ($scenario['call'] ?? 'extract');
		if ($call === 'meta') {
			$meta = $handler->getSourceMetadata(11);
			$meta['updated'] = $meta['updated']?->format('U');
			return $meta;
		}

		if ($call === 'timestamp') {
			return $handler->getSourceTimestamp(11);
		}

		if ($call === 'needs') {
			return $handler->needsExtraction(...$scenario['args']);
		}

		if ($call === 'type') {
			return $handler->getSourceType();
		}

		return $handler->extractText(11, ['ignored' => true]);
	}//end invoke()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function objectMapper(array $scenario): MagicMapper {
		$spec = ($scenario['object'] ?? []);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function ($identifier) use ($spec): ObjectEntity {
				$this->calls[] = 'object.find(' . json_encode($identifier) . ')';
				if ($spec === 'throw') {
					throw new RuntimeException('object store down');
				}

				if ($spec === 'missing') {
					throw new DoesNotExistException('no object');
				}

				return $this->object(spec: $spec);
			}
		);

		return $mapper;
	}//end objectMapper()

	/**
	 * @param array<string, mixed> $scenario
	 */
	private function chunkMapper(array $scenario): ChunkMapper {
		$mapper = $this->createMock(ChunkMapper::class);
		$mapper->method('getLatestUpdatedTimestamp')->willReturnCallback(
			function (string $sourceType, int $sourceId) use ($scenario): ?int {
				$this->calls[] = 'chunk.latest(' . $sourceType . ', ' . $sourceId . ')';
				return ($scenario['latest'] ?? null);
			}
		);

		return $mapper;
	}//end chunkMapper()

	/**
	 * @param array<string, mixed>|string|null $spec
	 */
	private function schemaMapper(array|string|null $spec): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			function ($id) use ($spec): Schema {
				$this->calls[] = 'schema.find(' . json_encode($id) . ')';
				$this->failWhen(spec: $spec);
				$schema = new Schema();
				$schema->setTitle($spec['title'] ?? null);
				$schema->setDescription($spec['description'] ?? null);
				return $schema;
			}
		);

		return $mapper;
	}//end schemaMapper()

	/**
	 * @param array<string, mixed>|string|null $spec
	 */
	private function registerMapper(array|string|null $spec): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function ($id) use ($spec): Register {
				$this->calls[] = 'register.find(' . json_encode($id) . ')';
				$this->failWhen(spec: $spec);
				$register = new Register();
				$register->setTitle($spec['title'] ?? null);
				$register->setDescription($spec['description'] ?? null);
				return $register;
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * @param array<string, mixed>|string|null $spec
	 */
	private function failWhen(array|string|null $spec): void {
		if ($spec === 'throw') {
			throw new RuntimeException('lookup broke');
		}

		if ($spec === 'missing') {
			throw new DoesNotExistException('not there');
		}
	}//end failWhen()

	/**
	 * @param array<string, mixed> $spec
	 */
	private function object(array $spec): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(11);
		$object->setUuid($spec['uuid'] ?? null);
		$object->setVersion($spec['version'] ?? null);
		$object->setSchema($spec['schema'] ?? null);
		$object->setRegister($spec['register'] ?? null);
		$object->setOwner($spec['owner'] ?? null);
		$object->setOrganisation($spec['organisation'] ?? null);
		$object->setObject($spec['data'] ?? []);
		if (isset($spec['updated']) === true) {
			$object->setUpdated((new DateTime('@' . $spec['updated']))->setTimezone(new DateTimeZone('UTC')));
		}

		return $object;
	}//end object()
}//end class
