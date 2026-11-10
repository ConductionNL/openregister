<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for MappingService::executeMapping().
 *
 * Each case runs one mapping recipe (rules, pass-through, unset, cast) on one
 * input, single or as a list; the recording holds the output, or the thrown
 * error. Compared with fixtures/mapping-execute-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\MappingService
 * @uses   \OCA\OpenRegister\Db\Mapping
 * @uses   \OCA\OpenRegister\Twig\MappingExtension
 * @uses   \OCA\OpenRegister\Twig\MappingRuntimeLoader
 * @uses   \OCA\OpenRegister\Twig\MappingRuntime
 */
class MappingServiceExecuteMappingGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mapping-execute-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<mixed>, 2: bool}>
	 */
	public static function cases(): array {
		$person = ['name' => 'Ada', 'age' => '36', 'address' => ['city' => 'Delft', 'zip' => '2611'], 'tags' => ['a', 'b']];

		return [
			'empty recipe' => [[], $person, false],
			'dot rule' => [['mapping' => ['city' => 'address.city', 'who' => 'name']], $person, false],
			'nested output key' => [['mapping' => ['out.inner.city' => 'address.city']], $person, false],
			'missing path renders as text' => [['mapping' => ['x' => 'not.there']], $person, false],
			'twig template' => [['mapping' => ['greeting' => 'Hi {{ name }} from {{ address.city }}']], $person, false],
			'twig entity decoded' => [['mapping' => ['t' => '{{ "a & b" }}']], $person, false],
			'entity in template text' => [['mapping' => ['t' => 'x &amp; y']], $person, false],
			'array rule value kept' => [['mapping' => ['fixed' => ['k' => 'v']]], $person, false],
			'numeric rule value' => [['mapping' => ['n' => 5]], $person, false],
			'twig error' => [['mapping' => ['bad' => '{{ name | nosuchfilter }}']], $person, false],
			'twig error unnamed' => [['name' => null, 'mapping' => ['bad' => '{% nosuchtag %}']], $person, false],
			'input key with dot' => [['mapping' => ['o' => 'a&#46;b'], 'passThrough' => true], ['a.b' => 'dotted', 'c' => 1], false],
			'pass through' => [['mapping' => ['who' => 'name'], 'passThrough' => true], $person, false],
			'pass through plus unset' => [['passThrough' => true, 'unset' => ['age', 'address.zip', 'nope']], $person, false],
			'unset mapped key' => [['mapping' => ['who' => 'name', 'city' => 'address.city'], 'unset' => ['who']], $person, false],
			'cast string list' => [['mapping' => ['age' => 'age', 'who' => 'name'], 'cast' => ['age' => 'int', 'who' => 'string,base64']], $person, false],
			'cast array list' => [['mapping' => ['age' => 'age'], 'cast' => ['age' => ['int', 'string']]], $person, false],
			'cast missing key' => [['mapping' => ['who' => 'name'], 'cast' => ['ghost' => 'int']], $person, false],
			'cast unset if value' => [['mapping' => ['who' => 'name', 'age' => 'age'], 'cast' => ['who' => 'unsetIfValue==Ada']], $person, false],
			'root hash array' => [['mapping' => ['#' => 'address']], $person, false],
			'root hash scalar' => [['mapping' => ['#' => 'name']], $person, false],
			'root hash null' => [['mapping' => ['#' => 'nothing'], 'cast' => ['#' => 'nullStringToNull']], ['nothing' => 'null'], false],
			'root hash with other key' => [['mapping' => ['#' => 'name', 'b' => 'age']], $person, false],
			'list of arrays' => [['mapping' => ['who' => 'name']], [['name' => 'A'], ['name' => 'B']], true],
			'list keyed' => [['mapping' => ['who' => 'name']], ['x' => ['name' => 'A'], 'y' => ['name' => 'B']], true],
			'list of scalars' => [['mapping' => ['v' => 'value']], ['p', 'q'], true],
			'list input with extras' => [
				['mapping' => ['v' => 'value', 'n' => 'name', 'c' => 'ctx']],
				['listInput' => [['name' => 'A'], 'raw'], 'ctx' => 'shared', 'value' => 'dropped'],
				true,
			],
			'list input empty extras' => [['mapping' => ['v' => 'value']], ['listInput' => [['name' => 'A']]], true],
			'list empty' => [['mapping' => ['v' => 'value']], [], true],
			'list of nulls' => [['mapping' => ['v' => 'value']], [null], true],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $recipe
	 * @param array<mixed>         $input
	 */
	#[DataProvider('cases')]
	public function testExecuteMappingMatchesGolden(array $recipe, array $input, bool $list): void {
		$mapping = new Mapping();
		$mapping->hydrate([
			'name' => array_key_exists('name', $recipe) === true ? $recipe['name'] : 'GoldenMapping',
			'mapping' => json_encode($recipe['mapping'] ?? []),
			'passThrough' => $recipe['passThrough'] ?? false,
			'unset' => json_encode($recipe['unset'] ?? []),
			'cast' => json_encode($recipe['cast'] ?? []),
		]);

		try {
			$actual = ['output' => $this->service()->executeMapping($mapping, $input, $list)];
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode(json_encode($actual, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true);
		$name   = (string)$this->dataName();

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testExecuteMappingMatchesGolden()

	private function service(): MappingService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$cacheFactory->method('createLocal')->willReturn($this->createMock(ICache::class));

		return new MappingService(
			$this->createMock(MappingMapper::class),
			$cacheFactory,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()
}//end class
