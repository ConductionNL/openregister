<?php

/**
 * Golden test for AnalyticsLinkService.
 *
 * Runs link/create/unlink/list/picker calls against a recording link mapper
 * and an NC Analytics ReportService stand-in, per scenario, and records each
 * result (or exception) plus every mapper, report-service and logger call in
 * fixtures/analytics-link-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * Timestamps the service takes from the clock are recorded as "<now>"; all
 * fixture timestamps are fixed and far from today, and the test runs in UTC.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;

if (class_exists('OCA\\Analytics\\Service\\ReportService') === false) {
	eval('namespace OCA\\Analytics\\Service; class ReportService {}');
}

/**
 * @covers \OCA\OpenRegister\Service\AnalyticsLinkService
 */
class AnalyticsLinkServiceGoldenTest extends TestCase {

	/**
	 * Ordered call log.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Timezone before the test.
	 *
	 * @var string
	 */
	private string $timezone = 'UTC';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}//end tearDown()

	/**
	 * Reports the stand-in ReportService reads, by id.
	 *
	 * @return array<int, mixed>
	 */
	private static function reports(): array {
		return [
			5 => ['name' => 'Revenue', 'type' => 2, 'subheader' => 'Q1', 'created_at' => 1000000000, 'modified_at' => '2001-02-03 04:05:06'],
			6 => ['name' => 'Bare'],
			7 => ['name' => 'Created', 'type' => '0', 'created_at' => '1000000001', 'updated_at' => 1000000002],
			8 => ['type' => null, 'subheader' => null, 'created_at' => 0, 'modified_at' => ''],
			9 => ['name' => 'Bad dates', 'created_at' => 'not a date', 'modified_at' => 'also bad', 'updated_at' => '2001-01-01'],
			10 => [],
			11 => 'scalar',
		];
	}//end reports()

	/**
	 * Scenarios: [options, calls].
	 *
	 * Options: user, analytics, service (ok|throws), existing (duplicate report ids),
	 * deleted (row count), links (linkedAt per link: null|old|fresh), updateThrows,
	 * createReturns, createThrows, readThrows (ids), index (ok|throws).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$link = static fn (int $id): array => ['linkReport', $id];
		return [
			'link found report' => [[], [$link(5), $link(6), $link(8), $link(9)]],
			'link missing report' => [[], [$link(404), $link(10), $link(11)]],
			'link read throws' => [['readThrows' => [5]], [$link(5)]],
			'link duplicate' => [['existing' => [5]], [$link(5)]],
			'link analytics off' => [['analytics' => false], [$link(5)]],
			'link no user' => [['user' => false], [$link(5)]],
			'link service unresolvable' => [['service' => 'throws'], [$link(5)]],
			'create and link' => [['createReturns' => 7], [['create', '  New report ', null], ['create', 'Typed', 3]]],
			'create read empty uses draft' => [['createReturns' => 10], [['create', 'Draft', null]]],
			'create returns zero' => [['createReturns' => 0], [['create', 'Zero', null]]],
			'create throws' => [['createThrows' => true], [['create', 'Boom', 4]]],
			'create blank name' => [[], [['create', '   ', null]]],
			'create no user' => [['user' => false], [['create', 'X', null]]],
			'create analytics off' => [['analytics' => false], [['create', 'X', null]]],
			'unlink' => [['deleted' => 1], [['unlink', 'obj-1', 5]]],
			'unlink missing' => [['deleted' => 0], [['unlink', 'obj-1', 5]]],
			'unlink no user' => [['user' => false], [['unlink', 'obj-1', 5]]],
			'linked reports refresh stale' => [['links' => [[5, null], [7, 'old'], [5, 'fresh'], [404, 'old'], [8, 'old']]], [['linked', 'obj-1']]],
			'linked reports update throws' => [['links' => [[5, 'old']], 'updateThrows' => true], [['linked', 'obj-1']]],
			'linked reports analytics off' => [['analytics' => false, 'links' => [[5, null], [7, 'old']]], [['linked', 'obj-1']]],
			'picker all' => [[], [['available', null], ['available', '']]],
			'picker search' => [[], [['available', 'REV'], ['available', 'zzz']]],
			'picker index throws' => [['index' => 'throws'], [['available', null]]],
			'picker analytics off' => [['analytics' => false], [['available', null]]],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed>     $options Scenario options.
	 * @param array<int, array<mixed>> $calls   Calls to run.
	 *
	 * @return void
	 */
	public function testServiceMatchesGolden(array $options, array $calls): void {
		$this->log = [];
		$options = array_merge(
			['user' => true, 'analytics' => true, 'service' => 'ok', 'existing' => [], 'deleted' => 1, 'links' => [], 'updateThrows' => false, 'createReturns' => 7, 'createThrows' => false, 'readThrows' => [], 'index' => 'ok'],
			$options
		);
		$service = new AnalyticsLinkService(
			$this->mapper($options),
			$this->appManager($options['analytics']),
			$this->session($options['user']),
			$this->logger(),
			$this->container($options)
		);

		$results = [];
		foreach ($calls as $call) {
			$results[] = $this->runCall($service, $call);
		}

		$actual = ['results' => $results, 'log' => $this->log];
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__.'/fixtures/analytics-link-service-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testServiceMatchesGolden()

	/**
	 * Run one call and describe the outcome.
	 *
	 * @param AnalyticsLinkService $service The service.
	 * @param array<int, mixed>    $call    The call.
	 *
	 * @return mixed
	 */
	private function runCall(AnalyticsLinkService $service, array $call): mixed {
		$target = (new LinkTarget('obj-1', 11))->inSchema(22);
		try {
			$result = match ($call[0]) {
				'linkReport' => $service->linkReport($target, $call[1]),
				'create' => $service->createAndLinkReport($target, $call[1], $call[2]),
				'unlink' => $service->unlinkReport($call[1], $call[2]),
				'linked' => $service->getLinkedReports($call[1]),
				'available' => $service->getAvailableReports($call[1]),
			};
			return ['result' => $this->describe($result)];
		} catch (Throwable $e) {
			return ['exception' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}
	}//end runCall()

	/**
	 * Describe a value, replacing clock-derived timestamps with "<now>".
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof AnalyticsLink) {
			return ['AnalyticsLink' => $this->describe($value->jsonSerialize()), 'updated' => array_keys($value->getUpdatedFields())];
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe($item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1
			&& abs(strtotime($value) - time()) < 86400
		) {
			return '<now>';
		}

		return $value;
	}//end describe()

	/**
	 * Recording link mapper.
	 *
	 * @param array<string, mixed> $options Scenario options.
	 *
	 * @return AnalyticsLinkMapper
	 */
	private function mapper(array $options): AnalyticsLinkMapper {
		$mapper = $this->createMock(AnalyticsLinkMapper::class);
		$mapper->method('findByObjectAndReport')->willReturnCallback(
			function (string $objectUuid, int $reportId) use ($options): ?AnalyticsLink {
				$this->log[] = ['mapper.findByObjectAndReport', $objectUuid, $reportId];
				return in_array($reportId, $options['existing'], true) === true ? new AnalyticsLink() : null;
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function ($link) {
				$this->log[] = ['mapper.insert', $this->describe($link)];
				return $link;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function ($link) use ($options) {
				$this->log[] = ['mapper.update', $this->describe($link)];
				if ($options['updateThrows'] === true) {
					throw new RuntimeException('update failed');
				}

				return $link;
			}
		);
		$mapper->method('deleteByObjectAndReport')->willReturnCallback(
			function (string $objectUuid, int $reportId) use ($options): int {
				$this->log[] = ['mapper.deleteByObjectAndReport', $objectUuid, $reportId];
				return $options['deleted'];
			}
		);
		$mapper->method('findByObjectUuid')->willReturnCallback(
			function (string $objectUuid) use ($options): array {
				$this->log[] = ['mapper.findByObjectUuid', $objectUuid];
				return array_map(fn (array $row) => $this->storedLink($row[0], $row[1]), $options['links']);
			}
		);
		return $mapper;
	}//end mapper()

	/**
	 * A stored link row with a fixed linkedAt.
	 *
	 * @param int         $reportId The report id.
	 * @param string|null $age      null|old|fresh.
	 *
	 * @return AnalyticsLink
	 */
	private function storedLink(int $reportId, ?string $age): AnalyticsLink {
		$link = new AnalyticsLink();
		$link->setId($reportId * 10);
		$link->setObjectUuid('obj-1');
		$link->setReportId($reportId);
		$link->setReportTitle('Cached '.$reportId);
		$link->setCreatedAt(new DateTime('2000-01-01T00:00:00+00:00'));
		if ($age !== null) {
			$link->setLinkedAt(new DateTime($age === 'old' ? '2001-01-01T00:00:00+00:00' : '2999-01-01T00:00:00+00:00'));
		}

		$link->resetUpdatedFields();
		return $link;
	}//end storedLink()

	/**
	 * App manager reporting analytics on/off.
	 *
	 * @param bool $enabled Whether analytics is enabled.
	 *
	 * @return IAppManager
	 */
	private function appManager(bool $enabled): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			function ($appId) use ($enabled): bool {
				$this->log[] = ['apps.isEnabledForUser', $appId];
				return $enabled;
			}
		);
		return $apps;
	}//end appManager()

	/**
	 * User session with or without a user.
	 *
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return IUserSession
	 */
	private function session(bool $loggedIn): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $user : null);
		return $session;
	}//end session()

	/**
	 * Container handing out the ReportService stand-in.
	 *
	 * @param array<string, mixed> $options Scenario options.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $options): ContainerInterface {
		$reports = new class ($this, $options, self::reports()) {
			/**
			 * @param AnalyticsLinkServiceGoldenTest $test    The test (log sink).
			 * @param array<string, mixed>           $options Scenario options.
			 * @param array<int, mixed>              $reports Reports by id.
			 */
			public function __construct(private $test, private array $options, private array $reports) {
			}

			public function create(...$args) {
				$this->test->record(['reports.create', $args]);
				if ($this->options['createThrows'] === true) {
					throw new RuntimeException('create failed');
				}

				return (string)$this->options['createReturns'];
			}

			public function read($reportId) {
				$this->test->record(['reports.read', $reportId]);
				if (in_array($reportId, $this->options['readThrows'], true) === true) {
					throw new RuntimeException('read failed');
				}

				return ($this->reports[$reportId] ?? false);
			}

			public function index() {
				$this->test->record(['reports.index']);
				if ($this->options['index'] === 'throws') {
					throw new RuntimeException('index failed');
				}

				$object = new stdClass();
				$object->id = 3;
				$object->name = 'Object Revenue';
				return [['id' => 1, 'name' => 'Revenue', 'type' => 2, 'subheader' => 'Sub'], ['id' => 0, 'name' => 'No id'], ['id' => '2', 'type' => '1'], $object, ['name' => 'Missing id']];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($options, $reports) {
				$this->log[] = ['container.get', $id];
				if ($options['service'] === 'throws') {
					throw new RuntimeException('not resolvable');
				}

				return $reports;
			}
		);
		return $container;
	}//end container()

	/**
	 * Append to the call log (used by the ReportService stand-in).
	 *
	 * @param array<int, mixed> $entry The entry.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()

	/**
	 * Logger recording every call.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					$this->log[] = ['log.'.$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
