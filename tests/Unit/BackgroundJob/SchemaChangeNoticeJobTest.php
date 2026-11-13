<?php

/**
 * SchemaChangeNoticeJob: sends the notice its argument names.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\BackgroundJob\SchemaChangeNoticeJob;
use OCA\OpenRegister\Service\Schema\SchemaChangeNoticeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\SchemaChangeNoticeJob
 * @uses   \OCA\OpenRegister\Service\Schema\SchemaChangeNoticeService
 */
final class SchemaChangeNoticeJobTest extends TestCase {

	private SchemaChangeNoticeService&MockObject $notices;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->notices = $this->createMock(SchemaChangeNoticeService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function runJob(mixed $argument): void {
		$job = new SchemaChangeNoticeJob($this->createMock(ITimeFactory::class), $this->notices, $this->logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testTheEntryAndTheCleanedNoteAreSent(): void {
		$this->notices->expects($this->once())->method('send')->with(77, 'Use omschrijving')->willReturn(2);
		$this->logger->expects($this->once())->method('info')->with($this->anything(), ['changelogId' => 77, 'told' => 2]);

		$this->runJob(['changelogId' => 77, 'note' => '<i>Use omschrijving</i>']);
	}//end testTheEntryAndTheCleanedNoteAreSent()

	public function testAMissingEntryIdSendsNothing(): void {
		$this->notices->expects($this->never())->method('send');
		$this->logger->expects($this->once())->method('warning');

		$this->runJob(['note' => 'x']);
	}//end testAMissingEntryIdSendsNothing()

	public function testAnEntryThatIsGoneIsLoggedNotThrown(): void {
		$this->notices->method('send')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('no longer exists'));

		$this->runJob(['changelogId' => 5]);
	}//end testAnEntryThatIsGoneIsLoggedNotThrown()
}//end class
