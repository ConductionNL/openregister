<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for GET .../reference-options: every refusal, the record it reads and the search it runs.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-reference-options-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @covers \OCA\OpenRegister\Controller\Support\ReferenceOptionsAnswer
 * @covers \OCA\OpenRegister\Controller\Support\ReferenceOptionsAsk
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 * @uses   \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 * @uses   \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\ReferenceFilterException
 * @uses   \OCA\OpenRegister\Service\Schemas\ReferenceOptionsReader
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class ObjectsControllerReferenceOptionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-reference-options-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<mixed> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'no property' => [[], []],
			'blank property' => [['property' => '   '], []],
			'register not found' => [['property' => 'plain'], ['throw' => 'register']],
			'schema not found' => [['property' => 'plain'], ['throw' => 'schema']],
			'no schema entity' => [['property' => 'plain'], ['noSchema' => true]],
			'ambiguous identifier' => [['property' => 'plain'], ['stored' => 'ambiguous']],
			'lookup fails, draft only' => [['property' => 'filtered', '_draft' => ['org' => 'o1']], ['stored' => 'fails']],
			'no stored record' => [['property' => 'filtered', '_draft' => ['org' => 'o1']], ['stored' => null]],
			'stored record decides' => [['property' => 'filtered'], ['stored' => ['org' => 'o2']]],
			'draft beats stored' => [['property' => 'filtered', '_draft' => ['org' => 'o3']], ['stored' => ['org' => 'o2']]],
			'draft not an array' => [['property' => 'filtered', '_draft' => 'x'], ['stored' => ['org' => 'o2']]],
			'unknown property' => [['property' => 'nope'], []],
			'needs a value' => [['property' => 'filtered'], ['stored' => []]],
			'no target schema' => [['property' => 'untargeted'], []],
			'plain reference' => [['property' => 'plain'], []],
			'own register' => [['property' => 'local'], []],
			'item reference' => [['property' => 'many'], []],
			'limit and offset' => [['property' => 'plain', '_limit' => '5', '_offset' => '10'], []],
			'search fails' => [['property' => 'plain'], ['searchFails' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testAnswerMatchesTheRecording(array $params, array $setup): void {
		$service = $this->objectService($setup);
		$response = $this->controller($params, $setup)->referenceOptions('object-1', 'reg', 'sch', $service);

		$this->compare($this->dataName(), ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls]);
	}

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties([
			'plain' => ['$ref' => 'contact', 'register' => 'other'],
			'local' => ['schema' => 'contact'],
			'many' => ['items' => ['$ref' => 'contact']],
			'untargeted' => ['type' => 'string'],
			'filtered' => ['$ref' => 'contact', 'x-openregister-reference-filter' => [['field' => 'organisation', 'op' => 'eq', 'from' => 'org']]],
		]);
		return $schema;
	}

	private function objectService(array $setup): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(function (...$args) use ($service, $setup) {
			$this->calls[] = ['setRegister' => $args];
			if (($setup['throw'] ?? '') === 'register') {
				throw new RegisterNotFoundException('reg');
			}

			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function (...$args) use ($service, $setup) {
			$this->calls[] = ['setSchema' => $args];
			if (($setup['throw'] ?? '') === 'schema') {
				throw new SchemaNotFoundException('sch');
			}

			return $service;
		});
		$service->method('getCurrentSchemaEntity')->willReturn(($setup['noSchema'] ?? false) ? null : $this->schema());
		$service->method('searchObjectsPaginated')->willReturnCallback(function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, SearchOptions $options = new SearchOptions()) use ($setup): array {
			$args = [$query, $_rbac, $_multitenancy, $options->deleted, $options->selection->ids, $options->selection->uses, $options->views];
			$this->calls[] = ['searchObjectsPaginated' => $args];
			if (($setup['searchFails'] ?? false) === true) {
				throw new RuntimeException('no search');
			}

			return ['results' => [['id' => 1]], 'total' => 1];
		});
		return $service;
	}

	private function storedService(array $setup): ObjectService {
		$stored = (array_key_exists('stored', $setup) ? $setup['stored'] : ['org' => 'o9']);
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(function (...$args) use ($stored) {
			$this->calls[] = ['find' => $args];
			if ($stored === 'ambiguous') {
				throw new MultipleObjectsReturnedException('two');
			}

			if ($stored === 'fails') {
				throw new RuntimeException('no read');
			}

			if ($stored === null) {
				return null;
			}

			$object = new ObjectEntity();
			$object->setObject($stored);
			return $object;
		});
		return $service;
	}

	private function controller(array $params, array $setup): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$this->calls[] = [$level => [$message, $context]];
			});
		}

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->storedService($setup),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			null,
			$logger
		);
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= (json_decode((string)@file_get_contents(self::FIXTURE), true) ?? []);
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
