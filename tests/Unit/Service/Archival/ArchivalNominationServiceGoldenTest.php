<?php

declare(strict_types=1);

/**
 * Golden test for ArchivalNominationService: pins the exact outcomes (allowed and refused,
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

use DateTime;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\ArchivalDeclarationReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use OCA\OpenRegister\Service\RetentionService;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ArchivalNominationService.
 */
class ArchivalNominationServiceGoldenTest extends TestCase {

	/**
	 * The service, with a selectielijst lookup and date arithmetic that answer as given.
	 *
	 * @param array<string, mixed>|null $entry The selectielijst row the lookup returns.
	 * @param array<string, string|null> $dates Disposal date per retention period.
	 *
	 * @return ArchivalNominationService The service.
	 */
	private function service(?array $entry, array $dates = []): ArchivalNominationService {
		$retention = $this->getMockBuilder(RetentionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['lookupSelectielijstEntry', 'calculateArchiveActionDate'])
			->getMock();
		$retention->method('lookupSelectielijstEntry')->willReturn($entry);
		$retention->method('calculateArchiveActionDate')->willReturnCallback(
			static fn (ObjectEntity $object, Schema $schema, string $retentionPeriod): ?string => ($dates[$retentionPeriod] ?? null)
		);
		$logger = new NullLogger();
		$objects = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['findAcrossAllSources'])->getMock();

