<?php

/**
 * Golden record of the filters FileFormattingHandler::extractFilterParameters() reads from a request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCP\Files\Lock\ILockManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\File\FileFormattingHandler
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class FileFilterParametersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-filter-parameters-golden.json';

	private static function requests(): array {
		return [
			'none' => [],
			'everything as strings' => [
				'_hasLabels' => '1', '_noLabels' => '0', 'labels' => 'a, b ,c', 'extension' => ' pdf ', 'extensions' => 'jpg , PNG',
				'minSize' => '10', 'maxSize' => '20kb', 'title' => ' Report ', 'search' => ' q ', 'page' => 2,
			],
			'arrays kept as given' => ['labels' => [' x ', 'y'], 'extensions' => ['a']],
			'scalars that are neither' => ['labels' => 5, 'extensions' => true],
			'nulls ignored' => ['labels' => null, 'title' => null, 'minSize' => null, '_hasLabels' => null],
			'falsy kept' => ['_hasLabels' => false, 'minSize' => 0, 'title' => '', 'extension' => ''],
			'underscore search' => ['_search' => ' alt '],
			'search wins over underscore search' => ['search' => 'main', '_search' => 'alt'],
		];
	}//end requests()

	public function testFilterParametersMatchGolden(): void {
		$handler = new FileFormattingHandler(
			$this->createMock(TaggingHandler::class),
			$this->createMock(FileSharingHandler::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ILockManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);
		$method = new \ReflectionMethod($handler, 'extractFilterParameters');
		$actual = [];
		foreach (self::requests() as $name => $request) {
			$actual[$name] = $method->invoke($handler, $request);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testFilterParametersMatchGolden()
}//end class
