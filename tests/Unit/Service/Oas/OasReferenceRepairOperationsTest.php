<?php

/**
 * Unit tests for which path-item entries the reference repair inspects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasReferenceRepair;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\TestCase;

/**
 * Path-item entries that are no operation, or an operation without responses, are passed over without a report.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 */
class OasReferenceRepairOperationsTest extends TestCase {

	/**
	 * A summary string, a parameter list and a response-less operation raise nothing and stay as they are.
	 *
	 * @return void
	 */
	public function testNonOperationsArePassedOver(): void {
		$report = new OasValidationReport();
		$oas    = [
			'components' => ['schemas' => ['Known' => ['type' => 'object']]],
			'paths'      => [
				'/x' => [
					'summary'    => 'Things',
					'parameters' => [['name' => 'id', 'in' => 'path']],
					'delete'     => ['operationId' => 'deleteThing'],
				],
			],
		];
		$before = $oas;

		(new OasReferenceRepair(['Known'], $report))->repairDocument($oas);

		$this->assertTrue($report->isEmpty());
		$this->assertSame($before, $oas);

	}//end testNonOperationsArePassedOver()
}//end class
