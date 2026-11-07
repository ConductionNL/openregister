<?php

declare(strict_types=1);

/**
 * Golden test for ArchivalDecisionResolver: pins the exact outcomes (allowed and refused,
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

use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ArchivalDecisionResolver.
 */
class ArchivalDecisionResolverGoldenTest extends TestCase {

	/**
	 * Golden: nothing.
	 *
	 * @return void
	 */
	public function testNothing(): void {
		$this->assertSame(
			NULL,
			$this->caseNothing()
		);
	}

	/**
	 * Golden: emptyBlocks.
	 *
	 * @return void
	 */
	public function testEmptyBlocks(): void {
		$this->assertSame(
			NULL,
			$this->caseEmptyBlocks()
		);
	}

	/**
	 * Golden: fullRetention.
	 *
	 * @return void
	 */
	public function testFullRetention(): void {
		$this->assertSame(
			array (
			  'appraisal' => 'destroy',
			  'disposalCategory' => '1.1',
			  'legalHold' => array (
			    'active' => true,
			    'reason' => 'WOO',
			    'placedBy' => 'admin',
			    'placedDate' => '2024-02-02',
			    'releasedCount' => 2,
			  ),
			  'retentionPeriod' => 'P5Y',
			  'disposalDate' => '2030-01-01',
			  'recordState' => 'semi_static',
			  'immutable' => false,
			  'aggregationLevel' => 'Dossier',
			  'useRestriction' => array (
			    'type' => 'Geen beperking',
			  ),
			  'temporalCoverage' => array (
			    'type' => 'Looptijd',
			  ),
			  'basis' => 'selection_list',
			  'source' => 'Selectielijst 2020',
			  'sourceVersion' => '2020.1',
			  'sourceConsultedAt' => '2024-01-01T00:00:00+00:00',
			  'selectionListRow' => 'row-1',
			  'nomination' => array (
			    'status' => 'nominated',
			  ),
			  'outcome' => array (
			    'kind' => 'destroyed',
			  ),
			  'annotation' => array (
			    'effectiveRetention' => 'P1Y',
			    'matchedRule' => 2,
			    'defaulted' => false,
			    'expiresAt' => '2025-01-01',
			    'useRestriction' => array (
			      'type' => 'Geen beperking',
			    ),
			  ),
			),
			$this->caseFullRetention()
		);
	}

	/**
	 * Golden: tmloOnly.
	 *
	 * @return void
	 */
	public function testTmloOnly(): void {
		$this->assertSame(
			array (
			  'appraisal' => 'retain_permanently',
			  'disposalCategory' => 'B2',
			  'retentionPeriod' => 'P10Y',
			  'disposalDate' => '2040-02-02',
			  'recordState' => 'transferred',
			  'immutable' => true,
			  'aggregationLevel' => 'Archief',
			  'basis' => 'tmlo',
			),
			$this->caseTmloOnly()
		);
	}

	/**
	 * Golden: tmloTermOnlyBasis.
	 *
	 * @return void
	 */
	public function testTmloTermOnlyBasis(): void {
		$this->assertSame(
			array (
			  'basis' => 'tmlo',
			),
			$this->caseTmloTermOnlyBasis()
		);
	}

	/**
	 * Golden: declaredFields.
	 *
	 * @return void
	 */
	public function testDeclaredFields(): void {
		$this->assertSame(
			array (
			  'appraisal' => 'retain_permanently',
			  'disposalDate' => '2031-03-03',
			  'recordState' => 'vreemd',
			  'immutable' => false,
			  'basis' => 'record',
			),
			$this->caseDeclaredFields()
		);
	}

	/**
	 * Golden: annotationOnlyDefaulted.
	 *
	 * @return void
	 */
	public function testAnnotationOnlyDefaulted(): void {
		$this->assertSame(
			array (
			  'retentionPeriod' => 'P7Y',
			  'disposalDate' => '2031-01-01T00:00:00+00:00',
			  'temporalCoverage' => array (
			    'type' => 'Looptijd',
			    'start' => '2020',
			  ),
			  'basis' => 'schema_annotation',
			  'annotation' => array (
			    'effectiveRetention' => 'P7Y',
			    'defaulted' => true,
			    'expiresAt' => '2031-01-01T00:00:00+00:00',
			    'temporalCoverage' => array (
			      'type' => 'Looptijd',
			      'start' => '2020',
			    ),
			  ),
			),
			$this->caseAnnotationOnlyDefaulted()
		);
	}

