<?php

/**
 * Behaviour test for AuditSettingsController: the audit aggregation window.
 *
 * Asserts what is read and stored, that a non-numeric window is refused before
 * anything is written, that a zero window reports merging off, and that a
 * failing store answers 500 with its message.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Controller\Settings\AuditSettingsController;
use OCA\OpenRegister\Service\Audit\AuditAggregationServiceInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditSettingsControllerBehaviourTest extends TestCase {

	/** @var AuditAggregationServiceInterface&MockObject The aggregation settings. */
	private AuditAggregationServiceInterface $aggregation;

	protected function setUp(): void {
		$this->aggregation = $this->createMock(AuditAggregationServiceInterface::class);
	}

	private function controller(mixed $windowSeconds = null): AuditSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('windowSeconds')->willReturn($windowSeconds);
		return new AuditSettingsController($request, $this->aggregation);
	}

	public function testReadingReportsTheWindowAndThatMergingIsOn(): void {
		$this->aggregation->method('windowSeconds')->willReturn(60);
		$this->assertSame(
			['windowSeconds' => 60, 'maximumSeconds' => 300, 'merging' => true],
			$this->controller()->getAggregationSettings()->getData()
		);
	}

	public function testAZeroWindowReportsMergingOff(): void {
		$this->aggregation->method('windowSeconds')->willReturn(0);
		$this->assertFalse($this->controller()->getAggregationSettings()->getData()['merging']);
	}

	public function testAFailingReadAnswers500(): void {
		$this->aggregation->method('windowSeconds')->willThrowException(new Exception('config unavailable'));
		$response = $this->controller()->getAggregationSettings();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'config unavailable'], $response->getData());
	}

	public function testANonNumericWindowIsRefusedWithoutWriting(): void {
		$this->aggregation->expects($this->never())->method('setWindowSeconds');
		foreach ([null, 'soon', ''] as $value) {
			$response = $this->controller($value)->updateAggregationSettings();
			$this->assertSame(400, $response->getStatus());
			$this->assertStringContainsString("'windowSeconds'", $response->getData()['error']);
		}
	}

	public function testUpdatingStoresTheWholeSecondsAndReportsWhatWasStored(): void {
		$this->aggregation->expects($this->once())->method('setWindowSeconds')->with(120)->willReturn(120);
		$this->assertSame(
			['windowSeconds' => 120, 'maximumSeconds' => 300, 'merging' => true],
			$this->controller('120.7')->updateAggregationSettings()->getData()
		);
	}

	public function testUpdatingToZeroSwitchesMergingOff(): void {
		$this->aggregation->expects($this->once())->method('setWindowSeconds')->with(0)->willReturn(0);
		$this->assertFalse($this->controller(0)->updateAggregationSettings()->getData()['merging']);
	}

	public function testAFailingWriteAnswers500(): void {
		$this->aggregation->method('setWindowSeconds')->willThrowException(new Exception('read only'));
		$response = $this->controller(30)->updateAggregationSettings();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'read only'], $response->getData());
	}
}
