<?php

/**
 * Fallback branches of the note version history service.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Files\Db\NoteVersion;
use OCA\OpenRegister\Files\Db\NoteVersionMapper;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class NoteVersionServiceBehaviourTest extends TestCase {

	private NoteVersionMapper&MockObject $mapper;

	private IUserManager&MockObject $userManager;

	private LoggerInterface&MockObject $logger;

	private NoteVersionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(NoteVersionMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new NoteVersionService(
			$this->mapper,
			$this->createMock(AuditTrailMapperInterface::class),
			$this->userManager,
			$this->logger
		);
	}//end setUp()

	public function testForgetThatFailsLogsAndReportsZero(): void {
		$this->mapper->method('deleteByComments')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('error')
			->with('[NoteVersionService] Versions of 2 deleted notes could not be removed', $this->arrayHasKey('exception'));

		$this->assertSame(0, $this->service->forget([7, 8]));
	}//end testForgetThatFailsLogsAndReportsZero()

	public function testBlankActorsResolveToNoDisplayNameWithoutALookup(): void {
		$version = new NoteVersion();
		$version->setCommentId(3);
		$version->setMessage('text');
		$version->setAuthor('   ');
		$version->setEditedBy('');
		$this->mapper->method('findByComment')->willReturn([$version]);
		$this->userManager->expects($this->never())->method('get');

		$rows = $this->service->versions(3);

		$this->assertNull($rows[0]['authorDisplayName']);
		$this->assertNull($rows[0]['editedByDisplayName']);
	}//end testBlankActorsResolveToNoDisplayNameWithoutALookup()

	public function testSummaryIdsAreDeduplicatedAndCastBeforeTheQuery(): void {
		$this->mapper->expects($this->once())
			->method('summariesFor')
			->with([4, 5])
			->willReturn([5 => ['editedAt' => 'x', 'editedBy' => null, 'versionCount' => 1]]);
		$this->userManager->expects($this->never())->method('get');

		$summaries = $this->service->summaries(['4', 4, 5]);

		$this->assertSame([4, 5], array_keys($summaries));
		$this->assertSame(['editedAt' => 'x', 'editedBy' => null, 'editedByDisplayName' => null, 'versionCount' => 1], $summaries[5]);
		$this->assertSame(0, $summaries[4]['versionCount']);
	}//end testSummaryIdsAreDeduplicatedAndCastBeforeTheQuery()
}//end class
