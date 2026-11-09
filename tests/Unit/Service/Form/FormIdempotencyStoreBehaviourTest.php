<?php

/**
 * The Idempotency-Key store behind a form submit: the payload binding, the
 * exclusive reservation and its refusal, the one-time no-op warning, the
 * replay only to the same asker, expiry, overwriting, and the purge.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormFileStorage;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * FormIdempotencyStore.
 */
class FormIdempotencyStoreBehaviourTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * File contents by name; null while the folder does not exist.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $files = null;

	/**
	 * Names deleted.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	private ILockingProvider&MockObject $locks;

	private LoggerInterface&MockObject $logger;

	private int $now = self::NOW;

	protected function setUp(): void {
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		(new ReflectionProperty(FormIdempotencyStore::class, 'warned'))->setValue(null, false);
	}//end setUp()

	protected function tearDown(): void {
		(new ReflectionProperty(FormIdempotencyStore::class, 'warned'))->setValue(null, false);
	}//end tearDown()

	private function store(?ILockingProvider $locks = null): FormIdempotencyStore {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FormIdempotencyStore(new FormFileStorage($this->appData(), $time), $locks ?? $this->locks, $l10n, $this->logger);
	}//end store()

	private function appData(): IAppData {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function (string $name): ISimpleFolder {
				$this->assertSame('form-submit-keys', $name);
				if ($this->files === null) {
					throw new NotFoundException();
				}
				return $this->folder();
			}
		);
		$appData->method('newFolder')->willReturnCallback(
			function (): ISimpleFolder {
				$this->files = [];
				return $this->folder();
			}
		);
		return $appData;
	}//end appData()

	private function folder(): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$name]));
		$folder->method('getFile')->willReturnCallback(fn (string $name): ISimpleFile => $this->file($name));
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content): ISimpleFile {
				$this->files[$name] = (string)$content;
				return $this->file($name);
			}
		);
		$folder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map(fn (string $name): ISimpleFile => $this->file($name), array_keys($this->files ?? []))
		);
		return $folder;
	}//end folder()

	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name]);
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($name): void {
				$this->files[$name] = (string)$content;
			}
		);
		$file->method('delete')->willReturnCallback(
			function () use ($name): void {
				unset($this->files[$name]);
				$this->deleted[] = $name;
			}
		);
		return $file;
	}//end file()

	private static function entryName(string $scope, string $key): string {
		return hash('sha256', $scope."\0".$key).'.json';
	}//end entryName()

	public function testTheBindingIgnoresKeyOrderButNotListOrderOrSubject(): void {
		$store = $this->store();

		$binding = $store->bindingFor('alice', ['b' => 1, 'a' => ['y' => 2, 'x' => [3, 4]]]);

		$this->assertSame($binding, $store->bindingFor('alice', ['a' => ['x' => [3, 4], 'y' => 2], 'b' => 1]));
		$this->assertNotSame($binding, $store->bindingFor('alice', ['a' => ['x' => [4, 3], 'y' => 2], 'b' => 1]));
		$this->assertNotSame($binding, $store->bindingFor('bob', ['b' => 1, 'a' => ['y' => 2, 'x' => [3, 4]]]));
		$this->assertSame(hash('sha256', "alice\0".'{"a":{"x":[3,4],"y":2},"b":1}'), $binding);
	}//end testTheBindingIgnoresKeyOrderButNotListOrderOrSubject()

	public function testAReservationTakesAnExclusiveLockOnTheHashedKeyAndReleaseFreesIt(): void {
		$path = 'openregister/form-submit-key/'.hash('sha256', "form-1\0key-1");
		$this->locks->expects($this->once())->method('acquireLock')->with($path, ILockingProvider::LOCK_EXCLUSIVE);
		$this->locks->expects($this->once())->method('releaseLock')->with($path, ILockingProvider::LOCK_EXCLUSIVE);
		$this->logger->expects($this->never())->method('warning');
		$store = $this->store();

		$store->reserve('form-1', 'key-1');
		$store->release('form-1', 'key-1');

		$this->assertFalse($store->reservationIsANoop());
	}//end testAReservationTakesAnExclusiveLockOnTheHashedKeyAndReleaseFreesIt()

	public function testAKeyAlreadyReservedIsRefusedWithAConflict(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException('busy'));

		try {
			$this->store()->reserve('form-1', 'key-1');
			$this->fail('A reserved key was reserved twice.');
		} catch (FormSubmitRefusedException $e) {
			$this->assertSame('This form is already being sent. Please wait a moment.', $e->getMessage());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testAKeyAlreadyReservedIsRefusedWithAConflict()

	public function testReleasingALockThatIsGoneIsQuiet(): void {
		$this->locks->expects($this->once())->method('releaseLock')->willThrowException(new RuntimeException('gone'));

		$this->store()->release('form-1', 'key-1');

		$this->assertSame([], $this->deleted);
	}//end testReleasingALockThatIsGoneIsQuiet()

	public function testANoopLockingProviderIsWarnedAboutOncePerProcess(): void {
		$noop = new Fakes\NoopLockingProvider();
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringStartsWith('[FormIdempotencyStore] The locking provider is a no-op')
		);
		$store = $this->store($noop);

		$this->assertTrue($store->reservationIsANoop());
		$store->reserve('form-1', 'a');
		$store->reserve('form-1', 'b');
		$this->store($noop)->reserve('form-2', 'c');
	}//end testANoopLockingProviderIsWarnedAboutOncePerProcess()

	public function testARememberedAnswerIsReplayedOnlyToTheSameAsker(): void {
		$store = $this->store();
		$this->assertNull($store->find('form-1', 'key-1', 'bind-a'));

		$store->remember('form-1', 'key-1', ['uuid' => 'o-1'], 'bind-a');

		$this->assertSame(['uuid' => 'o-1'], $store->find('form-1', 'key-1', 'bind-a'));
		$this->assertSame(
			['expiresAt' => self::NOW + 86400, 'binding' => 'bind-a', 'response' => ['uuid' => 'o-1']],
			json_decode($this->files[self::entryName('form-1', 'key-1')], true)
		);
		try {
			$store->find('form-1', 'key-1', 'bind-b');
			$this->fail('Another asker received the stored answer.');
		} catch (FormSubmitRefusedException $e) {
			$this->assertSame('This Idempotency-Key was already used for another submission.', $e->getMessage());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testARememberedAnswerIsReplayedOnlyToTheSameAsker()

	public function testRememberingTwiceOverwritesTheEntry(): void {
		$store = $this->store();
		$store->remember('form-1', 'key-1', ['uuid' => 'o-1'], 'bind-a');
		$this->now += 10;

		$store->remember('form-1', 'key-1', ['uuid' => 'o-2'], 'bind-a');

		$this->assertCount(1, $this->files);
		$this->assertSame(['uuid' => 'o-2'], $store->find('form-1', 'key-1', 'bind-a'));
	}//end testRememberingTwiceOverwritesTheEntry()

	public function testAnExpiredOrUnreadableEntryIsDeletedAndNotFound(): void {
		$store = $this->store();
		$store->remember('form-1', 'old', ['uuid' => 'o-1'], 'bind');
		$this->files[self::entryName('form-1', 'bad')] = '{"expiresAt":"soon","response":[]}';
		$this->files[self::entryName('form-1', 'noresponse')] = '{"expiresAt":'.(self::NOW + 999999).',"response":"x"}';
		$this->now = self::NOW + 86400;

		$this->assertNull($store->find('form-1', 'old', 'bind'));
		$this->assertNull($store->find('form-1', 'bad', 'bind'));
		$this->assertNull($store->find('form-1', 'noresponse', 'bind'));
		$this->assertSame([], $this->files);
	}//end testAnExpiredOrUnreadableEntryIsDeletedAndNotFound()

	public function testAnEntryWithoutABindingMatchesNobody(): void {
		$this->files = [self::entryName('form-1', 'legacy') => json_encode(['expiresAt' => self::NOW + 60, 'response' => ['uuid' => 'o']])];

		$this->expectException(FormSubmitRefusedException::class);

		$this->store()->find('form-1', 'legacy', 'bind');
	}//end testAnEntryWithoutABindingMatchesNobody()

	public function testPurgeDeletesExpiredAndUnreadableEntriesAndKeepsLiveOnes(): void {
		$this->files = [
			'live.json' => json_encode(['expiresAt' => self::NOW + 1, 'binding' => 'b', 'response' => []]),
			'edge.json' => json_encode(['expiresAt' => self::NOW, 'binding' => 'b', 'response' => []]),
			'junk.json' => 'not json',
		];

		$this->assertSame(2, $this->store()->purge());
		$this->assertSame(['live.json'], array_keys($this->files));
		$this->assertSame(['edge.json', 'junk.json'], $this->deleted);
	}//end testPurgeDeletesExpiredAndUnreadableEntriesAndKeepsLiveOnes()

	public function testWithoutAFolderThereIsNothingToFindOrPurge(): void {
		$store = $this->store();

		$this->assertNull($store->find('form-1', 'key-1', 'bind'));
		$this->assertSame(0, $store->purge());
		$this->assertNull($this->files);
	}//end testWithoutAFolderThereIsNothingToFindOrPurge()
}//end class
