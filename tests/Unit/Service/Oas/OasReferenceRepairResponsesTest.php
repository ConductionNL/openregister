<?php

/**
 * Unit tests for repairing a dangling reference inside a response schema.
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
 * A dangling $ref in a response schema is substituted in the document, the same way as in a component,
 * so the document says what the report says was done.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 */
class OasReferenceRepairResponsesTest extends TestCase {

	/**
	 * The response schema is repaired in place, exactly as the same dangling ref in a component is.
	 *
	 * @return void
	 */
	public function testADanglingResponseRefIsSubstitutedInTheDocument(): void {
		$dangling = ['$ref' => '#/components/schemas/Missing'];
		$oas      = [
			'components' => ['schemas' => ['Known' => ['type' => 'object'], 'Holder' => ['type' => 'object', 'properties' => ['x' => $dangling]]]],
			'paths'      => ['/x' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => $dangling]]]]]]],
		];
		$report = new OasValidationReport();

		(new OasReferenceRepair(['Known', 'Holder'], $report))->repairDocument($oas);

		$repaired = $oas['paths']['/x']['get']['responses']['200']['content']['application/json']['schema'];
		$this->assertArrayNotHasKey('$ref', $repaired, 'the dangling reference is gone from the document');
		$this->assertSame($oas['components']['schemas']['Holder']['properties']['x'], $repaired, 'repaired as a component is');
		$this->assertContains(
			'paths./x.get.responses.200.$ref',
			array_column($report->getIssues(), 'path')
		);

	}//end testADanglingResponseRefIsSubstitutedInTheDocument()
}//end class
