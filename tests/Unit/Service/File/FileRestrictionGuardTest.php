<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Event\FileRestrictionAdmitEvent;
use OCA\OpenRegister\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\File\FileRestrictionStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Who reads a restricted file.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */
class FileRestrictionGuardTest extends TestCase {

	private const RESTRICTED = 42;

	private const OPEN = 43;

	private FileRestrictionStore&MockObject $store;

	private IEventDispatcher&MockObject $dispatcher;

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(FileRestrictionStore::class);
		$this->store->method('findMany')->willReturnCallback(
			static fn (array $ids): array => in_array(self::RESTRICTED, $ids, true) === true
				? [self::RESTRICTED => ['fileId' => self::RESTRICTED, 'users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Prices', 'setBy' => 'pieter', 'setAt' => '']]
				: []
		);
		$this->store->method('find')->willReturnCallback(
			static fn (int $id): ?array => $id === self::RESTRICTED ? ['fileId' => self::RESTRICTED] : null
		);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	/**
	 * A guard asking as one user in some groups, or anonymously.
	 *
	 * @param string|null   $uid    The caller.
	 * @param array<string> $groups Their groups.
	 *
	 * @return FileRestrictionGuard
	 */
	private function guardFor(?string $uid, array $groups = []): FileRestrictionGuard {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new FileRestrictionGuard($this->store, $session, $groupManager, $this->dispatcher, new NullLogger());
	}//end guardFor()

	public function testAFileWithoutRestrictionIsReadable(): void {
		$this->assertTrue($this->guardFor('anna')->mayRead(self::OPEN));
	}//end testAFileWithoutRestrictionIsReadable()

	public function testACallerNotListedIsRefused(): void {
		$this->assertFalse($this->guardFor('anna', ['dossiq-medewerkers'])->mayRead(self::RESTRICTED));
	}//end testACallerNotListedIsRefused()

	public function testAListedUserIsAdmitted(): void {
		$this->assertTrue($this->guardFor('pieter')->mayRead(self::RESTRICTED));
	}//end testAListedUserIsAdmitted()

	public function testAMemberOfAListedGroupIsAdmitted(): void {
		$this->assertTrue($this->guardFor('kees', ['woo-team'])->mayRead(self::RESTRICTED));
	}//end testAMemberOfAListedGroupIsAdmitted()

	public function testAnAdministratorIsAdmitted(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->assertTrue($this->guardFor('root', ['admin'])->mayRead(self::RESTRICTED));
	}//end testAnAdministratorIsAdmitted()

	public function testAnAnonymousCallerIsRefused(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->assertFalse($this->guardFor(null)->mayRead(self::RESTRICTED));
	}//end testAnAnonymousCallerIsRefused()

	public function testAnAppAdmitsACallerThroughTheEvent(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (FileRestrictionAdmitEvent $event): void {
				$this->assertSame(self::RESTRICTED, $event->getFileId());
				$this->assertSame('anna', $event->getUserId());
				$event->admit();
			}
		);

		$this->assertTrue($this->guardFor('anna')->mayRead(self::RESTRICTED));
	}//end testAnAppAdmitsACallerThroughTheEvent()

	public function testAFailingListenerRefuses(): void {
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('down'));
		$this->assertFalse($this->guardFor('anna')->mayRead(self::RESTRICTED));
	}//end testAFailingListenerRefuses()

	public function testALookupFailureRefusesEvenAnUnrestrictedFile(): void {
		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('findMany')->willThrowException(new RuntimeException('db gone'));
		$this->store = $store;

		$this->assertFalse($this->guardFor('anna')->mayRead(self::OPEN));
	}//end testALookupFailureRefusesEvenAnUnrestrictedFile()

	public function testReadableNodesKeepsOnlyWhatTheCallerMayRead(): void {
		$restricted = $this->createMock(File::class);
		$restricted->method('getId')->willReturn(self::RESTRICTED);
		$open = $this->createMock(File::class);
		$open->method('getId')->willReturn(self::OPEN);

		$this->assertSame([$open], $this->guardFor('anna')->readableNodes([$restricted, $open]));
		$this->assertSame([$restricted, $open], $this->guardFor('pieter')->readableNodes([$restricted, $open]));
	}//end testReadableNodesKeepsOnlyWhatTheCallerMayRead()
	public function testIsRestrictedWhateverTheCallerAndOnALookupFailure(): void {
		$this->assertTrue($this->guardFor('pieter')->isRestricted(self::RESTRICTED));
		$this->assertFalse($this->guardFor('pieter')->isRestricted(self::OPEN));

		$store = $this->createMock(FileRestrictionStore::class);
		$store->method('find')->willThrowException(new RuntimeException('db gone'));
		$this->store = $store;
		$this->assertTrue($this->guardFor('pieter')->isRestricted(self::OPEN));
	}//end testIsRestrictedWhateverTheCallerAndOnALookupFailure()
}//end class
