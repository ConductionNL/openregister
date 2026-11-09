<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\SentWrite;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\Object\ConflictReport;
use OCA\OpenRegister\Service\Object\ConflictReportInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden test for the version check on a write: what it refuses, what the 409 says and what the trail records.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-version-conflict-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\ConflictTrail
 * @covers \OCA\OpenRegister\Controller\Support\ObjectVersionGuard
 * @covers \OCA\OpenRegister\Controller\Support\SentWrite
 * @covers \OCA\OpenRegister\Controller\Support\VersionClaim
 * @covers \OCA\OpenRegister\Controller\Support\VersionMismatch
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectsControllerVersionConflictGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-version-conflict-golden.json';
	private const STORED = '2026-05-05T10:00:00+00:00';
	private const OLDER = '2026-05-05T09:00:00+00:00';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<mixed> */
	private array $trail = [];

	/** @var list<string> */
	private array $warned = [];

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function versionCases(): array {
		return [
			'nothing claimed' => [null, ''],
			'blank claim' => ['   ', ''],
			'same version' => [self::STORED, ''],
			'stale version' => [self::OLDER, ''],
			'integer claim' => [5, ''],
			'blank param, same header' => ['', self::STORED],
			'header stale' => [null, self::OLDER],
			'header quoted' => [null, '"' . self::OLDER . '"'],
			'header padded' => [null, '  ' . self::OLDER . '  '],
			'header an etag' => [null, '"abc123"'],
			'header a media type' => [null, 'application/json'],
			'header a date with another offset' => [null, '2026-05-05T12:00:00+02:00'],
			'header a bare date' => [null, '2026-05-05'],
			'header a year and month' => [null, '2026-05'],
			'header unparsable with a date in it' => [null, 'x2026-05-05'],
			'param wins over header' => [self::STORED, self::OLDER],
		];
	}

	#[DataProvider('versionCases')]
	public function testPlainRefusalMatchesTheRecording(mixed $param, string $header): void {
		$controller = $this->controller($param, $header, null);
		$this->compare('plain ' . $this->dataName(), $this->answer($controller, null));
	}

	public function testSchemaReportMatchesTheRecording(): void {
		$report = $this->createMock(ConflictReport::class);
		$report->method('build')->willReturnCallback(function (ObjectEntity $stored, Schema $schema, array $sent, array $intervening): array {
			return ['error' => 'built', 'conflicts' => ['a' => 1, 'b' => 2], 'sent' => $sent, 'intervening' => count($intervening)];
		});
		$controller = $this->controller(self::OLDER, '', $report);

		$this->compare('report', $this->answer($controller, $this->createMock(Schema::class)));
	}

	public function testAnUnparsableClaimHasNoInterveningChanges(): void {
		$report = $this->createMock(ConflictReport::class);
		$report->method('build')->willReturnCallback(function (ObjectEntity $stored, Schema $schema, array $sent, array $intervening): array {
			return ['conflicts' => [], 'intervening' => count($intervening)];
		});
		$controller = $this->controller('not a date', '', $report);

		$this->compare('report unparsable claim', $this->answer($controller, $this->createMock(Schema::class)));
	}

	public function testAReportThatFailsStillRefuses(): void {
		$report = $this->createMock(ConflictReport::class);
		$report->method('build')->willThrowException(new RuntimeException('no report'));
		$controller = $this->controller(self::OLDER, '', $report);

		$this->compare('report fails', $this->answer($controller, $this->createMock(Schema::class)));
	}

	public function testATrailThatFailsStillRefuses(): void {
		$controller = $this->controller(self::OLDER, '', null, new RuntimeException('no trail'));

		$this->compare('trail fails', $this->answer($controller, null));
	}

	private function answer(ObjectsController $controller, ?Schema $schema): mixed {
		$object = new ObjectEntity();
		$object->setUpdated(new DateTime(self::STORED));
		$object->setUuid('object-uuid');
		$response = $this->check($controller, $object, ['a' => 'mine'], $schema);
		$plain = null;
		if ($response instanceof JSONResponse) {
			$plain = ['status' => $response->getStatus(), 'data' => $response->getData()];
		}

		return ['response' => $plain, 'trail' => $this->trail, 'warned' => $this->warned];
	}

	private function check(ObjectsController $controller, ObjectEntity $object, array $sent, ?Schema $schema): ?JSONResponse {
		return (new ReflectionClass(ObjectsController::class))->getMethod('versionGuard')->invoke($controller)->refusal($object, new SentWrite($sent, $schema));
	}

	private function controller(mixed $param, string $header, ?ConflictReport $report, ?RuntimeException $trailFails = null): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($key === '_expectedUpdated') ? $param : null);
		$request->method('getHeader')->willReturnCallback(fn (string $key) => ($key === 'If-Match') ? $header : '');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ConflictReportInterface::class)->willReturn($report);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warned[] = $message;
		});

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->auditTrail($trailFails),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			null,
			$logger
		);
	}

	private function auditTrail(?RuntimeException $fails): AuditTrailMapper {
		$before = new AuditTrail();
		$before->setCreated(new DateTime('2026-05-05T08:00:00+00:00'));
		$after = new AuditTrail();
		$after->setCreated(new DateTime('2026-05-05T09:30:00+00:00'));
		$undated = new AuditTrail();

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(function (...$args) use ($before, $after, $undated): array {
			$this->trail[] = ['findAll' => $args];
			return [$before, $after, $undated, 'not an entry'];
		});
		$mapper->method('createAuditTrailEntry')->willReturnCallback(function (...$args) use ($fails) {
			if ($fails !== null) {
				throw $fails;
			}

			$this->trail[] = ['entry' => array_slice($args, 1)];
			return new AuditTrail();
		});
		return $mapper;
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
