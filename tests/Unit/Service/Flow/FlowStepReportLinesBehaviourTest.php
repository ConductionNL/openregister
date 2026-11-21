<?php

/**
 * FlowStepReport: a step's own non-list lines value is replaced, not appended to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepReport
 */
class FlowStepReportLinesBehaviourTest extends TestCase {

	public function testANonListLinesValueIsReplacedByTheFirstLine(): void {
		$report = new FlowStepReport();
		$report->put(key: 'lines', value: 'free text');

		$report->line(level: 'shout', message: 'Read 3 objects');

		$lines = $report->take()['lines'];
		self::assertCount(1, $lines);
		self::assertSame('info', $lines[0]['level'], 'an unknown level is recorded as info');
		self::assertSame('Read 3 objects', $lines[0]['message']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/', $lines[0]['at']);
	}//end testANonListLinesValueIsReplacedByTheFirstLine()
}//end class