	/**
	 * Golden: notConsultedAndReleasedHold.
	 *
	 * @return void
	 */
	public function testNotConsultedAndReleasedHold(): void {
		$this->assertSame(
			array (
			  'disposalCategory' => '1.2',
			  'legalHold' => array (
			    'active' => false,
			    'releasedCount' => 1,
			  ),
			  'recordState' => 'destroyed',
			  'immutable' => true,
			  'basis' => 'selection_list_not_consulted',
			  'annotation' => array (
			    'noRule' => true,
			  ),
			),
			$this->caseNotConsultedAndReleasedHold()
		);
	}

	/**
	 * Golden: onlyHoldNeverActive.
	 *
	 * @return void
	 */
	public function testOnlyHoldNeverActive(): void {
		$this->assertSame(
			array (
			  'legalHold' => array (
			    'active' => false,
			  ),
			),
			$this->caseOnlyHoldNeverActive()
		);
	}

	/**
	 * Golden: schemaBasis.
	 *
	 * @return void
	 */
	public function testSchemaBasis(): void {
		$this->assertSame(
			array (
			  'appraisal' => 'Onbekend',
			  'retentionPeriod' => 'P3Y',
			  'basis' => 'schema',
			),
			$this->caseSchemaBasis()
		);
	}

	private function caseNothing(): mixed {
		return (new ArchivalDecisionResolver())->resolve(new ObjectEntity());
	}

	private function caseEmptyBlocks(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["annotation" => "x", "legalHold" => "no"]); $e->setTmlo([]); $e->setObject(["archiefnominatie" => "  "]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseFullRetention(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["archiefnominatie" => "vernietigen", "classification" => "1.1", "bewaartermijn" => "P5Y", "archiefactiedatum" => "2030-01-01", "archiefstatus" => "gearchiveerd", "selectielijstBron" => "Selectielijst 2020", "selectionListVersion" => "2020.1", "selectionListConsultedAt" => "2024-01-01T00:00:00+00:00", "selectielijstRow" => "row-1", "nomination" => ["status" => "nominated"], "outcome" => ["kind" => "destroyed"], "aggregationLevel" => "Dossier", "legalHold" => ["active" => true, "reason" => "WOO", "placedBy" => "admin", "placedDate" => "2024-02-02", "history" => [["x" => 1], ["x" => 2]]], "annotation" => ["effectiveRetention" => "P1Y", "matchedRule" => 2, "expiresAt" => "2025-01-01", "useRestriction" => ["type" => "Geen beperking"]]]); $e->setTmlo(["archiefnominatie" => "bewaren", "dekkingInTijd" => ["type" => "Looptijd"], "beperkingGebruik" => ""]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseTmloOnly(): mixed {
		$e = new ObjectEntity(); $e->setTmlo(["archiefnominatie" => "bewaren", "vernietigingsCategorie" => "B2", "bewaarTermijn" => "P10Y", "archiefactiedatum" => "2040-02-02", "archiefstatus" => "overgebracht", "aggregatieniveau" => "Archief"]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseTmloTermOnlyBasis(): mixed {
		$e = new ObjectEntity(); $e->setTmlo(["bewaarTermijn" => ""]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseDeclaredFields(): mixed {
		$e = new ObjectEntity(); $e->setObject(["archiveNomination" => "blijvend_bewaren", "archiefnominatie" => "vernietigen", "archiefactiedatum" => " 2031-03-03 ", "archiveStatus" => 7, "archiefstatus" => "vreemd"]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseAnnotationOnlyDefaulted(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["classification" => "", "annotation" => ["effectiveRetention" => "P7Y", "matchedRule" => null, "expiresAt" => "2031-01-01T00:00:00+00:00", "temporalCoverage" => ["type" => "Looptijd", "start" => "2020"]]]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseNotConsultedAndReleasedHold(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["classification" => "1.2", "archiefstatus" => "vernietigd", "legalHold" => ["active" => false, "reason" => "old", "history" => [["r" => 1]]], "nomination" => [], "outcome" => "x", "annotation" => ["noRule" => true]]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseOnlyHoldNeverActive(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["legalHold" => ["history" => []]]); return (new ArchivalDecisionResolver())->resolve($e);
	}

	private function caseSchemaBasis(): mixed {
		$e = new ObjectEntity(); $e->setRetention(["bewaartermijn" => "P3Y", "archiefnominatie" => "Onbekend"]); return (new ArchivalDecisionResolver())->resolve($e);
	}
}
