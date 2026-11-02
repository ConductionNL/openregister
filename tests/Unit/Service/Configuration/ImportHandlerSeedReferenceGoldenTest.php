<?php

/**
 * Golden characterization test for ImportHandler::resolveSeedReferenceTokens().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs resolveSeedReferenceTokens() over a fixed corpus and compares the
 * resolved data, the warnings and the existing-object lookups with
 * `fixtures/seed-reference-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\SeedReferenceIndex
 */
class ImportHandlerSeedReferenceGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/seed-reference-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [data, appId].
	 *
	 * @return array<string, array{0: array, 1: ?string}>
	 */
	private static function corpus(): array {
		$shift = ['register' => 'r', 'schema' => 'shift', 'slug' => 'shift-1', 'id' => 'UUID-SHIFT'];
		return [
			'no-objects-key' => [['components' => []], null],
			'objects-not-array' => [['components' => ['objects' => 'x']], null],
			'no-tokens' => [['components' => ['objects' => [['@self' => $shift, 'v' => 'plain', 'n' => 3]]]], null],
			'bare' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:shift-1'],
			]]], null],
			'qualified' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:shift:shift-1'],
			]]], null],
			'nested' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'rep', 'slug' => 'r1'], 'a' => ['b' => ['@ref:shift-1', 'plain', 7, null, ['@ref:shift:shift-1']]]],
			]]], null],
			'unresolved-bare' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:nope'],
			]]], null],
			'unresolved-qualified' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:other:shift-1'],
			]]], null],
			'ambiguous' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'a', 'slug' => 'dup', 'id' => 'UUID-A']],
				['@self' => ['register' => 'r', 'schema' => 'b', 'slug' => 'dup', 'id' => 'UUID-B']],
				['@self' => ['register' => 'r', 'schema' => 'src', 'slug' => 's1'], 'x' => '@ref:dup', 'y' => '@ref:a:dup', 'z' => '@ref:b:dup'],
			]]], null],
			'same-slug-same-uuid' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'a', 'slug' => 'dup', 'id' => 'UUID-SAME']],
				['@self' => ['register' => 'r', 'schema' => 'b', 'slug' => 'dup', 'id' => 'UUID-SAME']],
				['@self' => ['register' => 'r', 'schema' => 'src', 'slug' => 's1'], 'x' => '@ref:dup'],
			]]], null],
			'persisted-wins' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'shift', 'slug' => 'persisted', 'id' => 'EXPLICIT']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:persisted'],
			]]], null],
			'persisted-entity' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'shift', 'slug' => 'entity']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:entity'],
			]]], null],
			'minted' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'shift', 'slug' => 'fresh']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:fresh', 'again' => '@ref:shift:fresh'],
			]]], null],
			'search-throws' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'shift', 'slug' => 'boom', 'id' => 'UUID-BOOM']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:boom'],
			]]], null],
			'register-missing' => [['components' => ['objects' => [
				['@self' => ['register' => 'missing', 'schema' => 'shift', 'slug' => 'shift-1', 'id' => 'U1']],
				['@self' => ['register' => 'missing', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:shift-1'],
			]]], null],
			'schema-missing' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'schema' => 'gone', 'slug' => 'shift-1', 'id' => 'U1']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'shift' => '@ref:shift-1'],
			]]], null],
			'foreign-schema' => [['components' => ['objects' => [
				['@self' => ['register' => 'listing', 'schema' => 'foreign', 'slug' => 'f1', 'id' => 'UF']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'f' => '@ref:f1'],
			]]], 'mine'],
			'foreign-schema-own-app' => [['components' => ['objects' => [
				['@self' => ['register' => 'listing', 'schema' => 'foreign', 'slug' => 'f1', 'id' => 'UF']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'f' => '@ref:f1'],
			]]], 'other'],
			'odd-entries' => [['components' => ['objects' => [
				'not-an-array',
				['@self' => ['register' => 'r', 'schema' => 'shift', 'slug' => '', 'id' => 'NOSLUG']],
				['v' => '@ref:shift-1'],
				['@self' => $shift],
				7,
			]]], null],
			'target-without-schema' => [['components' => ['objects' => [
				['@self' => ['register' => 'r', 'slug' => 'noschema', 'id' => 'UNS']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'x' => '@ref:noschema', 'y' => '@ref:drop:noschema'],
			]]], null],
			'qualified-only-referenced' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'other', 'slug' => 'shift-1', 'id' => 'UNREF']],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'x' => '@ref:shift:shift-1'],
			]]], null],
			'empty-schema-prefix' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd'], 'x' => '@ref::shift-1'],
			]]], null],
			'token-inside-self' => [['components' => ['objects' => [
				['@self' => $shift],
				['@self' => ['register' => 'r', 'schema' => 'drop', 'slug' => 'd', 'owner' => '@ref:shift-1']],
			]]], null],
			'self-null' => [['components' => ['objects' => [
				['@self' => null, 'x' => '@ref:shift-1'],
				['@self' => $shift],
			]]], null],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each corpus case produces the recorded data, warnings and lookups.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @covers ::resolveSeedReferenceTokens
	 *
	 * @dataProvider cases
	 */
	public function testResolveSeedReferenceTokensMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testResolveSeedReferenceTokensMatchesGolden()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: ?string} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->calls[] = ['warning', (string)$message, $context];
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $id): Register {
				$this->calls[] = ['register.find', $id];
				if ($id === 'missing') {
					throw new \RuntimeException('no register');
				}

				$register = new Register();
				$register->setId(1);
				$register->setSlug((string)$id);
				if ($id === 'listing') {
					$register->setSchemas([99]);
					$register->setApplication('owner-of-register');
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				$this->calls[] = ['schema.find', $id];
				if ($id === 'gone') {
					throw new \RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setId(strlen((string)$id));
				$schema->setSlug((string)$id);
				if ($id === 'foreign') {
					$schema->setApplication('other');
				}

				return $schema;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->calls[] = ['searchObjects', $query];
				$slug = $query['@self']['slug'];
				if ($slug === 'boom') {
					throw new \RuntimeException('search failed');
				}

				if ($slug === 'persisted') {
					return [['@self' => ['id' => 'PERSISTED']]];
				}

				if ($slug === 'entity') {
					return [['id' => 'FLAT-ID']];
				}

				return [];
			}
		);

		$handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $objectService
		);

		$method = new \ReflectionMethod(ImportHandler::class, 'resolveSeedReferenceTokens');
		$out = $method->invoke($handler, $case[0], $case[1]);

		return $this->mintedUuidsReplaced(record: ['out' => $out, 'calls' => $this->calls]);
	}//end runCase()

	/**
	 * Replace freshly minted v4 UUIDs with stable placeholders.
	 *
	 * @param array<string, mixed> $record The case record.
	 *
	 * @return array<string, mixed>
	 */
	private function mintedUuidsReplaced(array $record): array {
		$json = (string)json_encode($record);
		preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', $json, $matches);
		$placeholders = [];
		foreach ($matches[0] as $uuid) {
			$placeholders[$uuid] ??= 'MINTED-'.count($placeholders);
		}

		return json_decode(strtr($json, $placeholders), true);
	}//end mintedUuidsReplaced()
}//end class
