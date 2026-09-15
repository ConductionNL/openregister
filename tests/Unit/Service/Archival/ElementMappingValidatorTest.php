<?php

declare(strict_types=1);

/**
 * The MDTO element mapping, and the catalogue it is checked against.
 *
 * 🔴 THE CATALOGUE TEST IS THE ONE THAT MATTERS. Every other test here would
 * pass against an empty catalogue, because an empty catalogue knows no
 * mandatory elements and therefore finds nothing missing. So the first test
 * names the five elements MDTO-XML1.0.1 actually demands: if the XSD parse
 * silently returns nothing, that assertion is what reddens.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MdtoElementCatalogue and ElementMappingValidator.
 */
class ElementMappingValidatorTest extends TestCase {

	private ElementMappingValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new ElementMappingValidator(new MdtoElementCatalogue());
	}

	/**
	 * A schema declaring two properties, so a mapping has something to read.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function properties(): array {
		return [
			'zaaknummer' => ['type' => 'string'],
			'titel' => ['type' => 'string'],
			'resultaat' => ['type' => 'object'],
		];
	}

	/**
	 * A mapping that fills every mandatory element.
	 *
	 * @return array<string, mixed> The mapping.
	 */
	private function completeMapping(): array {
		return [
			'identificatie' => ['property' => 'zaaknummer'],
			'naam' => ['property' => 'titel'],
			'waardering' => ['property' => 'resultaat.archiefnominatie'],
			'archiefvormer' => ['const' => 'Gemeente Voorbeeld'],
			'beperkingGebruik' => ['const' => 'Geen beperking'],
		];
	}

	/**
	 * 🔴 The mandatory set is read out of MDTO-XML1.0.1.xsd. These five names
	 * are what `minOccurs="1"` resolves to there, counting the two the
	 * informatieobject inherits from objectType. A parse that reached only the
	 * extension would report three, and a parse that reached nothing would
	 * report none and make every mapping below valid.
	 */
	public function testTheCatalogueNamesTheFiveElementsMdtoDemands(): void {
		$catalogue = new MdtoElementCatalogue();

		$this->assertSame(
			['identificatie', 'naam', 'waardering', 'archiefvormer', 'beperkingGebruik'],
			$catalogue->mandatory()
		);
		$this->assertGreaterThan(15, count($catalogue->elements()));
		$this->assertTrue($catalogue->knows('bewaartermijn'));
		$this->assertFalse($catalogue->knows('bewaarTermijn'));
	}

	public function testACompleteMappingIsAccepted(): void {
		$this->assertSame(
			[],
			$this->validator->validate(mapping: $this->completeMapping(), properties: $this->properties())
		);
	}

	public function testAMandatoryElementTheMappingLeavesOutIsNamed(): void {
		$mapping = $this->completeMapping();
		unset($mapping['beperkingGebruik']);

		$errors = $this->validator->validate(mapping: $mapping, properties: $this->properties());

		$this->assertCount(1, $errors);
		$this->assertSame('mdto-mapping-missing-mandatory', $errors[0]['code']);
		$this->assertStringContainsString('beperkingGebruik', $errors[0]['message']);
	}

	public function testAnElementMdtoDoesNotHaveIsRefused(): void {
		$mapping = $this->completeMapping();
		$mapping['bewaarTermijn'] = ['property' => 'titel'];

		$codes = array_column(
			$this->validator->validate(mapping: $mapping, properties: $this->properties()),
			'code'
		);

		$this->assertSame(['mdto-mapping-unknown-element'], $codes);
	}

	public function testAPropertyTheSchemaDoesNotDeclareIsRefused(): void {
		$mapping = $this->completeMapping();
		$mapping['naam'] = ['property' => 'onderwerp'];

		$errors = $this->validator->validate(mapping: $mapping, properties: $this->properties());

		$this->assertSame('mdto-mapping-unknown-property', $errors[0]['code']);
		$this->assertStringContainsString('onderwerp', $errors[0]['message']);
	}

	/**
	 * Only the root segment is checked. A dotted path reaches into an
	 * object-typed property whose inner shape the schema need not declare, and
	 * refusing what cannot be verified would make every nested source unmappable.
	 */
	public function testADottedPathIsCheckedOnItsRootOnly(): void {
		$this->assertSame(
			[],
			$this->validator->validate(
				mapping: $this->completeMapping(),
				properties: $this->properties()
			)
		);
	}

	public function testAnEntryWithNoSourceIsRefused(): void {
		$mapping = $this->completeMapping();
		$mapping['naam'] = [];

		$this->assertSame(
			'mdto-mapping-no-source',
			$this->validator->validate(mapping: $mapping, properties: $this->properties())[0]['code']
		);
	}

	public function testAnEntryWithTwoSourcesIsRefused(): void {
		$mapping = $this->completeMapping();
		$mapping['naam'] = ['property' => 'titel', 'const' => 'Dossier'];

		$this->assertSame(
			'mdto-mapping-two-sources',
			$this->validator->validate(mapping: $mapping, properties: $this->properties())[0]['code']
		);
	}

	public function testAnEntryThatIsNotAnObjectIsRefused(): void {
		$mapping = $this->completeMapping();
		$mapping['naam'] = 'titel';

		$this->assertSame(
			'mdto-mapping-entry-not-an-object',
			$this->validator->validate(mapping: $mapping, properties: $this->properties())[0]['code']
		);
	}

	/**
	 * A declared but empty mapping is refused rather than treated as absent: an
	 * absent mapping means "use the built-in resolution", and an empty one
	 * means somebody meant to administer this and has not.
	 */
	public function testAnEmptyMappingIsRefusedRatherThanTreatedAsAbsent(): void {
		$this->assertSame(
			'mdto-mapping-empty',
			$this->validator->validate(mapping: [], properties: $this->properties())[0]['code']
		);
	}
}
