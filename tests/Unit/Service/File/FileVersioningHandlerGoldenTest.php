<?php

/**
 * FileVersioningHandlerGoldenTest
 *
 * Golden tests that pin what
 * {@see \OCA\OpenRegister\Service\File\FileVersioningHandler} returns, throws
 * and logs today for listing and restoring versions, including the path that
 * talks to the files_versions version manager. The manager name is
 * declared in each test's own process so it never leaks into other tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileVersionsPlatform;
use Exception;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins FileVersioningHandler's listing, restore and logging.
 */
#[RunTestsInSeparateProcesses]
class FileVersioningHandlerGoldenTest extends TestCase {

	private const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

	/**
	 * Recorded log lines as "level: message".
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Services the container hands out, by id.
	 *
	 * @var array<string,mixed>
	 */
	private array $services = [];

	/**
	 * Whether files_versions is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled = true;

	/**
	 * The session user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * Fix the timezone and start as alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		date_default_timezone_set('UTC');

		$this->sessionUser = $this->user(uid: 'alice');
	}//end setUp()

	/**
	 * Declare the manager name in this test's process, as Nextcloud does (an
	 * interface) or as a class, which is the only way the handler's
	 * class_exists() check lets the manager path run.
	 *
	 * @param string $kind `interface` or `class`.
	 *
	 * @return void
	 */
	private static function declareManager(string $kind): void {
		eval('namespace OCA\Files_Versions\Versions; '.$kind.' IVersionManager {}');
	}//end declareManager()

	/**
	 * A user with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Build the handler over the current fakes.
	 *
	 * @return FileVersioningHandler
	 */
	private function handler(): FileVersioningHandler {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'files_versions' && $this->enabled);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$service = ($this->services[$id] ?? null);
				if ($service instanceof Exception) {
					throw $service;
				}

