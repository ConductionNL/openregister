<?php

/**
 * Who may be told that a property exists, as against what it holds.
 *
 * 🔴 A FIELD NAME IS INFORMATION, AND THE GOVERNED NAMES ARE THE ONES WORTH
 * PROTECTING. `onderzoek_integriteit`, `schuldhulpverlening`,
 * `bijzondere_bijstand`: the name alone says what category of fact is held, and
 * on a record about one person it says the fact is held about them. A property
 * carries an authorization block or a scope precisely because it is sensitive,
 * so the set of governed names is by construction the set most worth not
 * printing.
 *
 * 🔑 THE CONTRACT OBJECTION IS SMALLER THAN IT LOOKS, and that is what settles
 * the decision. The API never returns a property this caller may not read, so
 * describing it promises a field that will never arrive. Leaving it out makes
 * the document MORE truthful: it describes the API this caller actually has.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The generated description and the read rule.
 *
 * @covers \OCA\OpenRegister\Service\OasService
 */
class SchemaShapeExposureTest extends TestCase {

	/**
	 * An OAS service whose read rule answers per property.
	 *
	 * @param array<string, bool>|null $reads Which properties are readable, or null for no rule at all.
	 *
	 * @return OasService The service.
	 */
	private function serviceWhereReadsAre(?array $reads): OasService {
		$rbac = null;

		if ($reads !== null) {
			$rbac = $this->createMock(PropertyRbacHandler::class);
			$rbac->method('canReadProperty')->willReturnCallback(
				static function (Schema $schema, string $property) use ($reads): bool {
					return ($reads[$property] ?? true);
				}
			);
		}

		return new OasService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IURLGenerator::class),
			null,
			null,
			$rbac
		);
	}//end serviceWhereReadsAre()

	/**
	 * A schema with one governed and one ordinary property.
	 *
	 * @param array<int, string> $required What the schema marks required.
	 *
	 * @return Schema The schema.
	 */
	private function governedSchema(array $required = []): Schema {
		$schema = new Schema();
		$schema->setTitle('Case');
		$schema->setProperties([
			'zaaknummer' => ['type' => 'string'],
			'onderzoek_integriteit' => [
				'type' => 'string',
				'scope' => 'team-a',
				'example' => 'lopend onderzoek naar melding 2026-114',
				'enum' => ['lopend', 'afgerond', 'geseponeerd'],
			],
		]);
		$schema->setRequired($required);

		return $schema;
	}//end governedSchema()

	/**
	 * Generate the description for a schema.
	 *
	 * @param OasService $service The service.
	 * @param Schema     $schema  The schema.
	 *
	 * @return array<string, mixed> The description.
	 */
	private function describe(OasService $service, Schema $schema): array {
		$method = new ReflectionMethod(OasService::class, 'enrichSchema');
		$method->setAccessible(true);

		return (array)$method->invoke($service, $schema);
	}//end describe()

	/**
	 * 🔴 A GOVERNED PROPERTY IS NOT NAMED TO SOMEBODY WHO MAY NOT READ IT.
	 *
	 * @return void
	 */
	public function testAGovernedPropertyIsNotNamedToSomebodyOutsideIt(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => false]),
			$this->governedSchema()
		);

		$this->assertArrayNotHasKey('onderzoek_integriteit', $described['properties']);
		$this->assertArrayHasKey('zaaknummer', $described['properties']);
	}//end testAGovernedPropertyIsNotNamedToSomebodyOutsideIt()

	/**
	 * 🔑 ITS EXAMPLE AND PERMITTED VALUES LEAVE WITH IT.
	 *
	 * An example is a sample answer and an enum is the set of permitted answers.
	 * Both are values outright, and "we hid the property, the example was
	 * elsewhere" is exactly the sort of gap that ships.
	 *
	 * @return void
	 */
	public function testItsExampleAndPermittedValuesLeaveWithIt(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => false]),
			$this->governedSchema()
		);

		$encoded = json_encode($described);

		$this->assertStringNotContainsString('lopend onderzoek naar melding', (string)$encoded);
		$this->assertStringNotContainsString('geseponeerd', (string)$encoded);
	}//end testItsExampleAndPermittedValuesLeaveWithIt()

	/**
	 * A colleague inside the scope is told the property exists.
	 *
	 * The control. Without it, a service that described nothing would pass the
	 * tests above while removing the whole document.
	 *
	 * @return void
	 */
	public function testAColleagueInsideTheScopeSeesIt(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => true]),
			$this->governedSchema()
		);

		$this->assertArrayHasKey('onderzoek_integriteit', $described['properties']);
		$this->assertArrayNotHasKey('x-openregister-withheld-properties', $described);
	}//end testAColleagueInsideTheScopeSeesIt()

	/**
	 * 🔑 THE OMISSION IS A COUNT, NEVER NAMES.
	 *
	 * Naming them would be the leak with an audit trail attached. Saying nothing
	 * would be worse in its own way: an integrator cannot tell "this is the
	 * whole schema" from "this is the part I am allowed to see".
	 *
	 * @return void
	 */
	public function testTheOmissionIsACountAndNeverNames(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => false]),
			$this->governedSchema()
		);

		$this->assertSame(1, $described['x-openregister-withheld-properties']);
		$this->assertStringNotContainsString(
			'onderzoek_integriteit',
			(string)json_encode($described),
			'The count must not become a list.'
		);
	}//end testTheOmissionIsACountAndNeverNames()

	/**
	 * 🔴 A REQUIRED LIST NAMING AN ABSENT PROPERTY IS NOT SATISFIABLE.
	 *
	 * A generated client would fail validation on a field it cannot even see,
	 * and the required list would name the property the document just withheld.
	 *
	 * @return void
	 */
	public function testRequiredDropsWhatTheDocumentCannotMention(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => false]),
			$this->governedSchema(['zaaknummer', 'onderzoek_integriteit'])
		);

		$this->assertSame(['zaaknummer'], ($described['required'] ?? []));
	}//end testRequiredDropsWhatTheDocumentCannotMention()

	/**
	 * Required keeps what the document still describes.
	 *
	 * @return void
	 */
	public function testRequiredKeepsWhatTheDocumentDescribes(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => true]),
			$this->governedSchema(['zaaknummer', 'onderzoek_integriteit'])
		);

		$this->assertSame(['zaaknummer', 'onderzoek_integriteit'], ($described['required'] ?? []));
	}//end testRequiredKeepsWhatTheDocumentDescribes()

	/**
	 * An ungoverned schema is described in full, and says nothing was withheld.
	 *
	 * Note there is NO read rule wired here: if an ungoverned schema reached the
	 * lookup it would fail closed and every ordinary schema would lose its
	 * properties.
	 *
	 * @return void
	 */
	public function testAnUngovernedSchemaIsDescribedInFull(): void {
		$schema = new Schema();
		$schema->setTitle('Plain');
		$schema->setProperties(['a' => ['type' => 'string'], 'b' => ['type' => 'string']]);

		$described = $this->describe($this->serviceWhereReadsAre(null), $schema);

		$this->assertArrayHasKey('a', $described['properties']);
		$this->assertArrayHasKey('b', $described['properties']);
		$this->assertArrayNotHasKey('x-openregister-withheld-properties', $described);
	}//end testAnUngovernedSchemaIsDescribedInFull()

	/**
	 * With a governed schema and no rule to ask, the property is withheld.
	 *
	 * Fails closed: the alternative is printing a name whose access nobody
	 * checked.
	 *
	 * @return void
	 */
	public function testWithNoRuleToAskAGovernedPropertyIsWithheld(): void {
		$described = $this->describe($this->serviceWhereReadsAre(null), $this->governedSchema());

		$this->assertArrayNotHasKey('onderzoek_integriteit', $described['properties']);
		$this->assertSame(1, $described['x-openregister-withheld-properties']);
	}//end testWithNoRuleToAskAGovernedPropertyIsWithheld()

	/**
	 * The core API properties survive whatever the read rule says.
	 *
	 * `id` and `_self` are not schema properties and are not governed by one;
	 * losing them would break every client for a reason unrelated to the rule.
	 *
	 * @return void
	 */
	public function testTheCoreApiPropertiesAreUntouched(): void {
		$described = $this->describe(
			$this->serviceWhereReadsAre(['onderzoek_integriteit' => false]),
			$this->governedSchema()
		);

		$this->assertArrayHasKey('id', $described['properties']);
		$this->assertArrayHasKey('_self', $described['properties']);
	}//end testTheCoreApiPropertiesAreUntouched()
}//end class
