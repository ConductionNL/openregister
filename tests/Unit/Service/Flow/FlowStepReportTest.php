<?php

/**
 * A step's own log lines: levels, timestamps, the cap, and the per-hop reset.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowStepReport;
use PHPUnit\Framework\TestCase;

class FlowStepReportTest extends TestCase {

	public function testALineIsTimestampedAndKeepsItsLevel(): void {
		$report = new FlowStepReport();
		$report->line('warning', 'The person has no portal account.');
		$report->line('loud', 'An unknown level reads as info.');

		$lines = $report->take()['lines'];

		$this->assertSame('warning', $lines[0]['level']);
		$this->assertSame('The person has no portal account.', $lines[0]['message']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/', $lines[0]['at']);
		$this->assertSame('info', $lines[1]['level']);
	}

	public function testLinesSitBesideOtherDetailAndClearPerHop(): void {
		$report = new FlowStepReport();
		$report->put('recipients', 2);
		$report->line('success', 'Sent.');

		$detail = $report->take();

		$this->assertSame(2, $detail['recipients']);
		$this->assertCount(1, $detail['lines']);
		$this->assertSame([], $report->take());
	}

	public function testAStepKeepsAtMostTheCapAndSaysHowManyWereLeftOut(): void {
		$report = new FlowStepReport();
		for ($i = 0; $i < (FlowStepReport::MAX_LINES + 3); $i++) {
			$report->line('info', 'Line ' . $i);
		}

		$lines = $report->take()['lines'];

		$this->assertCount(FlowStepReport::MAX_LINES, $lines);
		$this->assertSame('Line ' . (FlowStepReport::MAX_LINES - 2), $lines[FlowStepReport::MAX_LINES - 2]['message']);
		$this->assertSame('4 more lines left out.', $lines[FlowStepReport::MAX_LINES - 1]['message']);

		$report->line('info', 'Next hop.');
		$this->assertSame('Next hop.', $report->take()['lines'][0]['message']);
	}

	public function testALongMessageIsCutToTheCapAndEndsInAMarker(): void {
		$report = new FlowStepReport();
		$report->line('info', str_repeat('x', 100000));
		$report->line('info', str_repeat('é', FlowStepReport::MAX_MESSAGE_LENGTH + 1));
		$report->line('info', str_repeat('y', FlowStepReport::MAX_MESSAGE_LENGTH));

		$lines = $report->take()['lines'];

		$this->assertSame(FlowStepReport::MAX_MESSAGE_LENGTH, mb_strlen($lines[0]['message']));
		$this->assertStringEndsWith(FlowStepReport::TRUNCATION_MARKER, $lines[0]['message']);
		$this->assertStringStartsWith('xxx', $lines[0]['message']);
		$this->assertSame(FlowStepReport::MAX_MESSAGE_LENGTH, mb_strlen($lines[1]['message']), 'multibyte text is cut by characters');
		$this->assertStringEndsWith(FlowStepReport::TRUNCATION_MARKER, $lines[1]['message']);
		$this->assertSame(str_repeat('y', FlowStepReport::MAX_MESSAGE_LENGTH), $lines[2]['message'], 'a message at the cap is untouched');
	}
}
