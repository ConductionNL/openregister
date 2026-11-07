<?php

declare(strict_types=1);

/**
 * Golden test for ArchivalFactsValidator: pins the exact outcomes (allowed and refused,
 * messages, codes, order and key order) captured on the code before the
 * A15 maintainability refactor, so the refactor provably changes nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalFactsValidator;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ArchivalFactsValidator.
 */
class ArchivalFactsValidatorGoldenTest extends TestCase {

	/**
	 * Golden: absent.
	 *
	 * @return void
	 */
	public function testAbsent(): void {
		$this->assertSame(
			array (
			  0 => array (
			  ),
			  1 => array (
			  ),
			  2 => array (
			  ),
			  3 => array (
			  ),
			  4 => array (
			  ),
			),
			$this->caseAbsent()
		);
	}

	/**
	 * Golden: aggregation.
	 *
	 * @return void
	 */
	public function testAggregation(): void {
		$this->assertSame(
			array (
			  0 => array (
			  ),
			  1 => array (
			    0 => array (
			      'code' => 'archival-aggregation-level-unknown',
			      'message' => 'x-openregister-archival.aggregationLevel "dossier" is not a term of MDTO\'s Aggregatieniveaus. Allowed: Archief, Serie, Dossier, Archiefstuk.',
			    ),
			  ),
			  2 => array (
			    0 => array (
			      'code' => 'archival-aggregation-level-unknown',
			      'message' => 'x-openregister-archival.aggregationLevel "4" is not a term of MDTO\'s Aggregatieniveaus. Allowed: Archief, Serie, Dossier, Archiefstuk.',
			    ),
			  ),
			  3 => array (
			    0 => array (
			      'code' => 'archival-aggregation-level-unknown',
			      'message' => 'x-openregister-archival.aggregationLevel "array" is not a term of MDTO\'s Aggregatieniveaus. Allowed: Archief, Serie, Dossier, Archiefstuk.',
			    ),
			  ),
			  4 => array (
			    0 => array (
			      'code' => 'archival-aggregation-level-unknown',
			      'message' => 'x-openregister-archival.aggregationLevel "" is not a term of MDTO\'s Aggregatieniveaus. Allowed: Archief, Serie, Dossier, Archiefstuk.',
			    ),
			  ),
			),
			$this->caseAggregation()
		);
	}

	/**
	 * Golden: useRestriction.
	 *
	 * @return void
	 */
	public function testUseRestriction(): void {
		$this->assertSame(
			array (
			  0 => array (
			    0 => array (
			      'code' => 'archival-use-restriction-not-object',
			      'message' => 'x-openregister-archival.useRestriction must be an object with a "type".',
			    ),
			  ),
			  1 => array (
			    0 => array (
			      'code' => 'archival-use-restriction-type-unknown',
			      'message' => 'x-openregister-archival.useRestriction.type is required and must be a term of MDTO\'s BeperkingGebruikTypeLijst. Allowed: Geen beperking, Nader te bepalen, Overig.',
			    ),
			  ),
			  2 => array (
			  ),
			  3 => array (
			  ),
			  4 => array (
			    0 => array (
			      'code' => 'archival-use-restriction-unknown-key',
			      'message' => 'x-openregister-archival.useRestriction contains unknown key "extra". Allowed: type, description.',
			    ),
			    1 => array (
			      'code' => 'archival-use-restriction-unknown-key',
			      'message' => 'x-openregister-archival.useRestriction contains unknown key "0". Allowed: type, description.',
			    ),
			    2 => array (
			      'code' => 'archival-use-restriction-type-unknown',
			      'message' => 'x-openregister-archival.useRestriction.type is required and must be a term of MDTO\'s BeperkingGebruikTypeLijst. Allowed: Geen beperking, Nader te bepalen, Overig.',
			    ),
			    3 => array (
			      'code' => 'archival-use-restriction-description-not-string',
			      'message' => 'x-openregister-archival.useRestriction.description must be a string when present.',
			    ),
			  ),
			  5 => array (
			  ),
			),
			$this->caseUseRestriction()
		);
	}

