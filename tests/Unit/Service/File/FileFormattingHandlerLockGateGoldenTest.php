<?php

/**
 * Golden record of what FileFormattingHandler::formatFiles() shows of a lock.
 *
 * An authenticated caller sees the `locked` flag and the lock envelope with its
 * owner, on a formatted file and on the stub of a file that raised
 * LockedException. An anonymous caller sees neither, and the lock manager is
 * never asked. Timestamps depend on the PHP time zone, so they are recorded as
 * present rather than by value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCP\IGroupManager;
use OCA\OpenRegister\Service\File\FileSession;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \OCA\OpenRegister\Service\File\FileFormattingHandler
 */
class FileFormattingHandlerLockGateGoldenTest extends TestCase {

	private const LOCK = ['type' => 'user', 'scope' => 'exclusive', 'owner' => 'alice', 'createdAt' => '~', 'expiresAt' => '~'];

	public function testAuthenticatedCallerSeesTheLockOnFilesAndStubs(): void {
		$recorded = $this->record(authenticated: true, params: []);

		$this->assertSame(['total' => 2, 'page' => 1, 'pages' => 1, 'limit' => 30, 'offset' => 0], $recorded['paging']);
		$this->assertSame(true, $recorded['results'][0]['locked']);
		$this->assertSame(self::LOCK, $recorded['results'][0]['lock']);
		$this->assertSame(['id' => 2, 'title' => 'locked.pdf', 'error' => 'locked', 'locked' => true, 'lock' => self::LOCK], $recorded['results'][1]);
		$this->assertSame(2, $recorded['lookups']);
		$this->assertSame(['info: [FileFormattingHandler] formatFiles: file locked.pdf (ID: 2) is locked, emitting stub entry'], $recorded['log']);
		$this->assertSame(['user', 'alice', 'locked.pdf', '"locked.pdf" is locked', 2], $recorded['logContext']);
	}//end testAuthenticatedCallerSeesTheLockOnFilesAndStubs()

	public function testAnonymousCallerSeesNoLockAndTheManagerIsNotAsked(): void {
		$recorded = $this->record(authenticated: false, params: ['_page' => '2', '_limit' => '1']);

		$this->assertSame(['total' => 2, 'page' => 2, 'pages' => 2, 'limit' => 1, 'offset' => 1], $recorded['paging']);
		$this->assertSame([['id' => 2, 'title' => 'locked.pdf', 'error' => 'locked']], $recorded['results']);
		$this->assertSame(0, $recorded['lookups']);
		$this->assertSame([null, null, 'locked.pdf', '"locked.pdf" is locked', 2], $recorded['logContext']);
	}//end testAnonymousCallerSeesNoLockAndTheManagerIsNotAsked()

	public function testAnonymousFormattedFileCarriesNoLockFields(): void {
		$recorded = $this->record(authenticated: false, params: []);

		$this->assertArrayNotHasKey('locked', $recorded['results'][0]);
		$this->assertArrayNotHasKey('lock', $recorded['results'][0]);
		$this->assertSame(['id' => 2, 'title' => 'locked.pdf', 'error' => 'locked'], $recorded['results'][1]);
		$this->assertSame(0, $recorded['lookups']);
	}//end testAnonymousFormattedFileCarriesNoLockFields()

	public function testLockLookupFailureHidesTheLockButKeepsTheFlagOnTheStub(): void {
		$recorded = $this->record(authenticated: true, params: [], lookupFails: true);

		$this->assertSame(false, $recorded['results'][0]['locked']);
		$this->assertArrayNotHasKey('lock', $recorded['results'][0]);
		$this->assertSame(['id' => 2, 'title' => 'locked.pdf', 'error' => 'locked', 'locked' => true], $recorded['results'][1]);
		$this->assertSame(
			[
				'warning: [FileFormattingHandler] formatLock: lock lookup failed for fileId 1: lock store down',
				'warning: [FileFormattingHandler] formatLock: lock lookup failed for fileId 2: lock store down',
				'info: [FileFormattingHandler] formatFiles: file locked.pdf (ID: 2) is locked, emitting stub entry',
			],
			$recorded['log']
		);
	}//end testLockLookupFailureHidesTheLockButKeepsTheFlagOnTheStub()

	/**
	 * @param array<string, mixed> $params
	 *
	 * @return array<string, mixed>
	 */
	private function record(bool $authenticated, array $params, bool $lookupFails=false): array {
		$log = [];
		$context = [];
		$logger = new class($log, $context) extends AbstractLogger {
			/**
			 * @param array<int, string> $log
			 * @param array<int, mixed>  $context
			 */
			public function __construct(private array &$log, private array &$context) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = $level . ': ' . $message;
				$this->context = [
					($context['lockType'] ?? null),
					($context['lockOwner'] ?? null),
					($context['name'] ?? null),
					($context['reason'] ?? null),
					($context['fileId'] ?? null),
				];
			}
		};

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$lock = $this->createMock(ILock::class);
		$lock->method('getType')->willReturn(ILock::TYPE_USER);
		$lock->method('getScope')->willReturn(ILock::LOCK_EXCLUSIVE);
		$lock->method('getOwner')->willReturn('alice');
		$lock->method('getCreatedAt')->willReturn(1700000000);
		$lock->method('getTimeout')->willReturn(60);

		$lookups = 0;
		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(true);
		$lockManager->method('getLocks')->willReturnCallback(
			function () use (&$lookups, $lock, $lookupFails): array {
				$lookups++;
				if ($lookupFails === true) {
					throw new \Exception('lock store down');
				}

				return [$lock];
			}
		);

		$handler = $this->handler(lockManager: $lockManager, session: $session, logger: $logger);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('findShares')->willReturnCallback(
			function (Node $node): array {
				if ($node->getId() === 2) {
					throw new LockedException('locked.pdf');
				}

				return [];
			}
		);
		$fileService->method('getFileTags')->willReturn([]);
		$handler->setFileService($fileService);

		$files = [$this->node(id: 1, name: 'good.pdf'), $this->node(id: 2, name: 'locked.pdf')];
		$result = $handler->formatFiles(files: $files, requestParams: $params);
		$results = array_map(fn (array $entry): array => $this->masked(entry: $entry), $result['results']);
		unset($result['results']);

		return ['results' => array_values($results), 'paging' => $result, 'lookups' => $lookups, 'log' => $log, 'logContext' => $context];
	}//end record()

	private function handler(ILockManager $lockManager, IUserSession $session, AbstractLogger $logger): FileFormattingHandler {
		return new FileFormattingHandler(
			$lockManager,
			new FileSession($session, $this->createMock(IGroupManager::class), $logger)
		);
	}//end handler()

	/**
	 * @param array<string, mixed> $entry
	 *
	 * @return array<string, mixed>
	 */
	private function masked(array $entry): array {
		foreach (['created', 'modified'] as $key) {
			if (array_key_exists($key, $entry) === true) {
				$entry[$key] = '~';
			}
		}

		if (isset($entry['lock']) === true) {
			$entry['lock']['createdAt'] = '~';
			$entry['lock']['expiresAt'] = '~';
		}

		return $entry;
	}//end masked()

	private function node(int $id, string $name): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->method('getPath')->willReturn('/user/files/' . $name);
		$node->method('getMimetype')->willReturn('application/pdf');
		$node->method('getExtension')->willReturn('pdf');
		$node->method('getSize')->willReturn(1024);
		$node->method('getEtag')->willReturn('etag-' . $id);
		$node->method('getCreationTime')->willReturn(1700000000);
		$node->method('getUploadTime')->willReturn(1700000100);
		return $node;
	}//end node()
}//end class
