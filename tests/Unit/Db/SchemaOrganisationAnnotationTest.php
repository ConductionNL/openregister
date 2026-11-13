<?php

/**
 * The `x-openregister-organisation` annotation: kept, and refused when it resolves nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks;
use OCA\OpenRegister\Service\Organisation\OrganisationAnnotation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks what a schema may declare about the property its objects' organisation comes from.
 *
 * @covers \OCA\OpenRegister\Service\Organisation\OrganisationAnnotation
 * @covers \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 *
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 */
class SchemaOrganisationAnnotationTest extends TestCase {

	/**
	 * A schema with the given properties and declaration.
	 *
	 * @param array<string, mixed> $properties  The properties block.
	 * @param mixed                $declaration The annotation's value.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties, mixed $declaration): Schema {
		$schema = new Schema();
		$schema->setSlug('publication');
		$schema->setProperties($properties);
		$schema->setConfiguration([OrganisationAnnotation::ANNOTATION => $declaration]);

		return $schema;
	}//end schema()

	/**
	 * The checks, with a logger that records nothing anyone asserts on.
	 *
	 * @return AccessDeclarationChecks The checks.
	 */
	private function checks(): AccessDeclarationChecks {
		return new AccessDeclarationChecks(logger: $this->createMock(LoggerInterface::class));
	}//end checks()

	/**
	 * The annotation key is in the vocabulary, so `setConfiguration()` keeps it.
	 *
	 * Without this the whole feature is dead on arrival: the key would be
	 * dropped at save and every object would keep the stamped organisation.
	 *
	 * @return void
	 */
	public function testTheAnnotationSurvivesSetConfiguration(): void {
		$schema = new Schema();
		$schema->setConfiguration(
			[OrganisationAnnotation::ANNOTATION => [OrganisationAnnotation::FROM_PROPERTY => 'organization']]
		);

		$this->assertSame(
			expected: ['fromProperty' => 'organization'],
			actual: ($schema->getConfiguration()[OrganisationAnnotation::ANNOTATION] ?? null)
		);
		$this->assertContains(needle: OrganisationAnnotation::ANNOTATION, haystack: Schema::ANNOTATION_VOCABULARY);
	}//end testTheAnnotationSurvivesSetConfiguration()

	/**
	 * A declaration naming a property the schema does not declare is refused.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyIsRefused(): void {
		$schema = $this->schema(
			properties: ['title' => ['type' => 'string']],
			declaration: ['fromProperty' => 'organization']
		);

		$this->expectExceptionMessageMatches(regularExpression: '/"organization" is not a property of this schema/');
		$this->checks()->checkOrganisation(schema: $schema);
	}//end testAnUndeclaredPropertyIsRefused()

	/**
	 * A property that is a reference is accepted: a reference names an organisation.
	 *
	 * @return void
	 */
	public function testAReferencePropertyIsAccepted(): void {
		$schema = $this->schema(
			properties: ['organization' => ['$ref' => '#/components/schemas/organisation']],
			declaration: ['fromProperty' => 'organization']
		);

		$this->checks()->checkOrganisation(schema: $schema);
		$this->assertSame(expected: 'organization', actual: OrganisationAnnotation::propertyOf(schema: $schema));
	}//end testAReferencePropertyIsAccepted()

	/**
	 * A string property is accepted: it holds the organisation's uuid.
	 *
	 * @return void
	 */
	public function testAStringPropertyIsAccepted(): void {
		$schema = $this->schema(
			properties: ['organization' => ['type' => 'string']],
			declaration: ['fromProperty' => 'organization']
		);

		$this->checks()->checkOrganisation(schema: $schema);
		$this->assertSame(expected: 'organization', actual: OrganisationAnnotation::propertyOf(schema: $schema));
	}//end testAStringPropertyIsAccepted()

	/**
	 * A property that is neither a string nor a reference is refused.
	 *
	 * @return void
	 */
	public function testANonStringNonReferencePropertyIsRefused(): void {
		$schema = $this->schema(
			properties: ['organization' => ['type' => 'integer']],
			declaration: ['fromProperty' => 'organization']
		);

		$this->expectExceptionMessageMatches(regularExpression: '/must be a string or a reference/');
		$this->checks()->checkOrganisation(schema: $schema);
	}//end testANonStringNonReferencePropertyIsRefused()

	/**
	 * A declaration that is not an object naming a property is refused.
	 *
	 * @return void
	 */
	public function testADeclarationThatNamesNoPropertyIsRefused(): void {
		$schema = $this->schema(properties: ['organization' => ['type' => 'string']], declaration: ['organization']);

		$this->expectExceptionMessageMatches(regularExpression: '/must be an object with a "fromProperty" key/');
		$this->checks()->checkOrganisation(schema: $schema);
	}//end testADeclarationThatNamesNoPropertyIsRefused()

	/**
	 * An empty `fromProperty` is refused rather than read as "no declaration".
	 *
	 * @return void
	 */
	public function testAnEmptyFromPropertyIsRefused(): void {
		$schema = $this->schema(
			properties: ['organization' => ['type' => 'string']],
			declaration: ['fromProperty' => '  ']
		);

		$this->expectExceptionMessageMatches(regularExpression: '/must name a property of this schema/');
		$this->checks()->checkOrganisation(schema: $schema);
	}//end testAnEmptyFromPropertyIsRefused()

	/**
	 * A schema that declares nothing is left alone, and names no property.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationIsUnchanged(): void {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration([]);

		$this->checks()->checkOrganisation(schema: $schema);
		$this->assertNull(actual: OrganisationAnnotation::propertyOf(schema: $schema));
		$this->assertNull(actual: OrganisationAnnotation::propertyOf(schema: null));
	}//end testASchemaWithoutTheAnnotationIsUnchanged()
}//end class
