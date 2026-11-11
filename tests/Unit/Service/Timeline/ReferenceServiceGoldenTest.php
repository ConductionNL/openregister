<?php

/**
 * Golden test for ReferenceService.
 *
 * Records, per case, every call the service makes on its mappers, the object
 * lookup and the logger, plus what it returns or throws: pattern declarations
 * (new, rewritten, refused), scans (several patterns, an unusable one, repeats,
 * the match cap, a failing lookup) and recording. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Db\EntryReference;
use OCA\OpenRegister\Notifications\Db\EntryReferenceMapper;
use OCA\OpenRegister\Notifications\Db\ReferencePattern;
use OCA\OpenRegister\Notifications\Db\ReferencePatternMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceSource;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\OptionalPayloadString
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\ReferenceSource
 * @uses   \OCA\OpenRegister\Notifications\Db\EntryReference
 * @uses   \OCA\OpenRegister\Notifications\Db\ReferencePattern
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ReferenceServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/reference-service-golden.json';

	private array $log = [];

	public function testReferenceServiceMatchesGolden(): void {
		$full = ['slug' => ' Zaak ', 'pattern' => 'Z-\d+', 'title' => ' Zaken ', 'register' => 'r', 'schema' => '', 'targetProperty' => 5, 'urlTemplate' => '/z/{code}', 'enabled' => false];
		$actual = [
			'declare new'          => $this->runCase(patterns: [], act: fn (ReferenceService $s) => $s->declarePattern($full)),
			'declare existing'     => $this->runCase(patterns: ['zaak'], act: fn (ReferenceService $s) => $s->declarePattern(['slug' => 'zaak', 'pattern' => 'a/b'])),
			'declare no slug'      => $this->runCase(patterns: [], act: fn (ReferenceService $s) => $s->declarePattern(['slug' => '  ', 'pattern' => 'x'])),
			'declare no pattern'   => $this->runCase(patterns: [], act: fn (ReferenceService $s) => $s->declarePattern(['slug' => 'a', 'pattern' => ' '])),
			'declare not compiled' => $this->runCase(patterns: [], act: fn (ReferenceService $s) => $s->declarePattern(['slug' => 'a', 'pattern' => '(x'])),
			'scan empty text'      => $this->runCase(patterns: ['zaak'], act: fn (ReferenceService $s) => $s->scan('  ')),
			'scan null text'       => $this->runCase(patterns: ['zaak'], act: fn (ReferenceService $s) => $s->scan(null)),
			'scan mixed'           => $this->runCase(patterns: ['broken', 'zaak', 'num'], act: fn (ReferenceService $s) => $s->scan('Z-1 en Z-2, Z-1 weer, 0 en 7 en Z-404 en Z-500')),
			'scan cap'             => $this->runCase(patterns: ['num', 'broken'], act: fn (ReferenceService $s) => $s->scan(implode(' ', range(1, 30)))),
			'scan encoded url'     => $this->runCase(patterns: ['spaced'], act: fn (ReferenceService $s) => $s->scan('zie ZK 12/a')),
			'record'               => $this->runCase(patterns: ['zaak', 'num'], act: fn (ReferenceService $s) => $s->record(new ReferenceSource('entry-1', 'source-1', 'Z-1, Z-404 en 3'))),
			'record nothing'       => $this->runCase(patterns: ['zaak'], act: fn (ReferenceService $s) => $s->record(new ReferenceSource('entry-1', 'source-1', null))),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testReferenceServiceMatchesGolden()

	private function runCase(array $patterns, callable $act): array {
		$this->log = [];
		$service = new ReferenceService(
			$this->patternMapper(slugs: $patterns),
			$this->referenceMapper(),
			$this->objects(),
			$this->logger()
		);
		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $act($service));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage() . ' ' . json_encode(method_exists($e, 'getErrors') === true ? $e->getErrors() : null);
		}

		$outcome['calls'] = $this->log;
		return $outcome;
	}//end runCase()

	private function pattern(string $slug): ReferencePattern {
		$bodies = ['zaak' => 'Z-\d+', 'num' => '\b\d+\b', 'broken' => '(unclosed', 'spaced' => 'ZK \d+'];
		$pattern = new ReferencePattern();
		$pattern->setId(array_search($slug, array_keys($bodies), true) + 1);
		$pattern->setSlug($slug);
		$pattern->setPattern($bodies[$slug]);
		$pattern->setRegister('reg-' . $slug);
		$pattern->setSchema('schema-' . $slug);
		if ($slug === 'zaak' || $slug === 'spaced') {
			$pattern->setUrlTemplate('/z/{code}?q=a b');
		}

		$pattern->resetUpdatedFields();
		return $pattern;
	}//end pattern()

	private function patternMapper(array $slugs): ReferencePatternMapper {
		$mapper = $this->createMock(ReferencePatternMapper::class);
		$all = array_map(fn (string $slug): ReferencePattern => $this->pattern(slug: $slug), $slugs);
		$mapper->method('findAll')->willReturnCallback(
			function (bool $enabledOnly = false) use ($all): array {
				$this->log[] = 'patterns.findAll(' . var_export($enabledOnly, true) . ')';
				return $all;
			}
		);
		$mapper->method('findBySlug')->willReturnCallback(
			function (string $slug) use ($all): ?ReferencePattern {
				$this->log[] = 'patterns.findBySlug(' . $slug . ')';
				foreach ($all as $pattern) {
					if ($pattern->getSlug() === $slug) {
						return $pattern;
					}
				}

				return null;
			}
		);
		$mapper->method('insert')->willReturnCallback(fn (Entity $e): Entity => $this->stored(what: 'patterns.insert', entity: $e));
		$mapper->method('update')->willReturnCallback(fn (Entity $e): Entity => $this->stored(what: 'patterns.update', entity: $e));
		return $mapper;
	}//end patternMapper()

	private function referenceMapper(): EntryReferenceMapper {
		$mapper = $this->createMock(EntryReferenceMapper::class);
		$mapper->method('deleteForEntry')->willReturnCallback(
			function (string $entryUuid): int {
				$this->log[] = 'references.deleteForEntry(' . $entryUuid . ')';
				return 2;
			}
		);
		$mapper->method('insert')->willReturnCallback(fn (Entity $e): Entity => $this->stored(what: 'references.insert', entity: $e));
		return $mapper;
	}//end referenceMapper()

	private function objects(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args): ?ObjectEntity {
				$args        = FindCallShape::find(args: $args);
				$this->log[] = 'objects.find(' . json_encode($args) . ')';
				$code = (string)($args['id'] ?? $args[0]);
				if (str_ends_with($code, '404') === true || $code === '7') {
					return null;
				}

				if (str_ends_with($code, '500') === true) {
					throw new RuntimeException('lookup failed');
				}

				$object = new ObjectEntity();
				$object->setUuid('uuid-' . $code);
				return $object;
			}
		);
		return $objects;
	}//end objects()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'logger.warning(' . $message . ')';
			}
		);
		return $logger;
	}//end logger()

	private function stored(string $what, Entity $entity): Entity {
		$fields = [];
		foreach (array_keys($entity->getUpdatedFields()) as $field) {
			$value = $entity->{'get' . ucfirst($field)}();
			$fields[$field] = is_object($value) === true ? get_class($value) : $value;
			if ($field === 'uuid') {
				$fields[$field] = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$value) === 1 ? 'uuid v4' : $value;
			}
		}

		$this->log[] = $what . ' ' . json_encode($fields, JSON_UNESCAPED_SLASHES);
		return $entity;
	}//end stored()

	private function render(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->render(value: $item), $value);
		}

		if ($value instanceof ReferencePattern === true) {
			return 'pattern ' . $value->getSlug() . ' ' . json_encode([$value->getTitle(), $value->getPattern(), $value->getRegister(), $value->getSchema(), $value->getTargetProperty(), $value->getUrlTemplate(), $value->getEnabled(), $value->getUuid() !== null]);
		}

		if ($value instanceof EntryReference === true) {
			return 'reference ' . json_encode([$value->getEntryUuid(), $value->getSourceUuid(), $value->getTargetUuid(), $value->getCode(), $value->getPatternSlug(), $value->getUrl()]);
		}

		return $value;
	}//end render()
}//end class
