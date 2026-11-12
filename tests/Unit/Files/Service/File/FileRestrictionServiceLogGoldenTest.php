<?php

/**
 * FileRestrictionServiceLogGoldenTest
 *
 * Pins what FileRestrictionService::restrict() records and answers for an
 * allowed restriction: the saved restriction with who set it, the log line
 * with the number of public links revoked, and the stored row it returns.
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

use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\FileRestriction;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionRecords;
use OCA\OpenRegister\Files\Service\File\FileRestrictionRequest;
use OCA\OpenRegister\Files\Service\File\FileRestrictionService;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record, log line and answer of an allowed FileRestrictionService::restrict().
 */
final class FileRestrictionServiceLogGoldenTest extends TestCase {

	/**
	 * Writes and log lines, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The service over recording fakes, signed in as pieter.
	 *
	 * @param array $revoked What depublishing the file answers.
	 *
	 * @return FileRestrictionService
	 */
	private function service(array $revoked): FileRestrictionService {
		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('save')->willReturnCallback(
			function (int $fileId, FileRestriction $restriction): void {
				$this->events[] = ['save', $fileId, $restriction->users, $restriction->groups, $restriction->reason, $restriction->setBy];
			}
		);
		$store->method('find')->willReturnCallback(
			function (int $fileId): ?array {
				$this->events[] = ['find', $fileId];
				return ['fileId' => $fileId, 'stored' => true];
			}
		);
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('forget')->willReturnCallback(
			function (): void {
				$this->events[] = ['forget'];
			}
		);
		$files = $this->createMock(FileMapper::class);
		$files->method('depublishFile')->willReturnCallback(
			function (int $fileId) use ($revoked): array {
				$this->events[] = ['depublish', $fileId];
				return $revoked;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['line']);
				$this->events[] = ['info', $message, $context];
			}
		);

		return new FileRestrictionService(
			records: new FileRestrictionRecords(store: $store, guard: $guard),
			userSession: $session,
			logger: $logger,
			fileMapper: $files
		);
	}//end service()

	/**
	 * Saved with who set it, the guard forgets, links go, the line logs the count, the stored row answers.
	 *
	 * @return void
	 */
	public function testAllowedRestriction(): void {
		$answer = $this->service(revoked: ['deleted_shares' => 2])->restrict(
			new FileRestrictionRequest(fileId: 9, users: [' anna ', 'anna'], groups: ['staff'], reason: ' prices ')
		);
		$this->service(revoked: [])->restrict(new FileRestrictionRequest(fileId: 4, users: [], groups: ['g'], reason: 'r'));

		$file = (string)(new \ReflectionClass(FileRestrictionService::class))->getFileName();
		self::assertSame(['fileId' => 9, 'stored' => true], $answer);
		self::assertSame(
			[
				['save', 9, ['anna'], ['staff'], 'prices', 'pieter'],
				['forget'],
				['depublish', 9],
				['info', '[FileRestrictionService] Restricted a file', ['file' => $file, 'fileId' => 9, 'setBy' => 'pieter', 'publicLinksRevoked' => 2]],
				['find', 9],
				['save', 4, [], ['g'], 'r', 'pieter'],
				['forget'],
				['depublish', 4],
				['info', '[FileRestrictionService] Restricted a file', ['file' => $file, 'fileId' => 4, 'setBy' => 'pieter', 'publicLinksRevoked' => 0]],
				['find', 4],
			],
			$this->events
		);
	}//end testAllowedRestriction()
}//end class
