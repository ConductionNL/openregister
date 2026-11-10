<?php

/**
 * ObjectFolderUploadedGoldenTest
 *
 * Pins how ObjectFolderResponder::uploaded() answers an upload batch: status
 * and body for all stored, some stored, none stored because every name was
 * taken, none stored for another reason, and an empty batch.
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

use InvalidArgumentException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectFolderConflictException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Files\Service\File\ObjectFolderResponder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden answers of ObjectFolderResponder::uploaded().
 */
final class ObjectFolderUploadedGoldenTest extends TestCase {

	/**
	 * Answer a batch and return [status, data].
	 *
	 * @param array $outcome The stored and rejected files.
	 * @param int   $total   How many were sent.
	 *
	 * @return array
	 */
	private function answer(array $outcome, int $total): array {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		$response = (new ObjectFolderResponder($l10n, new NullLogger()))->uploaded(outcome: $outcome, total: $total);
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Every outcome shape.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$taken = ['name' => 'a.pdf', 'error' => 'exists', 'conflict' => true];
		$bad = ['name' => 'b.exe', 'error' => 'Not allowed', 'conflict' => false];
		$stored = [['id' => 1, 'name' => 'c.pdf']];
		$takenOut = ['name' => 'a.pdf', 'error' => 'T:A file or folder with that name is already here'];
		$badOut = ['name' => 'b.exe', 'error' => 'Not allowed'];

		self::assertSame([201, ['stored' => $stored, 'rejected' => []]], $this->answer(['stored' => $stored, 'rejected' => []], 1));
		self::assertSame([207, ['stored' => $stored, 'rejected' => [$takenOut, $badOut]]], $this->answer(['stored' => $stored, 'rejected' => [$taken, $bad]], 3));
		self::assertSame(
			[409, ['error' => $takenOut['error'], 'stored' => [], 'rejected' => [$takenOut, $takenOut]]],
			$this->answer(['stored' => [], 'rejected' => [$taken, $taken]], 2)
		);
		self::assertSame(
			[400, ['error' => 'Not allowed', 'stored' => [], 'rejected' => [$badOut, $takenOut]]],
			$this->answer(['stored' => [], 'rejected' => [$bad, $taken]], 2)
		);
		self::assertSame(
			[409, ['error' => 'T:The upload failed', 'stored' => [], 'rejected' => []]],
			$this->answer(['stored' => [], 'rejected' => []], 0)
		);
		self::assertSame(
			[400, ['error' => 'T:The upload failed', 'stored' => [], 'rejected' => []]],
			$this->answer(['stored' => [], 'rejected' => []], 1)
		);
	}//end testAnswers()

	/**
	 * Every failure answer: refusals, bad requests, conflicts, and the logged 500.
	 *
	 * @return void
	 */
	public function testFailureAnswers(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context['exception']->getMessage()];
			}
		);
		$responder = new ObjectFolderResponder($l10n, $logger);
		$answer = static function (\Throwable $e) use ($responder): array {
			$response = $responder->failure($e);
			return [$response->getStatus(), $response->getData()];
		};

		self::assertSame([404, ['error' => 'T:Object not found']], $answer(new ObjectFileAccessDeniedException(ObjectFileAccessDeniedException::NOT_READABLE)));
		self::assertSame([403, ['error' => 'T:You do not have access to this object']], $answer(new ObjectFileAccessDeniedException(ObjectFileAccessDeniedException::NOT_CHANGEABLE)));
		self::assertSame([400, ['error' => 'T:The path is not a folder of this object']], $answer(new InvalidArgumentException('x', ObjectFolderBrowser::BAD_PATH)));
		self::assertSame([400, ['error' => 'T:This name cannot be used']], $answer(new InvalidArgumentException('x', 7)));
		self::assertSame([404, ['error' => 'T:Not found']], $answer(new NotFoundException('x')));
		$frozen = new ObjectStateWriteException('Frozen');
		self::assertSame([409, $frozen->toResponseBody()], $answer($frozen));
		self::assertSame([409, ['error' => 'T:A file or folder with that name is already here']], $answer(new ObjectFolderConflictException('x', ObjectFolderBrowser::TAKEN)));
		self::assertSame([409, ['error' => 'T:A file in this folder is locked by someone else']], $answer(new ObjectFolderConflictException('x', ObjectFolderBrowser::LOCKED)));
		self::assertSame([500, ['error' => 'T:Internal server error']], $answer(new RuntimeException('disk on fire')));
		self::assertSame([['[ObjectFolder] disk on fire', 'disk on fire']], $logged);
	}//end testFailureAnswers()
}//end class
