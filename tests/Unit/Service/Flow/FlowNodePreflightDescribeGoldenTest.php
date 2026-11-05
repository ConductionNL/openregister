<?php

/**
 * Golden test for FlowNodePreflight::describe.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the refusal message for every kind of blocking finding, with and
 * without the optional fields.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 */
class FlowNodePreflightDescribeGoldenTest extends TestCase {

	/**
	 * The refusal message equals the recording.
	 *
	 * @return void
	 */
	public function testDescribeMatchesTheRecording(): void {
		$preflight = new FlowNodePreflight(
			$this->createMock(FlowNodeRegistry::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class)
		);
		$step = static fn (string $reason, array $extra = []): array => array_merge(
			['reason' => $reason, 'type' => 'acme.' . $reason, 'step' => 's-' . $reason, 'app' => 'acme'],
			$extra
		);
		$blocking = [
			$step(FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY, ['detail' => '"colour"', 'reads' => '"color", "size"']),
			$step(FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY, ['reads' => '']),
			$step(FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY),
			$step(FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED, ['detail' => 'continue']),
			$step(FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED),
			$step(FlowNodePreflight::REASON_CONFIG_REJECTED, ['detail' => 'title is required']),
			$step(FlowNodePreflight::REASON_CONFIG_REJECTED),
			$step(FlowNodePreflight::REASON_PRE_INVERSION_SHAPE),
			$step(FlowNodePreflight::REASON_NOT_NAMESPACED),
			$step(FlowNodePreflight::REASON_MISSING_FROM_OWNER),
			$step(FlowNodePreflight::REASON_OWNER_NOT_ENABLED, ['app' => 'other']),
			$step(FlowNodePreflight::REASON_DEAD_END),
		];

		$actual = [
			'all' => $preflight->describe(flow: 'intake', blocking: $blocking),
			'none' => $preflight->describe(flow: 'empty', blocking: []),
		];
		$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/flow-preflight-describe-golden.json'), true);

		$this->assertSame($golden, $actual);
	}//end testDescribeMatchesTheRecording()
}//end class
