<?php

/**
 * A parser failure on the output PDF is logged by its class, never its message.
 *
 * The parser's message can quote the document it failed on, so it stays out
 * of the log.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Pdf;

use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\Pdf\SerialisedPdf;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer
 */
class PdfTextReplacerParserFailureLogTest extends TestCase {

	public function testTheParserFailureIsLoggedWithoutItsMessage(): void {
		$contexts = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$contexts): void {
				$contexts[] = $context;
			}
		);

		$replacer = new PdfTextReplacer(logger: $logger);
		$result = (new ReflectionMethod($replacer, 'reExtract'))->invoke($replacer, new SerialisedPdf(bytes: 'not a pdf: Jane Doe'));

		$this->assertNull($result);
		$logged = array_values(array_filter($contexts, static fn (array $context): bool => ($context['stage'] ?? null) === 'validate.extract'));
		$this->assertCount(1, $logged);
		$this->assertArrayHasKey('parser_exception', $logged[0]);
		$this->assertArrayNotHasKey('parser_exception_msg', $logged[0]);
		$this->assertStringNotContainsString('Jane Doe', (string)json_encode($logged[0]));
	}//end testTheParserFailureIsLoggedWithoutItsMessage()
}//end class
