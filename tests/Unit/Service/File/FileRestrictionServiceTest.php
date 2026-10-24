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
 * @spec openspec/changes/per-file-read-restriction/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use InvalidArgumentException;
use OCA\OpenRegister\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\File\FileRestrictionService;
use OCA\OpenRegister\Service\File\FileRestrictionStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Setting, changing and lifting a restriction.
 *
 * @spec openspec/changes/per-file-read-restriction/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
class FileRestrictionServiceTest extends TestCase {

	private FileRestrictionStore&MockObject $store;

	private FileRestrictionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(FileRestrictionStore::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->service = new FileRestrictionService(
			$this->store,
			$this->createMock(FileRestrictionGuard::class),
			$session,
			new NullLogger()
		);
	}//end setUp()

	public function testARestrictionIsStoredWithWhoSetIt(): void {
		$this->store->expects($this->once())->method('save')->with(
			42,
			['pieter', '1001'],
			['woo-team'],
			'Commercially sensitive prices',
			'pieter',
			$this->isInstanceOf(\DateTimeImmutable::class)
		);
		$this->store->method('find')->willReturn(['fileId' => 42]);

		$stored = $this->service->restrict(42, [' pieter ', 'pieter', '1001', ''], ['woo-team'], ' Commercially sensitive prices ');

		$this->assertSame(['fileId' => 42], $stored);
	}//end testARestrictionIsStoredWithWhoSetIt()

	public function testARestrictionWithoutPrincipalsIsRefused(): void {
		$this->store->expects($this->never())->method('save');
		$this->expectException(InvalidArgumentException::class);
		$this->service->restrict(42, [], [' '], 'Prices');
	}//end testARestrictionWithoutPrincipalsIsRefused()

	public function testARestrictionWithoutReasonIsRefused(): void {
		$this->store->expects($this->never())->method('save');
		$this->expectException(InvalidArgumentException::class);
		$this->service->restrict(42, ['pieter'], [], '  ');
	}//end testARestrictionWithoutReasonIsRefused()

	public function testLiftingAnswersWhatWasThereAndRemovesIt(): void {
		$previous = ['fileId' => 42, 'users' => ['pieter']];
		$this->store->method('find')->willReturn($previous);
		$this->store->expects($this->once())->method('delete')->with(42);

		$this->assertSame($previous, $this->service->lift(42));
	}//end testLiftingAnswersWhatWasThereAndRemovesIt()

	public function testLiftingAnUnrestrictedFileDoesNothing(): void {
		$this->store->method('find')->willReturn(null);
		$this->store->expects($this->never())->method('delete');

		$this->assertNull($this->service->lift(42));
	}//end testLiftingAnUnrestrictedFileDoesNothing()
}//end class