	/**
	 * Golden: temporalCoverage.
	 *
	 * @return void
	 */
	public function testTemporalCoverage(): void {
		$this->assertSame(
			array (
			  0 => array (
			    0 => array (
			      'code' => 'archival-temporal-coverage-not-object',
			      'message' => 'x-openregister-archival.temporalCoverage must be an object with a "type" and a "startProperty".',
			    ),
			  ),
			  1 => array (
			    0 => array (
			      'code' => 'archival-temporal-coverage-type-missing',
			      'message' => 'x-openregister-archival.temporalCoverage.type is required and must be a non-empty string.',
			    ),
			    1 => array (
			      'code' => 'archival-temporal-coverage-startproperty-missing',
			      'message' => 'x-openregister-archival.temporalCoverage.startProperty is required and must be a non-empty string.',
			    ),
			  ),
			  2 => array (
			  ),
			  3 => array (
			    0 => array (
			      'code' => 'archival-temporal-coverage-unknown-key',
			      'message' => 'x-openregister-archival.temporalCoverage contains unknown key "x". Allowed: type, startProperty, endProperty.',
			    ),
			    1 => array (
			      'code' => 'archival-temporal-coverage-type-missing',
			      'message' => 'x-openregister-archival.temporalCoverage.type is required and must be a non-empty string.',
			    ),
			    2 => array (
			      'code' => 'archival-temporal-coverage-startproperty-missing',
			      'message' => 'x-openregister-archival.temporalCoverage.startProperty is required and must be a non-empty string.',
			    ),
			    3 => array (
			      'code' => 'archival-temporal-coverage-endproperty-not-string',
			      'message' => 'x-openregister-archival.temporalCoverage.endProperty must be a non-empty string when present.',
			    ),
			  ),
			  4 => array (
			    0 => array (
			      'code' => 'archival-temporal-coverage-endproperty-not-string',
			      'message' => 'x-openregister-archival.temporalCoverage.endProperty must be a non-empty string when present.',
			    ),
			  ),
			  5 => array (
			  ),
			  6 => array (
			  ),
			),
			$this->caseTemporalCoverage()
		);
	}

	private function caseAbsent(): mixed {
		$v = new ArchivalFactsValidator(); return [$v->validateAggregationLevel([]), $v->validateUseRestriction([]), $v->validateTemporalCoverage([]), $v->validateUseRestriction(["useRestriction" => null]), $v->validateTemporalCoverage(["temporalCoverage" => null])];
	}

	private function caseAggregation(): mixed {
		$v = new ArchivalFactsValidator(); return [$v->validateAggregationLevel(["aggregationLevel" => "Dossier"]), $v->validateAggregationLevel(["aggregationLevel" => "dossier"]), $v->validateAggregationLevel(["aggregationLevel" => 4]), $v->validateAggregationLevel(["aggregationLevel" => ["x"]]), $v->validateAggregationLevel(["aggregationLevel" => false])];
	}

	private function caseUseRestriction(): mixed {
		$v = new ArchivalFactsValidator(); return [$v->validateUseRestriction(["useRestriction" => "x"]), $v->validateUseRestriction(["useRestriction" => []]), $v->validateUseRestriction(["useRestriction" => ["type" => "Geen beperking"]]), $v->validateUseRestriction(["useRestriction" => ["type" => "Geen beperking", "description" => "d"]]), $v->validateUseRestriction(["useRestriction" => ["type" => "nope", "description" => 3, "extra" => 1, 0 => 2]]), $v->validateUseRestriction(["useRestriction" => ["type" => "Geen beperking", "description" => null]])];
	}

	private function caseTemporalCoverage(): mixed {
		$v = new ArchivalFactsValidator(); return [$v->validateTemporalCoverage(["temporalCoverage" => 5]), $v->validateTemporalCoverage(["temporalCoverage" => []]), $v->validateTemporalCoverage(["temporalCoverage" => ["type" => "Looptijd", "startProperty" => "begin"]]), $v->validateTemporalCoverage(["temporalCoverage" => ["type" => " ", "startProperty" => 3, "endProperty" => " ", "x" => 1]]), $v->validateTemporalCoverage(["temporalCoverage" => ["type" => "a", "startProperty" => "b", "endProperty" => 9]]), $v->validateTemporalCoverage(["temporalCoverage" => ["type" => "a", "startProperty" => "b", "endProperty" => "eind"]]), $v->validateTemporalCoverage(["temporalCoverage" => ["type" => "a", "startProperty" => "b", "endProperty" => null]])];
	}
}
