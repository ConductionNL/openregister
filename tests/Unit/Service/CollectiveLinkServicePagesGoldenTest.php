<?php

/**
 * Golden test for the Collectives page picker of CollectiveLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the rows, page lookups and log lines of getAvailablePages().
 *
 * @covers \OCA\OpenRegister\Service\CollectiveLinkService
 * @uses   \OCA\OpenRegister\Service\PageSearch
 */
class CollectiveLinkServicePagesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/collective-link-service-pages-golden.json';

	private array $calls = [];

	public static function setUpBeforeClass(): void {
		foreach (['CollectiveService', 'PageService'] as $name) {
			if (class_exists('OCA\\Collectives\\Service\\' . $name) === false) {
				class_alias(\stdClass::class, 'OCA\\Collectives\\Service\\' . $name);
			}
		}
	}

	/**
	 * Cases: [search, uid, collectives (id => [name, pages] or 'throws'), collective listing throws].
	 */
	private static function cases(): array {
		$pages = [
			['title' => 'Meeting Notes', 'id' => 11, 'timestamp' => 1700000000, 'emoji' => '📝'],
			['title' => 'Budget', 'id' => '12', 'timestamp' => 0, 'emoji' => null],
			['title' => 'notes archive', 'id' => 13, 'timestamp' => 'throws', 'emoji' => 'throws'],
			['title' => 'throws', 'id' => 14, 'timestamp' => 5, 'emoji' => 'x'],
			['title' => 'Old', 'id' => 15, 'timestamp' => -3, 'emoji' => 'y'],
		];
		$two = [1 => ['Team', $pages], 2 => 'throws', 3 => ['Empty', []], 4 => ['Side', [$pages[1], $pages[0]]]];
		return [
			'no user' => [null, null, $two, false],
			'listing fails' => [null, 'alice', $two, true],
			'no collectives' => [null, 'alice', [], false],
			'all pages' => [null, 'alice', $two, false],
			'empty search' => ['', 'alice', $two, false],
			'search lower' => ['notes', 'alice', $two, false],
			'search mixed case' => ['NoTeS', 'alice', $two, false],
			'search none' => ['zzz', 'alice', $two, false],
		];
	}//end cases()

	public function testAvailablePagesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$search, $uid, $collectives, $listingThrows]) {
			$this->calls = [];
			$service = $this->makeService($uid, $collectives, $listingThrows);
			$actual[$name] = ['result' => $service->getAvailablePages($search), 'calls' => $this->calls];
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
	}//end testAvailablePagesMatchGolden()

	private function makeService(?string $uid, array $collectives, bool $listingThrows): CollectiveLinkService {
		$test = $this;
		$collectiveService = new class($test, $collectives, $listingThrows) {
			public function __construct(private $test, private array $collectives, private bool $throws) {
			}

			public function getCollectives(string $uid): array {
				$this->test->record('getCollectives(' . $uid . ')');
				if ($this->throws === true) {
					throw new RuntimeException('listing down');
				}

				$out = [];
				foreach ($this->collectives as $id => $spec) {
					$out[] = new class($id, $spec === 'throws' ? 'Broken' : $spec[0]) {
						public function __construct(private int $id, private string $name) {
						}

						public function getId(): int {
							return $this->id;
						}

						public function getName(): string {
							return $this->name;
						}
					};
				}

				return $out;
			}
		};
		$pageService = new class($test, $collectives) {
			public function __construct(private $test, private array $collectives) {
			}

			public function findAll(int $collectiveId, string $uid): array {
				$this->test->record('findAll(' . $collectiveId . ', ' . $uid . ')');
				if ($this->collectives[$collectiveId] === 'throws') {
					throw new RuntimeException('pages down');
				}

				return array_map(fn (array $p) => $this->test->page($p), $this->collectives[$collectiveId][1]);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $class) => str_ends_with($class, 'PageService') ? $pageService : $collectiveService
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$appManager->method('isInstalled')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn (string $route) => '/apps/' . $route);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $m) => $this->record('warning ' . $m));
		$logger->method('debug')->willReturnCallback(fn (string $m) => $this->record('debug ' . $m));
		$mapper = $this->getMockBuilder(CollectiveLinkMapper::class)->disableOriginalConstructor()->getMock();
		return new CollectiveLinkService($mapper, $container, new LinkServiceRuntime($appManager, $session, $logger), $urls);
	}//end makeService()

	public function record(string $call): void {
		$this->calls[] = $call;
	}

	public function page(array $spec): object {
		return new class($spec) {
			public function __construct(private array $spec) {
			}

			private function value(string $key): mixed {
				if ($this->spec[$key] === 'throws') {
					throw new RuntimeException($key . ' unreadable');
				}

				return $this->spec[$key];
			}

			public function getTitle(): mixed {
				return $this->value('title');
			}

			public function getId(): mixed {
				return $this->value('id');
			}

			public function getTimestamp(): mixed {
				return $this->value('timestamp');
			}

			public function getEmoji(): mixed {
				return $this->value('emoji');
			}
		};
	}//end page()
}//end class
