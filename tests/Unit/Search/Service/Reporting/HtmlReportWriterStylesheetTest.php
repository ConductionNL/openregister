<?php

/**
 * Pins the inline stylesheet of the HTML report byte for byte.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Reporting;

use OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter
 */
final class HtmlReportWriterStylesheetTest extends TestCase {

	public function testTheInlineStylesheetIsUnchanged(): void {
		$html = (new HtmlReportWriter())->write(['titel' => 'x'], []);

		$this->assertSame(1, preg_match('#<style>(.*)</style>#s', $html, $match));
		$this->assertSame(1651, strlen($match[1]));
		$this->assertSame('43174a1a4c5b5831637c458803b03df5a8b282e8b4ce95a29217ef1ebbf0f814', hash('sha256', $match[1]));
		$this->assertStringStartsWith("* { box-sizing: border-box; }\nbody {", $match[1]);
		$this->assertStringEndsWith("    .widget { break-inside: avoid; }\n}", $match[1]);
	}//end testTheInlineStylesheetIsUnchanged()
}//end class
