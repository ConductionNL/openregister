<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ObjectGovernanceServices;
use OCA\OpenRegister\Controller\Support\ResultWindow;
use OCA\OpenRegister\Controller\Support\StringTypedPatch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Golden test for the paging envelope, the history refusal, the unread badges and the string-typed patch restore.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-small-units-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\ResultWindow
 * @covers \OCA\OpenRegister\Controller\Support\StringTypedPatch
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Service\Object\PaginationLinks
 * @uses   \OCA\OpenRegister\Service\Search\HistoryPredicate
 * @uses   \OCA\OpenRegister\Support\FilterParams
 */
class ObjectsControllerSmallUnitsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-small-units-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<mixed> */
	private array $logged = [];

	/**
	 * @return array<string, array{0: list<mixed>, 1: int|null, 2: int|null, 3: int|null, 4: int|null}>
	 */
	public static function pageCases(): array {
		return [
			'all defaults' => [[], 0, 20, 0, 1],
			'nulls' => [['a'], null, null, null, null],
			'second page by number' => [['a'], 45, 10, 0, 2],
			'third page by offset' => [['a'], 45, 10, 25, 1],
			'offset on a page edge' => [['a'], 45, 10, 20, 1],
			'offset of one' => [['a'], 45, 10, 1, 1],
			'page and offset both' => [['a'], 45, 10, 5, 3],
			'last page' => [['a'], 45, 10, 0, 5],
			'beyond the last page' => [[], 45, 10, 0, 9],
			'zero limit' => [['a'], 45, 0, 0, 1],
			'negative limit, offset and page' => [['a'], 45, -5, -5, -5],
			'negative total' => [['a', 'b'], -3, 10, 0, 1],
			'total below the results' => [['a', 'b', 'c'], 1, 2, 0, 1],
			'one exact page' => [['a', 'b'], 2, 2, 0, 1],
			'zero total with an offset' => [[], 0, 10, 30, 1],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
	 */
	public static function historyCases(): array {
		return [
			'no predicate' => [['colour' => 'red'], ['status']],
			'projected' => [['_was_ever' => ['status' => 'open']], ['status']],
			'not projected' => [['_was_ever' => ['colour' => 'red']], ['status']],
			'between not projected' => [['_changed_between' => ['colour' => '2026-01-01,2026-02-01']], ['status']],
			'nothing projected' => [['_was_ever' => ['status' => 'open']], []],
		];
	}

	/**
	 * @return array<string, array{0: string|null, 1: array<string, int>|null}>
	 */
	public static function unreadCases(): array {
		return [
			'anonymous' => [null, ['notes' => 2]],
			'nothing unread' => ['anna', []],
			'unread' => ['anna', ['notes' => 2, 'files' => 1]],
			'lookup fails' => ['anna', null],
		];
	}

	#[DataProvider('pageCases')]
	public function testPagingMatchesTheRecording(array $results, ?int $total, ?int $limit, ?int $offset, ?int $page): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn('/api/objects/1/2?_page=2&x=1');
		$controller = $this->controller();
		(new ReflectionProperty(Controller::class, 'request'))->setValue($controller, $request);

		$answer = $this->invoke($controller, 'paginate', [$results, new ResultWindow($total, $limit, $offset, $page)]);

		$this->compare('page ' . $this->dataName(), ['answer' => $answer, 'types' => array_map('gettype', $answer)]);
	}

	public function testHistoryPredicateIsIgnoredWithoutAProjector(): void {
		$controller = $this->controller();
		$this->set($controller, 'governance', new ObjectGovernanceServices());

		$this->assertNull($this->invoke($controller, 'refuseUnprojectedHistoryPredicate', [['_was_ever' => ['colour' => 'red']]]));
	}

	#[DataProvider('historyCases')]
	public function testHistoryRefusalMatchesTheRecording(array $params, array $projected): void {
		$projector = $this->createMock(StateHistoryProjector::class);
		$projector->method('projectedProperties')->willReturn($projected);
		$controller = $this->controller();
		$this->set($controller, 'governance', new ObjectGovernanceServices(stateHistory: $projector));

		$answer = $this->invoke($controller, 'refuseUnprojectedHistoryPredicate', [$params]);

		$this->compare('history ' . $this->dataName(), $this->plain($answer));
	}

	#[DataProvider('unreadCases')]
	public function testUnreadCountsMatchTheRecording(?string $caller, ?array $counts): void {
		$object = $this->createMock(ObjectEntity::class);
		$readState = $this->createMock(ReadStateService::class);
		$readState->method('callerUid')->willReturn($caller);
		if ($counts === null) {
			$readState->method('unreadCounts')->willThrowException(new RuntimeException('no read state'));
		} else {
			$readState->method('unreadCounts')->with($object)->willReturn($counts);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ReadStateService::class)->willReturn($readState);
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$answer = $this->invoke($controller, 'withUnreadCounts', [['id' => 1], $object]);

		$this->compare('unread ' . $this->dataName(), ['answer' => $answer, 'logged' => $this->logged]);
	}

	public function testUnreadCountsSurviveAContainerThatFails(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no container'));
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$answer = $this->invoke($controller, 'withUnreadCounts', [['id' => 1], $this->createMock(ObjectEntity::class)]);

		$this->compare('unread container fails', ['answer' => $answer, 'logged' => $this->logged]);
	}

	public function testRestoreLeavesDataAloneWithoutASchema(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$this->assertSame(['a' => [1]], $this->restore($controller, ['a' => [1]], 'not a schema', ['a']));
	}

	public function testRestoreLeavesDataAloneWhenTheConverterIsMissing(): void {
		$missing = new class('gone') extends RuntimeException implements NotFoundExceptionInterface {
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException($missing);
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$this->assertSame(['a' => [1]], $this->restore($controller, ['a' => [1]], $this->schema(['p' => 1]), ['a']));
	}

	public function testRestoreLeavesDataAloneWhenTheContainerAnswersSomethingElse(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$this->assertSame(['a' => [1]], $this->restore($controller, ['a' => [1]], $this->schema(['p' => 1]), ['a']));
	}

	public function testRestoreHandsTheConverterItsThreeInputs(): void {
		$converter = $this->createMock(SchemaTypeConverter::class);
		$converter->expects($this->once())->method('restoreStringTypedValues')
			->with(['a' => [1]], ['p' => 1], ['b'])
			->willReturn(['restored' => true]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(SchemaTypeConverterInterface::class)->willReturn($converter);
		$controller = $this->controller();
		$this->set($controller, 'container', $container);

		$this->assertSame(['restored' => true], $this->restore($controller, ['a' => [1]], $this->schema(['p' => 1]), ['b']));
	}

	public function testListingQueryWarnsAboutFilterKeysNoPropertyNames(): void {
		$warned = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warned): void {
			$warned[] = [$message, $context];
		});
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('buildSearchQuery')->willReturn(['colour' => 'red', 'status' => 'open', '_limit' => 5]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$controller = $this->controller(logger: $logger);
		(new ReflectionProperty(Controller::class, 'request'))->setValue($controller, $request);

		$listing = ['register' => 'r1', 'schema' => 's1', 'resolved' => ['register' => 1, 'schema' => 2, 'schemaEntity' => $this->schema(['status' => []])]];
		$query = $this->invoke($controller, 'buildListingQuery', [$listing, $objectService]);
		$this->compare('warn unknown key', ['query' => $query, 'warned' => $warned]);

		$warned = [];
		$listing['resolved']['schemaEntity'] = null;
		$this->invoke($controller, 'buildListingQuery', [$listing, $objectService]);
		$this->assertSame([], $warned);
	}

	private function restore(ObjectsController $controller, array $merged, mixed $schema, array $supplied): array {
		return $this->invoke($controller, 'restoreStringTypedValues', [$merged, new StringTypedPatch($schema, $supplied)]);
	}

	private function schema(array $properties): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}

	private function controller(?LoggerInterface $logger = null): ObjectsController {
		$controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$logger ??= $this->createMock(LoggerInterface::class);
		if ($logger instanceof MockObject) {
			$logger->method('debug')->willReturnCallback(function (string $message): void {
				$this->logged[] = $message;
			});
		}

		$this->set($controller, 'logger', $logger);
		return $controller;
	}

	private function set(ObjectsController $controller, string $property, mixed $value): void {
		(new ReflectionProperty(ObjectsController::class, $property))->setValue($controller, $value);
	}

	private function invoke(ObjectsController $controller, string $method, array $args): mixed {
		return (new ReflectionClass(ObjectsController::class))->getMethod($method)->invokeArgs($controller, $args);
	}

	private function plain(?JSONResponse $response): mixed {
		if ($response === null) {
			return null;
		}

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
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
