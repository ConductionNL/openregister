<?php

/**
 * FileRestrictionGuardMemoGoldenTest
 *
 * Pins how FileRestrictionGuard::readable() reaches its verdicts: id
 * normalisation, the per-caller memo, one lookup for the unknown ids only,
 * the caller's groups read once and only for a restricted file, the
 * refusal of every unknown file when the lookup fails (not memoised), and
 * the allowed and refused verdicts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Files\Service\File\FileRestrictionCaller;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden lookups, memo use and verdicts of FileRestrictionGuard::readable().
 */
final class FileRestrictionGuardMemoGoldenTest extends TestCase {

	/**
	 * Store lookups, group reads and log lines, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * Whether the next store lookup fails.
	 *
	 * @var bool
	 */
	private bool $storeFails = false;

	/**
	 * The signed-in uid; null when anonymous.
	 *
	 * @var string|null
	 */
	private ?string $uid = 'anna';

	/**
	 * The guard over recording fakes. File 1 is restricted to anna, 2 to the staff group, 3 is open.
	 *
	 * @return FileRestrictionGuard
	 */
	private function guard(): FileRestrictionGuard {
		$restrictions = [
			1 => ['fileId' => 1, 'users' => ['anna'], 'groups' => []],
			2 => ['fileId' => 2, 'users' => [], 'groups' => ['staff']],
		];
		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('findMany')->willReturnCallback(
			function (array $fileIds) use ($restrictions): array {
				$this->events[] = ['findMany', $fileIds];
				if ($this->storeFails === true) {
					throw new RuntimeException('db down');
				}

				return array_intersect_key($restrictions, array_flip($fileIds));
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->uid === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(
			function (IUser $user): array {
				$this->events[] = ['groups', $user->getUID()];
				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->events[] = ['warning', $message, $context['error'] ?? null];
			}
		);

		return new FileRestrictionGuard(
			store: $store,
			caller: new FileRestrictionCaller(userSession: $session, groupManager: $groups),
			dispatcher: $this->createMock(IEventDispatcher::class),
			logger: $logger
		);
	}//end guard()

	/**
	 * Ids are normalised and deduplicated; an empty list asks nothing.
	 *
	 * @return void
	 */
	public function testNormalisationAndEmpty(): void {
		$guard = $this->guard();

		self::assertSame([], $guard->readable(fileIds: []));
		self::assertSame([3 => true, 1 => true, 2 => false], $guard->readable(fileIds: ['3', 1, 3, '2', 1]));
		self::assertSame([['findMany', [3, 1, 2]], ['groups', 'anna']], $this->events);
	}//end testNormalisationAndEmpty()

	/**
	 * Known verdicts come from the memo; only unknown ids are looked up; groups are read once per lookup.
	 *
	 * @return void
	 */
	public function testMemoAndSingleLookup(): void {
		$guard = $this->guard();

		self::assertSame([3 => true], $guard->readable(fileIds: [3]));
		self::assertSame([3 => true, 1 => true, 2 => false], $guard->readable(fileIds: [3, 1, 2]));
		self::assertSame([2 => false, 1 => true, 3 => true], $guard->readable(fileIds: [2, 1, 3]));
		self::assertTrue($guard->mayRead(fileId: 1));

		self::assertSame([['findMany', [3]], ['findMany', [1, 2]], ['groups', 'anna']], $this->events);
	}//end testMemoAndSingleLookup()

	/**
	 * The memo is per caller, and forget() clears it.
	 *
	 * @return void
	 */
	public function testMemoIsPerCaller(): void {
		$guard = $this->guard();
		$guard->readable(fileIds: [1]);
		$this->uid = 'bob';
		self::assertSame([1 => false], $guard->readable(fileIds: [1]));
		$this->uid = null;
		self::assertSame([1 => false, 3 => true], $guard->readable(fileIds: [1, 3]));
		$guard->forget();
		$this->uid = 'anna';
		self::assertSame([1 => true], $guard->readable(fileIds: [1]));

		self::assertSame(
			[
				['findMany', [1]],
				['groups', 'anna'],
				['findMany', [1]],
				['groups', 'bob'],
				['findMany', [1, 3]],
				['findMany', [1]],
				['groups', 'anna'],
			],
			$this->events
		);
	}//end testMemoIsPerCaller()

	/**
	 * A failing lookup refuses every unknown file, keeps memoised verdicts, and is not memoised itself.
	 *
	 * @return void
	 */
	public function testALookupFailureRefusesTheUnknownFiles(): void {
		$guard = $this->guard();
		$guard->readable(fileIds: [3]);
		$this->storeFails = true;

		self::assertSame([3 => true, 1 => false, 2 => false], $guard->readable(fileIds: [3, 1, 2]));

		$this->storeFails = false;
		self::assertSame([1 => true], $guard->readable(fileIds: [1]));

		self::assertSame(
			[
				['findMany', [3]],
				['findMany', [1, 2]],
				['warning', '[FileRestrictionGuard] Could not read file restrictions, refusing the files', 'db down'],
				['findMany', [1]],
				['groups', 'anna'],
			],
			$this->events
		);
	}//end testALookupFailureRefusesTheUnknownFiles()
}//end class