		return new ArchivalNominationService($retention, $logger, new LifecycleFinalStateResolver($objects, $logger), new ArchivalDeclarationReader($logger));
	}

	/**
	 * A schema with the given archive block and configuration.
	 *
	 * @param array<string, mixed> $archive       The archive block.
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $archive, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setArchive($archive);
		$schema->setConfiguration($configuration);
		return $schema;
	}

	/**
	 * A record with fixed identity, data, creation date and retention.
	 *
	 * @param array<string, mixed>      $data      The object data.
	 * @param array<string, mixed>|null $retention The stored retention block.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(array $data = [], ?array $retention = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-4000-8000-000000000002');
		$object->setObject($data);
		$object->setCreated(new DateTime('2020-01-01T00:00:00+00:00'));
		$object->setRetention($retention);
		return $object;
	}

	/**
	 * The nomination and the retention it wrote, with the wall-clock `at` replaced by a marker.
	 *
	 * @param array<string, mixed> $block  The returned nomination.
	 * @param ObjectEntity         $object The record.
	 *
	 * @return array<string, mixed> Both, clock-free.
	 */
	private function outcome(array $block, ObjectEntity $object): array {
		$mask = static function (mixed $value) use (&$mask): mixed {
			if (is_array($value) === false) {
				return $value;
			}

			foreach ($value as $key => $inner) {
				if ($key === 'at' && is_string($inner) === true && strtotime($inner) !== false) {
					$value[$key] = '<at>';
					continue;
				}

				$value[$key] = $mask($inner);
			}

			return $value;
		};

		return ['block' => $mask($block), 'retention' => $mask($object->getRetention())];
	}

	/**
	 * Run one nomination and return its clock-free outcome.
	 *
	 * With an actor or a reason it is a recomputation, the only nomination that carries them.
	 *
	 * @param ArchivalNominationService $service The service.
	 * @param ObjectEntity              $object  The record.
	 * @param Schema                    $schema  Its schema.
	 * @param string|null               $actor   The actor.
	 * @param string|null               $reason  The reason.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function nominateOnce(ArchivalNominationService $service, ObjectEntity $object, Schema $schema, ?string $actor = null, ?string $reason = null): array {
		if ($actor === null && $reason === null) {
			$block = $service->nominate(object: $object, schema: $schema);
			return $this->outcome(block: $block, object: $object);
		}

		$block = $service->recompute(object: $object, schema: $schema, actor: $actor, reason: $reason);
		return $this->outcome(block: $block, object: $object);
	}

	/**
	 * Golden: notApplicable.
	 *
	 * @return void
	 */
	public function testNotApplicable(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'not_applicable',
			    'unnominatableReason' => 'this schema declares neither an `archive` block with `enabled: true` nor an `x-openregister-archival` retention block, so nothing says what happens to its records when their business use ends',
			  ),
			  'retention' => array (
			  ),
			),
			$this->caseNotApplicable()
		);
	}

	/**
	 * Golden: selectionListFull.
	 *
	 * @return void
	 */
	public function testSelectionListFull(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'recompute',
			    'actor' => 'admin',
			    'reason' => 'recompute after appeal',
			    'rule' => 'selection_list',
			    'selectionListRow' => '11.1.2',
			  ),
			  'retention' => array (
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'old',
			      ),
			      1 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'recompute',
			        'actor' => 'admin',
			        'reason' => 'recompute after appeal',
			        'rule' => 'selection_list',
			        'selectionListRow' => '11.1.2',
			      ),
			    ),
			    'keep' => 'me',
			    'archiefnominatie' => 'vernietigen',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P7Y',
			    'archiefactiedatum' => '2033-03-01',
			    'classification' => '11.1.2',
			    'selectielijstBron' => 'Selectielijst 2020',
			    'selectielijstRow' => '11.1.2',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'recompute',
			      'actor' => 'admin',
			      'reason' => 'recompute after appeal',
			      'rule' => 'selection_list',
			      'selectionListRow' => '11.1.2',
			    ),
			  ),
			),
			$this->caseSelectionListFull()
		);
	}

	/**
	 * Golden: selectionListBareRow.
	 *
	 * @return void
	 */
	public function testSelectionListBareRow(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'selection_list',
			    'selectionListRow' => '1.1',
			  ),
			  'retention' => array (
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'selection_list',
			        'selectionListRow' => '1.1',
			      ),
			    ),
			    'archiefnominatie' => 'bewaren',
			    'archiefstatus' => 'semi_static',
			    'classification' => '1.1',
			    'selectielijstRow' => '1.1',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'selection_list',
			      'selectionListRow' => '1.1',
			    ),
			  ),
			),
			$this->caseSelectionListBareRow()
		);
	}

	/**
	 * Golden: rowWithoutAppraisalFallsToDefault.
	 *
	 * @return void
	 */
	public function testRowWithoutAppraisalFallsToDefault(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'schema_default',
			    'selectionListRow' => '2.2',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'vernietigen',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P10Y',
			    'archiefactiedatum' => '2030-01-01',
			    'classification' => '2.2',
			    'selectielijstBron' => 'x',
			    'selectielijstRow' => '2.2',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'schema_default',
			      'selectionListRow' => '2.2',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'schema_default',
			        'selectionListRow' => '2.2',
			      ),
			    ),
			  ),
			),
			$this->caseRowWithoutAppraisalFallsToDefault()
		);
	}

	/**
	 * Golden: defaultWithOverride.
	 *
	 * @return void
	 */
	public function testDefaultWithOverride(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'local_override',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'vernietigen',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P3Y',
			    'archiefactiedatum' => '2023-01-01',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'local_override',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'local_override',
			      ),
			    ),
			  ),
			),
			$this->caseDefaultWithOverride()
		);
	}

	/**
	 * Golden: defaultWithoutPeriod.
	 *
	 * @return void
	 */
	public function testDefaultWithoutPeriod(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'schema_default',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'blijvend_bewaren',
			    'archiefstatus' => 'semi_static',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'schema_default',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'schema_default',
			      ),
			    ),
			  ),
			),
			$this->caseDefaultWithoutPeriod()
		);
	}

	/**
	 * Golden: unnominatableWithCategory.
	 *
	 * @return void
	 */
	public function testUnnominatableWithCategory(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'unnominatable',
			    'at' => '<at>',
			    'trigger' => 'recompute',
			    'actor' => 'a',
			    'unnominatableReason' => 'no selectielijst row matches category 9.9, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			  ),
			  'retention' => array (
			    'nomination' => array (
			      'status' => 'unnominatable',
			      'at' => '<at>',
			      'trigger' => 'recompute',
			      'actor' => 'a',
			      'unnominatableReason' => 'no selectielijst row matches category 9.9, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'unnominatable',
			        'at' => '<at>',
			        'trigger' => 'recompute',
			        'actor' => 'a',
			        'unnominatableReason' => 'no selectielijst row matches category 9.9, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			      ),
			    ),
			  ),
			),
			$this->caseUnnominatableWithCategory()
		);
	}

	/**
	 * Golden: unnominatableWithoutCategory.
	 *
	 * @return void
	 */
	public function testUnnominatableWithoutCategory(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'unnominatable',
			    'at' => '<at>',
			    'trigger' => 'recompute',
			    'reason' => 'why',
			    'unnominatableReason' => 'the schema names no selectielijst category, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'keep',
			    'nomination' => array (
			      'status' => 'unnominatable',
			      'at' => '<at>',
			      'trigger' => 'recompute',
			      'reason' => 'why',
			      'unnominatableReason' => 'the schema names no selectielijst category, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'unnominatable',
			        'at' => '<at>',
			        'trigger' => 'recompute',
			        'reason' => 'why',
			        'unnominatableReason' => 'the schema names no selectielijst category, and neither the schema\'s `archive` block nor its `x-openregister-archival` retention block names a default nomination',
			      ),
			    ),
			  ),
			),
			$this->caseUnnominatableWithoutCategory()
		);
	}

	/**
	 * Golden: periodNotCountable.
	 *
	 * @return void
	 */
	public function testPeriodNotCountable(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'unnominatable',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'unnominatableReason' => 'the retention period P5Y could not be counted from any date on this record',
			  ),
			  'retention' => array (
			    'nomination' => array (
			      'status' => 'unnominatable',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'unnominatableReason' => 'the retention period P5Y could not be counted from any date on this record',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'unnominatable',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'unnominatableReason' => 'the retention period P5Y could not be counted from any date on this record',
			      ),
			    ),
			  ),
			),
			$this->casePeriodNotCountable()
		);
	}

	/**
	 * Golden: permanentWithoutDate.
	 *
	 * @return void
	 */
	public function testPermanentWithoutDate(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'schema_default',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'bewaren',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P5Y',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'schema_default',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'schema_default',
			      ),
			    ),
			  ),
			),
			$this->casePermanentWithoutDate()
		);
	}

	/**
	 * Golden: objectOwnCategory.
	 *
	 * @return void
	 */
	public function testObjectOwnCategory(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'selection_list',
			    'selectionListRow' => '3.3',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'vernietigen',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P2Y',
			    'archiefactiedatum' => '2022-01-01',
			    'classification' => '3.3',
			    'selectielijstRow' => '3.3',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'selection_list',
			      'selectionListRow' => '3.3',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'selection_list',
			        'selectionListRow' => '3.3',
			      ),
			    ),
			  ),
			),
			$this->caseObjectOwnCategory()
		);
	}

	/**
	 * Golden: annotationDeclared.
	 *
	 * @return void
	 */
	public function testAnnotationDeclared(): void {
		$this->assertSame(
			array (
			  'block' => array (
			    'status' => 'nominated',
			    'at' => '<at>',
			    'trigger' => 'closure',
			    'rule' => 'archival_annotation',
			  ),
			  'retention' => array (
			    'archiefnominatie' => 'destroy',
			    'archiefstatus' => 'semi_static',
			    'bewaartermijn' => 'P5Y',
			    'archiefactiedatum' => '2025-01-01',
			    'nomination' => array (
			      'status' => 'nominated',
			      'at' => '<at>',
			      'trigger' => 'closure',
			      'rule' => 'archival_annotation',
			    ),
			    'nominationHistory' => array (
			      0 => array (
			        'status' => 'nominated',
			        'at' => '<at>',
			        'trigger' => 'closure',
			        'rule' => 'archival_annotation',
			      ),
			    ),
			  ),
			),
			$this->caseAnnotationDeclared()
		);
	}

	/**
	 * Golden: terminalStates.
	 *
	 * @return void
	 */
	public function testTerminalStates(): void {
		$this->assertSame(
			array (
			  0 => true,
			  1 => false,
			  2 => false,
			  3 => false,
			  4 => false,
			  5 => false,
			),
			$this->caseTerminalStates()
		);
	}

	private function caseNotApplicable(): mixed {
		return $this->nominateOnce($this->service(null), $this->record(), $this->schema(["enabled" => false]));
	}

	private function caseSelectionListFull(): mixed {
		return $this->nominateOnce($this->service(["categorie" => " 11.1.2 ", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P7Y", "bron" => "Selectielijst 2020"], ["P7Y" => "2033-03-01"]), $this->record(["x" => 1], ["nominationHistory" => [["status" => "old"]], "keep" => "me"]), $this->schema(["enabled" => true, "classification" => "11.1.2"]), "admin", "recompute after appeal");
	}

	private function caseSelectionListBareRow(): mixed {
		return $this->nominateOnce($this->service(["archiefnominatie" => "bewaren", "bewaartermijn" => ""]), $this->record([], ["nominationHistory" => "broken"]), $this->schema(["enabled" => true, "classification" => "1.1"]));
	}

	private function caseRowWithoutAppraisalFallsToDefault(): mixed {
		return $this->nominateOnce($this->service(["categorie" => "2.2", "bewaartermijn" => "P1Y", "bron" => "x"], ["P10Y" => "2030-01-01"]), $this->record(), $this->schema(["enabled" => true, "classification" => "2.2", "defaultNominatie" => "vernietigen", "defaultBewaartermijn" => "P10Y"]));
	}

	private function caseDefaultWithOverride(): mixed {
		return $this->nominateOnce($this->service(null, ["P3Y" => "2023-01-01"]), $this->record(), $this->schema(["enabled" => true, "defaultNominatie" => "vernietigen", "defaultBewaartermijn" => "P10Y", "bewaartermijnOverride" => "P3Y"]));
	}

	private function caseDefaultWithoutPeriod(): mixed {
		return $this->nominateOnce($this->service(null), $this->record(), $this->schema(["enabled" => true, "defaultNominatie" => "blijvend_bewaren"]));
	}

	private function caseUnnominatableWithCategory(): mixed {
		return $this->nominateOnce($this->service(null), $this->record(), $this->schema(["enabled" => true, "classification" => "9.9"]), "a", null);
	}

	private function caseUnnominatableWithoutCategory(): mixed {
		return $this->nominateOnce($this->service(null), $this->record([], ["archiefnominatie" => "keep"]), $this->schema(["enabled" => true]), null, "why");
	}

	private function casePeriodNotCountable(): mixed {
		return $this->nominateOnce($this->service(null), $this->record(), $this->schema(["enabled" => true, "defaultNominatie" => "vernietigen", "defaultBewaartermijn" => "P5Y"]));
	}

	private function casePermanentWithoutDate(): mixed {
		return $this->nominateOnce($this->service(null), $this->record(), $this->schema(["enabled" => true, "defaultNominatie" => "bewaren", "defaultBewaartermijn" => "P5Y"]));
	}

	private function caseObjectOwnCategory(): mixed {
		return $this->nominateOnce($this->service(["categorie" => "3.3", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P2Y"], ["P2Y" => "2022-01-01"]), $this->record(["cat" => "3.3"]), $this->schema(["enabled" => true, "classification" => "1.1", "classificationProperty" => "cat"]));
	}

	private function caseAnnotationDeclared(): mixed {
		return $this->nominateOnce($this->service(null, ["P5Y" => "2025-01-01"]), $this->record(), $this->schema([], ["x-openregister-archival" => ["retention" => ["default" => "P5Y"]]]));
	}

	private function caseTerminalStates(): mixed {
		$s = $this->service(null); $a = $this->schema([], ["x-openregister-lifecycle" => ["final" => ["klaar"]]]); $g = $this->schema([], ["x-openregister-lifecycle" => ["graph" => ["schema" => " ", "finalField" => "f"]]]); $n = $this->schema([], ["x-openregister-lifecycle" => "x"]); return [$s->isTerminalState($a, "klaar"), $s->isTerminalState($a, "open"), $s->isTerminalState($a, " "), $s->isTerminalState($g, "klaar"), $s->isTerminalState($n, "klaar"), $s->isTerminalState($this->schema([], []), "klaar")];
	}
}
