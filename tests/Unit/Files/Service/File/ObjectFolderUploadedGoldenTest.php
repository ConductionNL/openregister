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

use OCA\OpenRegister\Files\Service\File\ObjectFolderResponder;
use OCP\IL10N;
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
}//end class
