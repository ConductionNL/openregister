<?php

declare(strict_types=1);

/**
 * Golden test for ArchiveActionDateCalculator: pins the exact outcomes (allowed and refused,
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
use Exception;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\MagicMapperInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Archival\RecordDating;

/**
 * Golden outcomes of ArchiveActionDateCalculator.
 */
class ArchiveActionDateCalculatorGoldenTest extends TestCase {

	/**
	 * A calculator whose mapper knows the given related records; any other reference throws.
	 *
	 * @param array<string, array<string, mixed>|null> $related Related record data by reference.
	 *
	 * @return ArchiveActionDateCalculator The calculator.
	 */
	private function calculator(array $related = []): ArchiveActionDateCalculator {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			static function (string|int $reference) use ($related): ObjectEntity {
				if (array_key_exists((string)$reference, $related) === false) {
					throw new Exception('not found');
				}

				$object = new ObjectEntity();
				$object->setObject($related[(string)$reference]);
				return $object;
			}
		);

		return new ArchiveActionDateCalculator($mapper, new NullLogger());
	}

	/**
	 * A schema with the given archive block.
	 *
	 * @param array<string, mixed> $archive The archive block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $archive): Schema {
		$schema = new Schema();
		$schema->setArchive($archive);
		return $schema;
	}

	/**
	 * A record with the given data and a FIXED creation date.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-4000-8000-000000000003');
		$object->setObject($data);
		$object->setCreated(new DateTime('2020-02-29T10:00:00+00:00'));
		return $object;
	}

	/**
	 * Every outcome of calculate() for a list of [archive, data, period] cases.
	 *
	 * @param array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> $cases The cases.
	 * @param array<string, array<string, mixed>|null>                                          $related Related records.
	 *
	 * @return array<int, string|null> The dates.
	 */
	private function dates(array $cases, array $related = []): array {
		$calculator = $this->calculator(related: $related);
		$out = [];
		foreach ($cases as $case) {
			$object = $this->record(data: $case[1]);
			$out[] = $calculator->calculate($object, $this->schema(archive: $case[0]), $case[2]);
			$out[] = $object->getCreated()->format('c');
		}

		return $out;
	}

	/**
	 * Golden: simpleMethods.
	 *
	 * @return void
	 */
	public function testSimpleMethods(): void {
		$this->assertSame(
			array (
			  0 => '2025-03-01',
			  1 => '2020-02-29T10:00:00+00:00',
			  2 => NULL,
			  3 => '2020-02-29T10:00:00+00:00',
			  4 => NULL,
			  5 => '2020-02-29T10:00:00+00:00',
			  6 => '2022-06-30',
			  7 => '2020-02-29T10:00:00+00:00',
			  8 => '2021-03-01',
			  9 => '2020-02-29T10:00:00+00:00',
			  10 => '2020-03-29',
			  11 => '2020-02-29T10:00:00+00:00',
			  12 => '2023-03-01',
			  13 => '2020-02-29T10:00:00+00:00',
			  14 => '2021-03-01',
			  15 => '2020-02-29T10:00:00+00:00',
			  16 => '2022-08-01',
			  17 => '2020-02-29T10:00:00+00:00',
			),
			$this->caseSimpleMethods()
		);
	}

	/**
	 * Golden: propertyMethods.
	 *
	 * @return void
	 */
	public function testPropertyMethods(): void {
		$this->assertSame(
			array (
			  0 => '2021-03-01',
			  1 => '2020-02-29T10:00:00+00:00',
			  2 => '2029-12-31',
			  3 => '2020-02-29T10:00:00+00:00',
			  4 => '2030-03-01',
			  5 => '2020-02-29T10:00:00+00:00',
			  6 => NULL,
			  7 => '2020-02-29T10:00:00+00:00',
			  8 => '2021-03-01',
			  9 => '2020-02-29T10:00:00+00:00',
			  10 => NULL,
			  11 => '2020-02-29T10:00:00+00:00',
			  12 => NULL,
			  13 => '2020-02-29T10:00:00+00:00',
			),
			$this->casePropertyMethods()
		);
	}

	/**
	 * Golden: relationMethods.
	 *
	 * @return void
	 */
	public function testRelationMethods(): void {
		$this->assertSame(
			array (
			  0 => NULL,
			  1 => '2020-02-29T10:00:00+00:00',
			  2 => '2025-01-15',
			  3 => '2020-02-29T10:00:00+00:00',
			  4 => '2025-07-01',
			  5 => '2020-02-29T10:00:00+00:00',
			  6 => NULL,
			  7 => '2020-02-29T10:00:00+00:00',
			  8 => NULL,
			  9 => '2020-02-29T10:00:00+00:00',
			  10 => NULL,
			  11 => '2020-02-29T10:00:00+00:00',
			  12 => NULL,
			  13 => '2020-02-29T10:00:00+00:00',
			  14 => '2012-10-10',
			  15 => '2020-02-29T10:00:00+00:00',
			  16 => NULL,
			  17 => '2020-02-29T10:00:00+00:00',
			),
			$this->caseRelationMethods()
		);
	}

	/**
	 * Golden: determineBrondatum.
	 *
	 * @return void
	 */
	public function testDetermineBrondatum(): void {
		$this->assertSame(
			array (
			  'eigenschap' => '2005-05-05',
			  'ander_datumkenmerk' => '2005-05-05',
			  'afgehandeld' => '2005-05-05',
			  'termijn' => '2005-05-05',
			  'hoofdzaak' => '2001-01-01',
			  'onbekend' => NULL,
			),
			$this->caseDetermineBrondatum()
		);
	}

	/**
	 * Golden: sourcePropertyOf.
	 *
	 * @return void
	 */
	public function testSourcePropertyOf(): void {
		$this->assertSame(
			array (
			  0 => NULL,
			  1 => 'c',
			  2 => NULL,
			  3 => 'b',
			  4 => NULL,
			  5 => 's',
			  6 => 'r',
			  7 => NULL,
			),
			$this->caseSourcePropertyOf()
		);
	}

	/**
	 * Golden: references.
	 *
	 * @return void
	 */
	public function testReferences(): void {
		$this->assertSame(
			array (
			  0 => NULL,
			  1 => 'a',
			  2 => '7',
			  3 => NULL,
			  4 => NULL,
			  5 => NULL,
			  6 => NULL,
			  7 => true,
			  8 => false,
			),
			$this->caseReferences()
		);
	}

	private function caseSimpleMethods(): mixed {
		return $this->dates([[[], [], "P5Y"], [["afleidingswijze" => "nope"], [], "P5Y"], [[], [], "garbage"], [["closureField" => "closed"], ["closed" => "2021-06-30"], "P1Y"], [["closureField" => "closed"], ["closed" => "not a date"], "P1Y"], [["closureField" => "closed"], [], "P1M"], [["afleidingswijze" => "termijn", "procestermijn" => "P2Y"], [], "P1Y"], [["afleidingswijze" => "termijn", "procestermijn" => "bad"], [], "P1Y"], [["afleidingswijze" => "termijn", "closureField" => "c", "procestermijn" => "P6M"], ["c" => "2022-01-31"], "P1D"]]);
	}

	private function casePropertyMethods(): mixed {
		return $this->dates([[["afleidingswijze" => "eigenschap"], [], "P1Y"], [["afleidingswijze" => "eigenschap", "bronEigenschap" => "d"], ["d" => "2019-12-31"], "P10Y"], [["afleidingswijze" => "eigenschap", "bronEigenschap" => "d"], ["d" => null], "P10Y"], [["afleidingswijze" => "ander_datumkenmerk"], [], "P1Y"], [["afleidingswijze" => "ander_datumkenmerk", "sourceDateProperty" => "s"], ["s" => "2018-03-01"], "P3Y"], [["afleidingswijze" => "ander_datumkenmerk", "sourceDateProperty" => "s"], ["s" => "not-a-date"], "P3Y"], [["afleidingswijze" => "ander_datumkenmerk", "sourceDateProperty" => "s"], [], "P3Y"]]);
	}

	private function caseRelationMethods(): mixed {
		return $this->dates([[["afleidingswijze" => "hoofdzaak"], [], "P1Y"], [["afleidingswijze" => "hoofdzaak", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => "uuid-1"], "P1Y"], [["afleidingswijze" => "gerelateerde_zaak", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => [" uuid-2 ", "uuid-1"]], "P2Y"], [["afleidingswijze" => "zaakobject", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => []], "P2Y"], [["afleidingswijze" => "ingangsdatum_besluit", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => "missing"], "P2Y"], [["afleidingswijze" => "vervaldatum_besluit", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => "uuid-3"], "P2Y"], [["afleidingswijze" => "vervaldatum_besluit", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => "uuid-4"], "P2Y"], [["afleidingswijze" => "zaakobject", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => 42], "P2Y"], [["afleidingswijze" => "zaakobject", "sourceRelation" => "r", "sourceRelationProperty" => "eind"], ["r" => 1.5], "P2Y"]], ["uuid-1" => ["eind" => "2024-01-15"], "uuid-2" => ["eind" => "2023-07-01"], "uuid-3" => ["other" => 1], "uuid-4" => null, "42" => ["eind" => "2010-10-10"]]);
	}

	private function caseDetermineBrondatum(): mixed {
		$c = $this->calculator(["u" => ["e" => "2001-01-01"]]); $o = $this->record(["d" => "2005-05-05", "r" => "u"]); $out = []; foreach (["eigenschap", "ander_datumkenmerk", "afgehandeld", "termijn", "hoofdzaak", "onbekend"] as $m) { $d = $c->determineBrondatum(new RecordDating(object: $o, archive: $this->schema(["bronEigenschap" => "d", "sourceDateProperty" => "d", "closureField" => "d", "sourceRelation" => "r", "sourceRelationProperty" => "e"])->getArchive(), afleidingswijze: $m)); $out[$m] = $d === null ? null : $d->format("Y-m-d"); } return $out;
	}

	private function caseSourcePropertyOf(): mixed {
		$c = $this->calculator(); $out = []; foreach ([[], ["closureField" => "c"], ["afleidingswijze" => "termijn", "closureField" => ""], ["afleidingswijze" => "eigenschap", "bronEigenschap" => "b"], ["afleidingswijze" => "ander_datumkenmerk", "sourceDateProperty" => 5], ["afleidingswijze" => "ander_datumkenmerk", "sourceDateProperty" => "s"], ["afleidingswijze" => "hoofdzaak", "sourceRelation" => "r"], ["afleidingswijze" => "onbekend", "sourceRelation" => "r"]] as $config) { $out[] = $c->sourcePropertyOf($config); } return $out;
	}

	private function caseReferences(): mixed {
		$c = $this->calculator(); return [$c->referenceOf(null), $c->referenceOf(" a "), $c->referenceOf(7), $c->referenceOf([]), $c->referenceOf(["", "b"]), $c->referenceOf([["x"]]), $c->referenceOf(true), $c->isRelationMethod("hoofdzaak"), $c->isRelationMethod("eigenschap")];
	}
}
