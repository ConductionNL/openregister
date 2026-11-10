<?php

/**
 * Characterisation golden for the parts of CollectiveLinkService, EmailLinkService and
 * AnalyticsLinkService that the other goldens do not pin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapperInterface;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Pins getAvailableCollectives() and linkPage() of CollectiveLinkService, the
 * cursor paging of EmailLinkService::getLinkedEmails() and the timestamp
 * parsing of AnalyticsLinkService, compared with
 * tests/Unit/Service/fixtures/app-link-services-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\CollectiveLinkService
 * @covers \OCA\OpenRegister\Service\EmailLinkService
 * @covers \OCA\OpenRegister\Service\AnalyticsLinkService
 * @uses   \OCA\OpenRegister\Db\CollectiveLink
 * @uses   \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 * @uses   \OCA\OpenRegister\Service\MailTableQueries
 */
class AppLinkServicesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/app-link-services-golden.json';

	private array $calls = [];

	public static function setUpBeforeClass(): void {
		foreach (['CollectiveService', 'PageService'] as $name) {
			if (class_exists('OCA\\Collectives\\Service\\' . $name) === false) {
				class_alias(\stdClass::class, 'OCA\\Collectives\\Service\\' . $name);
			}
		}
	}//end setUpBeforeClass()

	public function testAppLinkServicesMatchGolden(): void {
		$actual = [];
		foreach ($this->collectiveCases() as $name => [$method, $args, $over]) {
			$actual['collective ' . $name] = $this->outcome(fn () => $this->collectiveService($over + self::collectiveWorld())->$method(...$args));
		}

		foreach (['null' => null, 'empty' => '', 'negative' => '-4', 'number' => '3', 'junk' => 'x'] as $label => $cursor) {
			foreach ([1, 2, 500, 0] as $limit) {
				$actual['email cursor ' . $label . ' limit ' . $limit] = $this->outcome(fn () => $this->emailService(4)->getLinkedEmails('obj-1', $cursor, $limit));
			}
		}

		$analytics = $this->analyticsService();
		foreach (['null' => null, 'empty' => '', 'zero' => 0, 'string zero' => '0', 'epoch' => 1700000000, 'iso' => '2026-01-02T03:04:05+00:00', 'garbage' => 'not a date'] as $label => $value) {
			$actual['analytics timestamp ' . $label] = $this->outcome(fn () => (new ReflectionMethod(AnalyticsLinkService::class, 'parseTimestamp'))->invoke($analytics, $value));
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testAppLinkServicesMatchGolden()

	public function record(string $call): void {
		$this->calls[] = $call;
	}//end record()

	private function outcome(callable $act): array {
		$this->calls = [];
		try {
			$result = $this->normalise($act());
		} catch (Throwable $e) {
			$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		return ['result' => $result, 'calls' => $this->calls];
	}//end outcome()

	private function normalise(mixed $value): mixed {
		if ($value instanceof CollectiveLink || $value instanceof EmailLink) {
			$value = $value->jsonSerialize();
		}

		if ($value instanceof DateTime) {
			return $value->format(DATE_ATOM);
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1 && abs(strtotime($value) - time()) < 3600) {
			return 'NOW';
		}

		return $value;
	}//end normalise()

	private static function collectiveWorld(): array {
		return [
			'uid' => 'alice',
			'enabled' => true,
			'services' => true,
			'listThrows' => false,
			'duplicate' => false,
			'collectives' => [
				['id' => 1, 'name' => 'Team', 'emoji' => '🚀', 'pages' => [11 => ['title' => 'Notes', 'timestamp' => 1700000000, 'emoji' => '📝']]],
				['id' => 'throws'],
				['id' => 2, 'name' => 'Side', 'emoji' => null, 'pages' => [12 => ['title' => 'Plan', 'timestamp' => 0, 'emoji' => 'throws']]],
			],
		];
	}//end collectiveWorld()

	private function collectiveCases(): array {
		$target = (new LinkTarget('obj-1', 3))->inSchema(4);
		return [
			'available' => ['getAvailableCollectives', [], []],
			'available no user' => ['getAvailableCollectives', [], ['uid' => null]],
			'available off' => ['getAvailableCollectives', [], ['enabled' => false]],
			'available no service' => ['getAvailableCollectives', [], ['services' => false]],
			'available listing throws' => ['getAvailableCollectives', [], ['listThrows' => true]],
			'link first collective' => ['linkPage', [$target, 11], []],
			'link second collective' => ['linkPage', [$target, 12], []],
			'link missing page' => ['linkPage', [$target, 99], []],
			'link listing throws' => ['linkPage', [$target, 11], ['listThrows' => true]],
			'link no service' => ['linkPage', [$target, 11], ['services' => false, 'enabled' => true]],
			'link duplicate' => ['linkPage', [$target, 11], ['duplicate' => true]],
			'link off' => ['linkPage', [$target, 11], ['enabled' => false]],
			'link no user' => ['linkPage', [$target, 11], ['uid' => null]],
		];
	}//end collectiveCases()

	public function bag(string $name, array $spec): object {
		return new class($this, $name, $spec) {
			public function __construct(private $test, private string $name, private array $spec) {
			}

			public function __call(string $method, array $args): mixed {
				$key = lcfirst(substr($method, 3));
				$value = $this->spec[$key] ?? null;
				if ($value === 'throws') {
					throw new RuntimeException($this->name . ' ' . $key . ' unreadable');
				}

				return $value;
			}
		};
	}//end bag()

	private function collectiveService(array $w): CollectiveLinkService {
		$test = $this;
		$collectiveService = new class($test, $w) {
			public function __construct(private $test, private array $w) {
			}

			public function getCollectives(string $uid): array {
				$this->test->record('getCollectives(' . $uid . ')');
				if ($this->w['listThrows'] === true) {
					throw new RuntimeException('listing down');
				}

				return array_map(fn (array $c) => $this->test->bag('collective', $c), $this->w['collectives']);
			}
		};
		$pageService = new class($test, $w) {
			public function __construct(private $test, private array $w) {
			}

			public function find(int $collectiveId, int $pageId, string $uid): object {
				$this->test->record('find(' . $collectiveId . ', ' . $pageId . ', ' . $uid . ')');
				foreach ($this->w['collectives'] as $collective) {
					if (($collective['id'] ?? null) === $collectiveId && isset($collective['pages'][$pageId]) === true) {
						return $this->test->bag('page', $collective['pages'][$pageId] + ['id' => $pageId]);
					}
				}

				throw new RuntimeException('page ' . $pageId . ' not in ' . $collectiveId);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($w, $pageService, $collectiveService) {
				$this->record('container.get(' . $class . ')');
				if ($w['services'] === false) {
					throw new RuntimeException('no ' . $class);
				}

				return str_ends_with($class, 'PageService') === true ? $pageService : $collectiveService;
			}
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($w['enabled']);
		$appManager->method('isInstalled')->willReturn($w['enabled']);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn (string $route) => '/apps/' . $route);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(fn (string $m) => $this->record($level . ' ' . $m));
		}

		$mapper = $this->createMock(CollectiveLinkMapperInterface::class);
		$mapper->method('findByObjectAndPage')->willReturnCallback(
			fn (string $uuid, int $pageId) => $this->record('findByObjectAndPage(' . $uuid . ', ' . $pageId . ')') ?? ($w['duplicate'] === true ? new CollectiveLink() : null)
		);
		$mapper->method('insert')->willReturnCallback(fn ($link) => $this->record('insert') ?? $link);
		return new CollectiveLinkService($mapper, $container, new LinkServiceRuntime($appManager, $session, $logger), $urls);
	}//end collectiveService()

	private function emailService(int $rows): EmailLinkService {
		$mapper = $this->createMock(EmailLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willReturnCallback(
			function (string $uuid, int $limit, int $offset) use ($rows) {
				$this->record('findByObjectUuid(' . $uuid . ', ' . $limit . ', ' . $offset . ')');
				$links = [];
				for ($i = $offset; $i < min($rows, $offset + $limit); $i++) {
					$link = new EmailLink();
					$link->setMailMessageId($i + 1);
					$links[] = $link;
				}

				return $links;
			}
		);
		$mapper->method('countByObjectUuid')->willReturn($rows);
		return new EmailLinkService(
			$mapper,
			$this->createMock(IDBConnection::class),
			new LinkServiceRuntime($this->createMock(IAppManager::class), $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class))
		);
	}//end emailService()

	private function analyticsService(): AnalyticsLinkService {
		$constructor = new \ReflectionMethod(AnalyticsLinkService::class, '__construct');
		$args = [];
		foreach ($constructor->getParameters() as $parameter) {
			$args[] = $this->createMock((string)$parameter->getType());
		}

		return new AnalyticsLinkService(...$args);
	}//end analyticsService()
}//end class