				return $service;
			}
		);
		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			public function __construct(private readonly FileVersioningHandlerGoldenTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->test->record(line: $level.': '.$message);
			}
		};

		return new FileVersioningHandler(new FileVersionsPlatform($this->createMock(IRootFolder::class), $appManager, $container), $session, $logger);
	}//end handler()

	/**
	 * Record one log line.
	 *
	 * @param string $line The line.
	 *
	 * @return void
	 */
	public function record(string $line): void {
		$this->logLines[] = $line;
	}//end record()

	/**
	 * A file with a path (or a throwing path), mtime, size and name.
	 *
	 * @param string|null $path Path, or null to make getPath() throw.
	 *
	 * @return File
	 */
	private function file(?string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getMTime')->willReturn(1700000000);
		$file->method('getSize')->willReturn(42);
		$file->method('getName')->willReturn('besluit.pdf');
		if ($path === null) {
			$file->method('getPath')->willThrowException(new Exception('no path'));
			return $file;
		}

		$file->method('getPath')->willReturn($path);
		return $file;
	}//end file()

	/**
	 * A version with a timestamp and optional label.
	 *
	 * @param int         $timestamp The timestamp.
	 * @param string|null $label     The label, or null for a version without getLabel().
	 *
	 * @return object
	 */
	private static function version(int $timestamp, ?string $label): object {
		if ($label === null) {
			return new class ($timestamp) {
				public function __construct(private readonly int $ts) {
				}

				public function getTimestamp(): int {
					return $this->ts;
				}

				public function getSize(): int {
					return 7;
				}

				public function getSourceFileName(): string {
					return 'oud.pdf';
				}
			};
		}

		return new class ($timestamp, $label) {
			public function __construct(private readonly int $ts, private readonly string $label) {
			}

			public function getTimestamp(): int {
				return $this->ts;
			}

			public function getSize(): int {
				return 10;
			}

			public function getSourceFileName(): string {
				return 'besluit.pdf';
			}

			public function getLabel(): string {
				return $this->label;
			}
		};
	}//end version()

	/**
	 * A fake version manager that records calls.
	 *
	 * @param list<object>|Exception $versions The versions, or an exception getVersionsForFile() throws.
	 *
	 * @return object
	 */
	private static function manager(array|Exception $versions): object {
		return new class ($versions) {
			/**
			 * Recorded calls.
			 *
			 * @var list<string>
			 */
			public array $calls = [];

			/**
			 * Exception rollback() throws, if any.
			 *
			 * @var Exception|null
			 */
			public ?Exception $rollbackFails = null;

			public function __construct(private readonly array|Exception $versions) {
			}

			public function getVersionsForFile(IUser $user, File $file): array {
				$this->calls[] = 'list as '.$user->getUID();
				if ($this->versions instanceof Exception) {
					throw $this->versions;
				}

				return $this->versions;
			}

			public function rollback(object $version): void {
				$this->calls[] = 'rollback '.$version->getTimestamp().' '.$version->getSize();
				if ($this->rollbackFails !== null) {
					throw $this->rollbackFails;
				}
			}
		};
	}//end manager()

	/**
	 * The current-version entry for the fixture file.
	 *
	 * @param string $author The author uid.
	 *
	 * @return array<string,mixed>
	 */
	private static function current(string $author): array {
		return [
			'versionId'         => 'current',
			'timestamp'         => '2023-11-14T22:13:20+00:00',
			'size'              => 42,
			'author'            => $author,
			'authorDisplayName' => $author,
			'label'             => null,
			'isCurrent'         => true,
		];
	}//end current()

	/**
	 * Disabled versioning: a warning listing and a refused restore.
	 *
	 * @return void
	 */
	public function testDisabled(): void {
		$this->enabled = false;
		$handler       = $this->handler();

		self::assertSame(
			['versions' => [], 'warning' => 'File versioning is not enabled on this instance'],
			$handler->listVersions($this->file('/alice/files/a.pdf'))
		);
		self::assertSame(['info: [FileVersioningHandler] files_versions app is not enabled'], $this->logLines);

		try {
			$handler->restoreVersion($this->file('/alice/files/a.pdf'), 'v-1690000000');
			self::fail('expected exception');
		} catch (Exception $e) {
			self::assertSame('File versioning is not enabled on this instance', $e->getMessage());
		}

		self::assertCount(1, $this->logLines);
	}//end testDisabled()

	/**
	 * With the manager declared as the interface Nextcloud ships, the
	 * class_exists() check is false: the manager is never asked, the listing
	 * holds only the current entry and every restore reports "Version not found".
	 *
	 * @return void
	 */
	public function testInterfaceManagerIsNeverAsked(): void {
		self::declareManager('interface');
		$manager = self::manager([self::version(1690000000, 'Concept')]);
		$this->services[self::MANAGER] = $manager;
		$handler = $this->handler();

		self::assertSame(['versions' => [self::current('alice')]], $handler->listVersions($this->file('/alice/files/a.pdf')));
		try {
			$handler->restoreVersion($this->file('/alice/files/a.pdf'), 'v-1690000000');
			self::fail('expected exception');
		} catch (Exception $e) {
			self::assertSame('Version not found', $e->getMessage());
		}

		self::assertSame([], $manager->calls);
		self::assertSame(['error: [FileVersioningHandler] Failed to restore version: Version not found'], $this->logLines);
	}//end testInterfaceManagerIsNeverAsked()

	/**
	 * The listing with a manager: current entry, then backend versions in order.
	 *
	 * @return void
	 */
	public function testListWithManager(): void {
		self::declareManager('class');
		$manager = self::manager([self::version(1690000000, 'Concept'), self::version(1680000000, null)]);
		$this->services[self::MANAGER] = $manager;

		self::assertSame(
			[
				'versions' => [
					self::current('alice'),
					[
						'versionId'         => 'v-1690000000',
						'timestamp'         => '2023-07-22T04:26:40+00:00',
						'size'              => 10,
						'author'            => 'besluit.pdf',
						'authorDisplayName' => 'besluit.pdf',
						'label'             => 'Concept',
						'isCurrent'         => false,
					],
					[
						'versionId'         => 'v-1680000000',
						'timestamp'         => '2023-03-28T10:40:00+00:00',
						'size'              => 7,
						'author'            => 'oud.pdf',
						'authorDisplayName' => 'oud.pdf',
						'label'             => null,
						'isCurrent'         => false,
					],
				],
			],
			$this->handler()->listVersions($this->file('/alice/files/a.pdf'))
		);
		self::assertSame(['list as alice'], $manager->calls);
		self::assertSame([], $this->logLines);
	}//end testListWithManager()

	/**
	 * Who the versions are listed as, and when only the current entry comes back.
	 *
	 * @return void
	 */
	public function testListUserAndFallbacks(): void {
		self::declareManager('class');
		$manager = self::manager([self::version(1690000000, 'x')]);
		$this->services[self::MANAGER] = $manager;

		// A path that cannot be read falls back to the session user.
		self::assertCount(2, $this->handler()->listVersions($this->file(null))['versions']);

		// An object's file is listed as the openregister account.
		$owner = $this->createMock(FileOwnershipHandler::class);
		$owner->method('getUser')->willReturn($this->user('openregister'));
		$this->services[FileOwnershipHandlerInterface::class] = $owner;
		self::assertCount(2, $this->handler()->listVersions($this->file('/openregister/files/Open Registers/r/s/o/a.pdf'))['versions']);
		self::assertSame(['list as alice', 'list as openregister'], $manager->calls);

		// The ownership handler failing leaves no user: only the current entry.
		$this->services[FileOwnershipHandlerInterface::class] = new Exception('no owner');
		self::assertSame(['versions' => [self::current('alice')]], $this->handler()->listVersions($this->file('/openregister/files/Open Registers/a.pdf')));

		// No session user on a plain path: only the current entry, authored by 'system'.
		$this->sessionUser = null;
		self::assertSame(['versions' => [self::current('system')]], $this->handler()->listVersions($this->file('/bob/files/a.pdf')));

		// No manager from the container: only the current entry.
		$this->sessionUser = $this->user('alice');
		$this->services[self::MANAGER] = null;
		self::assertSame(['versions' => [self::current('alice')]], $this->handler()->listVersions($this->file('/alice/files/a.pdf')));
		self::assertCount(2, $manager->calls);
		self::assertSame([], $this->logLines);
	}//end testListUserAndFallbacks()

	/**
	 * A failing backend gives an empty listing with the error as warning.
	 *
	 * @return void
	 */
	public function testListBackendFailure(): void {
		self::declareManager('class');
		$this->services[self::MANAGER] = self::manager(new Exception('boom'));
		self::assertSame(
			['versions' => [], 'warning' => 'Failed to retrieve file versions: boom'],
			$this->handler()->listVersions($this->file('/alice/files/a.pdf'))
		);
		self::assertSame(['warning: [FileVersioningHandler] Failed to list versions: boom'], $this->logLines);

		$this->logLines = [];
		$this->services[self::MANAGER] = new Exception('no container entry');
		self::assertSame(
			['versions' => [], 'warning' => 'Failed to retrieve file versions: no container entry'],
			$this->handler()->listVersions($this->file('/alice/files/a.pdf'))
		);
		self::assertSame(['warning: [FileVersioningHandler] Failed to list versions: no container entry'], $this->logLines);
	}//end testListBackendFailure()

	/**
	 * Restoring: id parsing, the first matching version, and the logs.
	 *
	 * @return void
	 */
	public function testRestore(): void {
		self::declareManager('class');
		$manager = self::manager([self::version(1680000000, null), self::version(1690000000, 'a'), self::version(1690000000, null)]);
		$this->services[self::MANAGER] = $manager;
		$handler = $this->handler();

		foreach (['v-0', 'abc', 'v--5', '', 'v-'] as $bad) {
			try {
				$handler->restoreVersion($this->file('/alice/files/a.pdf'), $bad);
				self::fail('expected exception for '.$bad);
			} catch (Exception $e) {
				self::assertSame('Invalid version ID format', $e->getMessage());
			}
		}

		self::assertSame([], $this->logLines);
		self::assertSame([], $manager->calls);

		self::assertTrue($handler->restoreVersion($this->file('/alice/files/a.pdf'), 'v-1690000000'));
		self::assertTrue($handler->restoreVersion($this->file('/alice/files/a.pdf'), '1680000000'));
		self::assertSame(['list as alice', 'rollback 1690000000 10', 'list as alice', 'rollback 1680000000 7'], $manager->calls);
		self::assertSame(
			[
				'info: [FileVersioningHandler] Restored version v-1690000000 for file besluit.pdf',
				'info: [FileVersioningHandler] Restored version 1680000000 for file besluit.pdf',
			],
			$this->logLines
		);
	}//end testRestore()

	/**
	 * Restore failures are logged as errors and rethrown.
	 *
	 * @return void
	 */
	public function testRestoreFailures(): void {
		self::declareManager('class');
		$manager = self::manager([self::version(1690000000, 'a')]);
		$this->services[self::MANAGER] = $manager;
		$handler = $this->handler();

		$messages = [];
		foreach (['v-123', 'v-1690000000'] as $i => $id) {
			if ($i === 1) {
				$manager->rollbackFails = new Exception('locked');
			}

			try {
				$handler->restoreVersion($this->file('/alice/files/a.pdf'), $id);
				self::fail('expected exception');
			} catch (Exception $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->services[self::MANAGER] = null;
		try {
			$handler->restoreVersion($this->file('/alice/files/a.pdf'), 'v-1690000000');
		} catch (Exception $e) {
			$messages[] = $e->getMessage();
		}

		$this->sessionUser = null;
		$this->services[self::MANAGER] = $manager;
		try {
			$handler->restoreVersion($this->file('/alice/files/a.pdf'), 'v-1690000000');
		} catch (Exception $e) {
			$messages[] = $e->getMessage();
		}

		self::assertSame(['Version not found', 'locked', 'Version not found', 'Version not found'], $messages);
		self::assertSame(
			[
				'error: [FileVersioningHandler] Failed to restore version: Version not found',
				'error: [FileVersioningHandler] Failed to restore version: locked',
				'error: [FileVersioningHandler] Failed to restore version: Version not found',
				'error: [FileVersioningHandler] Failed to restore version: Version not found',
			],
			$this->logLines
		);
		self::assertSame(['list as alice', 'list as alice', 'rollback 1690000000 10'], $manager->calls);
	}//end testRestoreFailures()
}//end class
