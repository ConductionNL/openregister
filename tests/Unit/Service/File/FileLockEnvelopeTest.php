<?php

/**
 * Unit tests for FileLockEnvelope.
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

use OCA\OpenRegister\Service\File\FileLockEnvelope;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\File\FileLockEnvelope
 */
class FileLockEnvelopeTest extends TestCase {

	public function testNoProviderMeansNoEnvelopeAndNoLookup(): void {
		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(false);
		$lockManager->expects($this->never())->method('getLocks');

		$this->assertNull((new FileLockEnvelope($lockManager, $this->createMock(LoggerInterface::class)))->forFile(fileId: 7));
	}//end testNoProviderMeansNoEnvelopeAndNoLookup()

	public function testNoLocksMeansNoEnvelope(): void {
		$this->assertNull($this->envelopeFor(locks: [])->forFile(fileId: 7));
	}//end testNoLocksMeansNoEnvelope()

	public function testFailedLookupIsLoggedAndMeansNoEnvelope(): void {
		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(true);
		$lockManager->method('getLocks')->willThrowException(new \Exception('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertNull((new FileLockEnvelope($lockManager, $logger))->forFile(fileId: 7));
	}//end testFailedLookupIsLoggedAndMeansNoEnvelope()

	public function testUnknownTypeAndScopeAndNoTimeout(): void {
		$envelope = $this->envelopeFor(locks: [$this->lock(type: 99, scope: 99, timeout: 0)])->forFile(fileId: 7);

		$this->assertSame('unknown', $envelope['type']);
		$this->assertSame('unknown', $envelope['scope']);
		$this->assertSame('bob', $envelope['owner']);
		$this->assertNull($envelope['expiresAt']);
	}//end testUnknownTypeAndScopeAndNoTimeout()

	public function testAppTokenAndSharedAliasesAndTimeout(): void {
		$app = $this->envelopeFor(locks: [$this->lock(type: ILock::TYPE_APP, scope: ILock::LOCK_SHARED, timeout: 30)])->forFile(fileId: 7);
		$token = $this->envelopeFor(locks: [$this->lock(type: ILock::TYPE_TOKEN, scope: ILock::LOCK_EXCLUSIVE, timeout: 0)])->forFile(fileId: 7);

		$this->assertSame(['app', 'shared'], [$app['type'], $app['scope']]);
		$this->assertSame(['token', 'exclusive'], [$token['type'], $token['scope']]);
		$this->assertSame(30, strtotime($app['expiresAt']) - strtotime($app['createdAt']));
	}//end testAppTokenAndSharedAliasesAndTimeout()

	/**
	 * @param list<ILock> $locks
	 */
	private function envelopeFor(array $locks): FileLockEnvelope {
		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(true);
		$lockManager->method('getLocks')->willReturn($locks);

		return new FileLockEnvelope($lockManager, $this->createMock(LoggerInterface::class));
	}//end envelopeFor()

	private function lock(int $type, int $scope, int $timeout): ILock {
		$lock = $this->createMock(ILock::class);
		$lock->method('getType')->willReturn($type);
		$lock->method('getScope')->willReturn($scope);
		$lock->method('getOwner')->willReturn('bob');
		$lock->method('getCreatedAt')->willReturn(1700000000);
		$lock->method('getTimeout')->willReturn($timeout);
		return $lock;
	}//end lock()
}//end class
