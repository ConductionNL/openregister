<?php

/**
 * Characterization tests for ImportHandler::importSchema().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
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
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use stdClass;
use Stringable;

/**
 * Pins what importSchema() writes, looks up, logs and returns for a corpus of
 * imports. The expected outcomes live in
 * `fixtures/import-schema-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Configuration\ImportHandler
 */
class ImportHandlerImportSchemaCharacterizationTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/import-schema-golden.json';
	}//end goldenPath()

	/**
	 * A property map that reaches every normalisation branch.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function richProperties(): array {
		return [
			'p_object'                 => (object) ['type' => 'string'],
			'p_notitle'                => ['type' => 'string'],
			'p_emptytitle'             => ['title' => '', 'type' => 'string'],
			'p_keeptitle'              => ['title' => 'Keep', 'type' => 'string'],
			'p_notype'                 => [],
			'p_null'                   => null,
			0                          => ['type' => 'integer'],
			'p_emptycfg'               => ['type' => 'object', 'objectConfiguration' => [], 'fileConfiguration' => []],
			'p_cfg_nonempty'           => ['type' => 'file', 'fileConfiguration' => ['maxSize' => 1]],
			'p_items_obj'              => ['type' => 'array', 'items' => (object) ['type' => 'string', 'format' => 'binary']],
			'p_items_emptycfg'         => ['type' => 'array', 'items' => ['objectConfiguration' => [], 'fileConfiguration' => []]],
			'p_items_string'           => ['type' => 'array', 'items' => 'string'],
			'p_format_byte'            => ['type' => 'string', 'format' => 'byte'],
			'p_format_binary'          => ['type' => 'string', 'format' => 'binary'],
			'p_format_string'          => ['type' => 'string', 'format' => 'string'],
			'p_format_date'            => ['type' => 'string', 'format' => 'date'],
			'p_items_format_byte'      => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'byte']],
			'p_items_format_uri'       => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
			'p_ref_map'                => ['type' => 'object', '$ref' => 'imported-slug'],
			'p_ref_schemasmap'         => ['type' => 'object', '$ref' => 'mapped-schema'],
			'p_ref_unknown'            => ['type' => 'object', '$ref' => 'nowhere'],
			'p_items_ref_map'          => ['type' => 'array', 'items' => ['$ref' => 'imported-slug']],
			'p_items_ref_schemasmap'   => ['type' => 'array', 'items' => ['$ref' => 'mapped-schema']],
			'p_items_ref_unknown'      => ['type' => 'array', 'items' => ['$ref' => 'nowhere']],
			'p_cfg_obj_reg_mapped'     => ['type' => 'object', 'objectConfiguration' => (object) ['register' => 'mapped-reg']],
			'p_cfg_reg_db'             => ['type' => 'object', 'objectConfiguration' => ['register' => 'db-reg']],
			'p_cfg_reg_missing'        => ['type' => 'object', 'objectConfiguration' => ['register' => 'missing-reg', 'handling' => 'related']],
			'p_cfg_reg_empty'          => ['type' => 'object', 'objectConfiguration' => ['register' => '']],
			'p_cfg_schema_mapped'      => ['type' => 'object', 'objectConfiguration' => ['schema' => 'mapped-schema']],
			'p_cfg_schema_db'          => ['type' => 'object', 'objectConfiguration' => ['schema' => 'db-schema']],
			'p_cfg_schema_missing'     => ['type' => 'object', 'objectConfiguration' => ['schema' => 'missing-schema']],
			'p_cfg_schema_empty'       => ['type' => 'object', 'objectConfiguration' => ['schema' => '']],
			'p_cfg_both_cached'        => ['type' => 'object', 'objectConfiguration' => ['register' => 'db-reg', 'schema' => 'db-schema']],
			'p_cfg_both_missing'       => ['type' => 'object', 'objectConfiguration' => ['register' => 'missing-reg', 'schema' => 'missing-schema']],
			'p_items_cfg_obj'          => ['type' => 'array', 'items' => (object) ['objectConfiguration' => (object) ['register' => 'mapped-reg', 'schema' => 'mapped-schema']]],
			'p_items_cfg_db'           => ['type' => 'array', 'items' => ['objectConfiguration' => ['register' => 'db-reg-2', 'schema' => 'db-schema-2']]],
			'p_items_cfg_missing'      => ['type' => 'array', 'items' => ['objectConfiguration' => ['register' => 'missing-reg', 'schema' => 'missing-schema']]],
			'p_items_cfg_empty'        => ['type' => 'array', 'items' => ['objectConfiguration' => ['register' => '', 'schema' => '']]],
			'p_legacy_reg_map'         => ['type' => 'object', 'register' => 'imported-reg'],
			'p_legacy_reg_mapped'      => ['type' => 'object', 'register' => 'mapped-reg'],
			'p_legacy_reg_unknown'     => ['type' => 'object', 'register' => 'zzz'],
			'p_items_legacy_reg_map'   => ['type' => 'array', 'items' => ['register' => 'imported-reg']],
			'p_items_legacy_reg_mapped' => ['type' => 'array', 'items' => ['register' => 'mapped-reg']],
			'p_items_legacy_reg_none'  => ['type' => 'array', 'items' => ['register' => 'zzz']],
		];
	}//end richProperties()

	/**
	 * The corpus: name => [importSchema arguments, versioning on].
	 *
	 * @return array<string, array{args: array<string, mixed>, versioning?: bool, failCreate?: bool}>
	 */
	public static function corpus(): array {
		$map = ['imported-slug' => 55, 'imported-reg' => 66];
		return [
			'create-rich' => ['args' => ['data' => ['id' => 1, 'uuid' => 'u', 'organisation' => 'o', 'slug' => 'thing', 'title' => 'Thing', 'version' => '1.0.0', 'properties' => self::richProperties()], 'slugsAndIdsMap' => $map, 'owner' => 'alice', 'appId' => 'myapp', 'version' => '2.0.0']],
			'create-bare' => ['args' => ['data' => ['slug' => 'bare'], 'slugsAndIdsMap' => []]],
			'create-null-properties' => ['args' => ['data' => ['slug' => 'nullprops', 'properties' => null], 'slugsAndIdsMap' => []]],
			'no-slug' => ['args' => ['data' => ['title' => 'Nameless'], 'slugsAndIdsMap' => []]],
			'blank-slug-no-title' => ['args' => ['data' => ['slug' => '  '], 'slugsAndIdsMap' => []]],
			'create-fails' => ['args' => ['data' => ['slug' => 'thing', 'properties' => ['a' => ['type' => 'string']]], 'slugsAndIdsMap' => []], 'failCreate' => true],
			'update-newer' => ['args' => ['data' => ['slug' => 'existing', 'version' => '2.0.0', 'properties' => ['a' => ['type' => 'string']]], 'slugsAndIdsMap' => [], 'owner' => 'bob', 'appId' => 'app2', 'version' => '9.9.9']],
			'update-same-differs' => ['args' => ['data' => ['slug' => 'existing', 'version' => '1.0.0', 'properties' => ['b' => ['type' => 'integer']]], 'slugsAndIdsMap' => []]],
			'update-same-unchanged' => ['args' => ['data' => ['slug' => 'existing', 'version' => '1.0.0', 'properties' => ['a' => ['type' => 'string', 'title' => 'a']], 'required' => []], 'slugsAndIdsMap' => []]],
			'update-older-forced' => ['args' => ['data' => ['slug' => 'existing', 'version' => '0.1.0', 'properties' => ['a' => ['type' => 'string']]], 'slugsAndIdsMap' => [], 'force' => true]],
			'update-no-version-forced' => ['args' => ['data' => ['slug' => 'existing', 'properties' => ['c' => ['type' => 'string']]], 'slugsAndIdsMap' => [], 'force' => true]],
			'update-versioned' => ['args' => ['data' => ['slug' => 'existing', 'version' => '1.0.0', 'properties' => ['z' => ['type' => 'string']]], 'slugsAndIdsMap' => [], 'force' => true], 'versioning' => true],
			'update-versioned-newer' => ['args' => ['data' => ['slug' => 'existing', 'version' => '3.0.0', 'properties' => ['z' => ['type' => 'string']]], 'slugsAndIdsMap' => []], 'versioning' => true],
			'update-unversioned-existing' => ['args' => ['data' => ['slug' => 'unversioned', 'properties' => ['z' => ['type' => 'string']]], 'slugsAndIdsMap' => [], 'force' => true], 'versioning' => true],
			'register-scoped' => ['args' => ['data' => ['slug' => 'thing', 'properties' => []], 'slugsAndIdsMap' => [], 'registerSchemaIds' => [1, 2]]],
		];
	}//end corpus()

	/**
	 * Run one corpus entry and describe everything observable about it.
	 *
	 * @param array<string, mixed> $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function observe(array $case): array {
		$seen = ['created' => [], 'updated' => [], 'registerFinds' => [], 'schemaFinds' => [], 'logs' => []];

		$schemaMapper   = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$appConfig      = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$existing = new Schema();
		$existing->hydrate(['slug' => 'existing', 'version' => '1.0.0', 'properties' => ['a' => ['type' => 'string', 'title' => 'a']], 'required' => [], 'owner' => 'old', 'application' => 'oldapp']);
		$existing->setId(9);
		$unversioned = new Schema();
		$unversioned->hydrate(['slug' => 'unversioned', 'properties' => []]);
		$unversioned->setId(10);

		$schemaMapper->method('find')->willReturnCallback(function ($slug) use (&$seen, $existing, $unversioned): Schema {
			$seen['schemaFinds'][] = $slug;
			if ($slug === 'existing') {
				return $existing;
			}

			if ($slug === 'unversioned') {
				return $unversioned;
			}

			if (in_array($slug, ['db-schema', 'db-schema-2'], true) === true) {
				$found = new Schema();
				$found->hydrate(['slug' => $slug]);
				$found->setId(($slug === 'db-schema') ? 42 : 43);
				return $found;
			}

			throw new DoesNotExistException('no schema '.$slug);
		});
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(fn ($slug, $application) => ($slug === 'existing') ? $existing : null);
		$schemaMapper->method('findBySlugInIds')->willReturnCallback(function ($slug, $ids) use (&$seen) {
			$seen['schemaFinds'][] = ['inIds' => $slug, 'ids' => $ids];
			return null;
		});
		$schemaMapper->method('updateEventCount')->willReturn(0);
		$schemaMapper->method('update')->willReturnArgument(0);
		$schemaMapper->method('createFromArray')->willReturnCallback(function (array $data) use (&$seen, $case): Schema {
			$seen['created'][] = self::plain($data);
			if (($case['failCreate'] ?? false) === true) {
				throw new \RuntimeException('mapper refused', 7);
			}

			$schema = new Schema();
			$schema->hydrate(['slug' => $data['slug']]);
			$schema->setId(100);
			return $schema;
		});
		$schemaMapper->method('updateFromArray')->willReturnCallback(function ($id, array $object) use (&$seen): Schema {
			$seen['updated'][] = ['id' => $id, 'object' => self::plain($object)];
			$schema = new Schema();
			$schema->hydrate(['slug' => $object['slug'], 'version' => ($object['version'] ?? null)]);
			$schema->setId($id);
			return $schema;
		});

		$registerMapper->method('find')->willReturnCallback(function ($slug) use (&$seen): Register {
			$seen['registerFinds'][] = $slug;
			if (in_array($slug, ['db-reg', 'db-reg-2'], true) === true) {
				$register = new Register();
				$register->hydrate(['slug' => $slug]);
				$register->setId(($slug === 'db-reg') ? 21 : 22);
				return $register;
			}

			throw new DoesNotExistException('no register '.$slug);
		});

		$logger = new class($seen) extends AbstractLogger {
			/**
			 * @param array<string, mixed> $seen The observation record.
			 */
			public function __construct(private array &$seen) {
			}

			/**
			 * @param mixed              $level   Level.
			 * @param string|Stringable $message Message.
			 * @param array<mixed>      $context Context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->seen['logs'][] = [$level, (string) $message, $context];
			}
		};

		$handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $appConfig,
			logger: $logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);

		if (($case['versioning'] ?? false) === true) {
			$versioning = $this->createMock(SchemaVersioningService::class);
			$versioning->method('classify')->willReturn(new SchemaChangeSet(changes: [['kind' => 'added', 'path' => 'z']], classification: 'minor', bump: 'minor'));
			$versioning->method('nextVersion')->willReturn('1.1.0');
			$handler->setSchemaVersioning($versioning);
		}

		$mappedSchema = new Schema();
		$mappedSchema->hydrate(['slug' => 'mapped-schema']);
		$mappedSchema->setId(7);
		$mappedRegister = new Register();
		$mappedRegister->hydrate(['slug' => 'mapped-reg']);
		$mappedRegister->setId(3);
		(new ReflectionProperty(ImportHandler::class, 'schemasMap'))->setValue($handler, ['mapped-schema' => $mappedSchema]);
		(new ReflectionProperty(ImportHandler::class, 'registersMap'))->setValue($handler, ['mapped-reg' => $mappedRegister]);

		try {
			$result = $handler->importSchema(...$case['args']);
			$seen['result'] = ['id' => $result->getId(), 'slug' => $result->getSlug(), 'version' => $result->getVersion(), 'owner' => $result->getOwner(), 'application' => $result->getApplication()];
		} catch (\Throwable $e) {
			$previous = $e->getPrevious();
			$seen['result'] = ['exception' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode(), 'previous' => ($previous === null) ? null : get_class($previous)];
		}

		$seen['schemasMap']   = array_map(static fn ($schema) => $schema->getId(), (new ReflectionProperty(ImportHandler::class, 'schemasMap'))->getValue($handler));
		$seen['registersMap'] = array_map(static fn ($register) => $register->getId(), (new ReflectionProperty(ImportHandler::class, 'registersMap'))->getValue($handler));

		return $seen;
	}//end observe()

	/**
	 * A JSON-safe copy that keeps objects distinguishable from arrays.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private static function plain(mixed $value): mixed {
		if ($value instanceof stdClass) {
			return ['@object' => self::plain((array) $value)];
		}

		if (is_array($value) === true) {
			return array_map(static fn ($item) => self::plain($item), $value);
		}

		return $value;
	}//end plain()

	/**
	 * Data provider over the corpus.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function corpusProvider(): array {
		$rows = [];
		foreach (array_keys(self::corpus()) as $name) {
			$rows[$name] = [$name];
		}

		return $rows;
	}//end corpusProvider()

	/**
	 * importSchema() behaves exactly as recorded for every corpus entry.
	 *
	 * @param string $name The corpus entry.
	 *
	 * @return void
	 */
	#[DataProvider('corpusProvider')]
	public function testImportSchemaMatchesGolden(string $name): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe(self::corpus()[$name])), true);

		$this->assertSame($golden[$name], $actual);
	}//end testImportSchemaMatchesGolden()

	/**
	 * Regenerates the golden file when OPENREGISTER_WRITE_GOLDEN is set; otherwise checks it has no orphans.
	 *
	 * @return void
	 */
	public function testGoldenCoversTheCorpus(): void {
		if (getenv('OPENREGISTER_WRITE_GOLDEN') === '1') {
			$out = [];
			foreach (self::corpus() as $name => $case) {
				$out[$name] = $this->observe($case);
			}

			file_put_contents(self::goldenPath(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertSame(array_keys(self::corpus()), array_keys($golden));
	}//end testGoldenCoversTheCorpus()
}//end class
