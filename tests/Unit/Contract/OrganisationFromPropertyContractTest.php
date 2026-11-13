<?php

/**
 * The contract opencatalogi builds on: the annotation shape and the two refusal bodies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\OrganisationFromPropertyException;
use OCA\OpenRegister\Service\Organisation\OrganisationAnnotation;
use PHPUnit\Framework\TestCase;

/**
 * Pins what another app may rely on, so a rename here fails here and not there.
 *
 * `opencatalogi/publications-reference-the-shared-organisation` declares
 * `{"x-openregister-organisation": {"fromProperty": "organization"}}` on its
 * publication schema and reads the `error`, `property` and `organisation` keys
 * of the refusals. Both are consumer-visible and neither is covered by a
 * behaviour test of the resolver alone, because a behaviour test is free to
 * follow a rename.
 *
 * @covers \OCA\OpenRegister\Service\Organisation\OrganisationAnnotation
 * @covers \OCA\OpenRegister\Exception\OrganisationFromPropertyException
 *
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 */
class OrganisationFromPropertyContractTest extends TestCase {

	/**
	 * The annotation is spelt as the consumer writes it, and survives a schema save.
	 *
	 * @return void
	 */
	public function testTheAnnotationShapeIsTheOneTheConsumerWrites(): void {
		$declaration = ['x-openregister-organisation' => ['fromProperty' => 'organization']];
		$schema = new Schema();
		$schema->setProperties(['organization' => ['type' => 'string']]);
		$schema->setConfiguration($declaration);

		$this->assertSame(expected: 'x-openregister-organisation', actual: OrganisationAnnotation::ANNOTATION);
		$this->assertSame(expected: 'fromProperty', actual: OrganisationAnnotation::FROM_PROPERTY);
		$this->assertSame(expected: $declaration, actual: $schema->getConfiguration());
		$this->assertSame(expected: 'organization', actual: OrganisationAnnotation::propertyOf(schema: $schema));
		$this->assertSame(expected: [], actual: OrganisationAnnotation::refusals(
			declaration: $declaration['x-openregister-organisation'],
			properties: ['organization' => ['type' => 'string']]
		));
	}//end testTheAnnotationShapeIsTheOneTheConsumerWrites()

	/**
	 * The 403 body: `error`, `property`, `organisation`.
	 *
	 * @return void
	 */
	public function testTheForbiddenBodyNamesThePropertyAndTheOrganisation(): void {
		$refusal = OrganisationFromPropertyException::notAMember(property: 'organization', organisation: 'org-a');
		$body = $refusal->getBody();

		$this->assertSame(expected: 403, actual: $refusal->getStatusCode());
		$this->assertSame(expected: 'organisation-not-a-member', actual: $body['error']);
		$this->assertSame(expected: 'organization', actual: $body['property']);
		$this->assertSame(expected: 'org-a', actual: $body['organisation']);
	}//end testTheForbiddenBodyNamesThePropertyAndTheOrganisation()

	/**
	 * The two 422 bodies: the same three keys, and the contradiction also names `@self`'s value.
	 *
	 * @return void
	 */
	public function testTheUnprocessableBodiesNameBothValues(): void {
		$unknown = OrganisationFromPropertyException::unknown(property: 'organization', organisation: 'org-ghost');
		$contradiction = OrganisationFromPropertyException::contradiction(
			property: 'organization',
			organisation: 'org-a',
			selfSubmission: 'org-b'
		);

		$this->assertSame(expected: 422, actual: $unknown->getStatusCode());
		$this->assertSame(expected: 'organisation-unknown', actual: $unknown->getBody()['error']);
		$this->assertSame(expected: 'org-ghost', actual: $unknown->getBody()['organisation']);

		$this->assertSame(expected: 422, actual: $contradiction->getStatusCode());
		$this->assertSame(expected: 'organisation-contradicts-property', actual: $contradiction->getBody()['error']);
		$this->assertSame(expected: 'organization', actual: $contradiction->getBody()['property']);
		$this->assertSame(expected: 'org-a', actual: $contradiction->getBody()['organisation']);
		$this->assertSame(expected: 'org-b', actual: $contradiction->getBody()['selfOrganisation']);
	}//end testTheUnprocessableBodiesNameBothValues()

	/**
	 * Every refusal body carries the three keys a consumer reads, and a message.
	 *
	 * @return void
	 */
	public function testEveryRefusalCarriesTheSameKeys(): void {
		$refusals = [
			OrganisationFromPropertyException::notAMember(property: 'p', organisation: 'o'),
			OrganisationFromPropertyException::unknown(property: 'p', organisation: 'o'),
			OrganisationFromPropertyException::contradiction(property: 'p', organisation: 'o', selfSubmission: 'q'),
		];

		foreach ($refusals as $refusal) {
			$body = $refusal->getBody();
			$this->assertSame(expected: ['error', 'property', 'organisation', 'message'], actual: array_slice(array_keys($body), 0, 4));
			$this->assertNotSame(expected: '', actual: $body['message']);
		}
	}//end testEveryRefusalCarriesTheSameKeys()
}//end class
