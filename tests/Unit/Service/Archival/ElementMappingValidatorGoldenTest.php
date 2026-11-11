<?php

declare(strict_types=1);

/**
 * Golden test for ElementMappingValidator: pins the exact outcomes (allowed and refused,
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

use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ElementMappingValidator.
 */
class ElementMappingValidatorGoldenTest extends TestCase {

	/**
	 * Golden: emptyMapping.
	 *
	 * @return void
	 */
	public function testEmptyMapping(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'code' => 'mdto-mapping-empty',
			    'message' => 'The MDTO mapping is declared but empty. Remove the key, or map the elements MDTO demands.',
			  ),
			),
			$this->caseEmptyMapping()
		);
	}

	/**
	 * Golden: unreadableCatalogue.
	 *
	 * @return void
	 */
	public function testUnreadableCatalogue(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'code' => 'mdto-mapping-catalogue-unreadable',
			    'message' => 'xsd gone',
			  ),
			),
			$this->caseUnreadableCatalogue()
		);
	}

	/**
	 * Golden: mixedEntries.
	 *
	 * @return void
	 */
	public function testMixedEntries(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'code' => 'mdto-mapping-unknown-element',
			    'message' => '"nietBestaand" is not an MDTO informatieobject element. MDTO-XML1.0.1.xsd declares: identificatie, naam, aggregatieniveau, classificatie, trefwoord, omschrijving, raadpleeglocatie, dekkingInTijd, dekkingInRuimte, taal, event, waardering, bewaartermijn, informatiecategorie, isOnderdeelVan, bevatOnderdeel, heeftRepresentatie, aanvullendeMetagegevens, gerelateerdInformatieobject, archiefvormer, betrokkene, activiteit, beperkingGebruik.',
			  ),
			  1 => array (
			    'code' => 'mdto-mapping-entry-not-an-object',
			    'message' => 'The mapping for "naam" must be an object naming a "property" or a "const".',
			  ),
			  2 => array (
			    'code' => 'mdto-mapping-two-sources',
			    'message' => 'The mapping for "identificatie" names both a "property" and a "const". One of them fills the element; say which.',
			  ),
			  3 => array (
			    'code' => 'mdto-mapping-no-source',
			    'message' => 'The mapping for "waardering" names neither a "property" nor a "const", so nothing fills the element.',
			  ),
			  4 => array (
			    'code' => 'mdto-mapping-unknown-property',
			    'message' => 'The mapping for "archiefvormer" reads property "onbekend", which this schema does not declare.',
			  ),
			  5 => array (
			    'code' => 'mdto-mapping-unknown-element',
			    'message' => '"7" is not an MDTO informatieobject element. MDTO-XML1.0.1.xsd declares: identificatie, naam, aggregatieniveau, classificatie, trefwoord, omschrijving, raadpleeglocatie, dekkingInTijd, dekkingInRuimte, taal, event, waardering, bewaartermijn, informatiecategorie, isOnderdeelVan, bevatOnderdeel, heeftRepresentatie, aanvullendeMetagegevens, gerelateerdInformatieobject, archiefvormer, betrokkene, activiteit, beperkingGebruik.',
			  ),
			),
			$this->caseMixedEntries()
		);
	}

	/**
	 * Golden: completeWithIntsAndConst.
	 *
	 * @return void
	 */
	public function testCompleteWithIntsAndConst(): void {
		$this->assertSame(
			array (
			),
			$this->caseCompleteWithIntsAndConst()
		);
	}

	/**
	 * Golden: onlyMissingMandatory.
	 *
	 * @return void
	 */
	public function testOnlyMissingMandatory(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "identificatie" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  1 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "waardering" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  2 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "archiefvormer" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  3 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "beperkingGebruik" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			),
			$this->caseOnlyMissingMandatory()
		);
	}

	/**
	 * Golden: nonStringSource.
	 *
	 * @return void
	 */
	public function testNonStringSource(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'code' => 'mdto-mapping-no-source',
			    'message' => 'The mapping for "naam" names neither a "property" nor a "const", so nothing fills the element.',
			  ),
			  1 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "identificatie" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  2 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "waardering" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  3 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "archiefvormer" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			  4 => array (
			    'code' => 'mdto-mapping-missing-mandatory',
			    'message' => 'MDTO demands "beperkingGebruik" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. A transfer of objects of this schema would be refused.',
			  ),
			),
			$this->caseNonStringSource()
		);
	}

	private function caseEmptyMapping(): mixed {
		$v = new ElementMappingValidator(new MdtoElementCatalogue()); return $v->validate(mapping: [], properties: ["a" => []]);
	}

	private function caseUnreadableCatalogue(): mixed {
		$c = $this->createMock(MdtoElementCatalogue::class); $c->method("elements")->willThrowException(new \RuntimeException("xsd gone")); $v = new ElementMappingValidator($c); return $v->validate(mapping: ["naam" => ["property" => "titel"]], properties: []);
	}

	private function caseMixedEntries(): mixed {
		$v = new ElementMappingValidator(new MdtoElementCatalogue()); return $v->validate(mapping: ["nietBestaand" => ["property" => "titel"], "naam" => "titel", "identificatie" => ["property" => "zaaknummer", "const" => "X"], "waardering" => ["property" => "  ", "const" => ""], "archiefvormer" => ["property" => "onbekend.pad"], "beperkingGebruik" => ["property" => "resultaat.diep"], 7 => ["const" => 5]], properties: ["zaaknummer" => [], "titel" => [], "resultaat" => []]);
	}

	private function caseCompleteWithIntsAndConst(): mixed {
		$v = new ElementMappingValidator(new MdtoElementCatalogue()); return $v->validate(mapping: ["identificatie" => ["property" => 12], "naam" => ["property" => "titel"], "waardering" => ["const" => "blijvend"], "archiefvormer" => ["const" => 3], "beperkingGebruik" => ["const" => " x "]], properties: ["12" => [], "titel" => []]);
	}

	private function caseOnlyMissingMandatory(): mixed {
		$v = new ElementMappingValidator(new MdtoElementCatalogue()); return $v->validate(mapping: ["naam" => ["property" => "titel", "const" => null]], properties: ["titel" => []]);
	}

	private function caseNonStringSource(): mixed {
		$v = new ElementMappingValidator(new MdtoElementCatalogue()); return $v->validate(mapping: ["naam" => ["property" => ["titel"], "const" => 1.5]], properties: ["titel" => []]);
	}
}
