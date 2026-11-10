<?php

/**
 * FileRestrictionRefusalGoldenTest
 *
 * Golden for the refusals of FileRestrictionService::restrict(): what is
 * refused, that nothing is stored, forgotten or depublished when it is, and
 * how the principals are cleaned when it is not.
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

use InvalidArgumentException;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\FileRestriction;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionRecords;
use OCA\OpenRegister\Files\Service\File\FileRestrictionRequest;
use OCA\OpenRegister\Files\Service\File\FileRestrictionService;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Refused and allowed restrictions.
 */
class FileRestrictionRefusalGoldenTest extends TestCase {

	/**
	 * Run one restriction against mocks that record every write.
	 *
	 * @param int                $fileId The file.
	 * @param array<int, mixed>  $users  Users as given.
	 * @param array<int, mixed>  $groups Groups as given.
	 * @param string             $reason The reason as given.
	 *
	 * @return array{refused: string|null, writes: list<string>, saved: FileRestriction|null}
	 */
	private function restrictWith(int $fileId, array $users, array $groups, string $reason): array {
		$writes = [];
		$saved = null;
		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('save')->willReturnCallback(
			function (int $id, FileRestriction $restriction) use (&$writes, &$saved): void {
				$writes[] = 'save:' . $id;
				$saved = $restriction;
			}
		);
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('forget')->willReturnCallback(function () use (&$writes): void {
			$writes[] = 'forget';
		});
		$files = $this->createMock(FileMapper::class);
		$files->method('depublishFile')->willReturnCallback(function (int $id) use (&$writes): array {
			$writes[] = 'depublish:' . $id;
			return [];
		});

		$service = new FileRestrictionService(
			records: new FileRestrictionRecords(store: $store, guard: $guard),
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger(),
			fileMapper: $files
		);

		$refused = null;
		try {
			$service->restrict(new FileRestrictionRequest(fileId: $fileId, users: $users, groups: $groups, reason: $reason));
		} catch (InvalidArgumentException $e) {
			$refused = $e->getMessage();
		}

		return ['refused' => $refused, 'writes' => $writes, 'saved' => $saved];
	}//end restrictWith()

	/**
	 * Every refusal happens before anything is written.
	 *
	 * @return void
	 */
	public function testRefusalsWriteNothing(): void {
		$cases = [
			[0, ['a'], [], 'r', 'A restriction needs a file'],
			[-3, ['a'], [], 'r', 'A restriction needs a file'],
			[5, [' ', 7, null], [''], 'r', 'A restriction needs at least one user or group'],
			[5, ['a'], [], " \t", 'A restriction needs a reason'],
		];
		foreach ($cases as [$fileId, $users, $groups, $reason, $message]) {
			$outcome = $this->restrictWith($fileId, $users, $groups, $reason);
			self::assertSame($message, $outcome['refused']);
			self::assertSame([], $outcome['writes']);
		}
	}//end testRefusalsWriteNothing()

	/**
	 * An allowed restriction is saved without an acting user, then the guard forgets, then links go.
	 *
	 * @return void
	 */
	public function testAllowedRestrictionOrder(): void {
		$outcome = $this->restrictWith(5, [7, ' b '], ['g', 'g'], ' why ');

		self::assertNull($outcome['refused']);
		self::assertSame(['save:5', 'forget', 'depublish:5'], $outcome['writes']);
		self::assertEquals(new FileRestriction(users: ['b'], groups: ['g'], reason: 'why', setBy: ''), $outcome['saved']);
	}//end testAllowedRestrictionOrder()
}//end class
