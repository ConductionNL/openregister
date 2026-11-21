<?php

/**
 * FacetableFieldDiscovery: the searchable fields come from the injected search profile.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\PropertySearchProfileInterface;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use OCA\OpenRegister\Service\Object\FacetableFieldDiscovery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Discovery asks the profile it was given, once per advertised property, and reports its answer.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetableFieldDiscovery
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 */
final class FacetableFieldDiscoveryTest extends TestCase {

	/**
	 * A schema with one plain and one facetable property.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setProperties(
			[
				'title' => ['type' => 'string', 'title' => 'Title'],
				'status' => ['type' => 'string', 'facetable' => true],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The searchable fields carry exactly what the injected profile declared.
	 *
	 * @return void
	 */
	public function testTheSearchableFieldsComeFromTheInjectedProfile(): void {
		$asked   = [];
		$profile = $this->createMock(PropertySearchProfileInterface::class);
		$profile->method('declaredBy')->willReturnCallback(
			static function (array $property) use (&$asked): array {
				$asked[] = $property['type'];
				return ['matchType' => 'exact', 'inputControl' => 'select', 'declared' => true];
			}
		);

		$fields = (new FacetableFieldDiscovery(logger: new NullLogger(), searchProfile: $profile, propertyRbac: null))->fieldsOf(schemas: [self::schema()]);

		$this->assertSame(['string', 'string'], $asked);
		$this->assertSame(
			['matchType' => 'exact', 'inputControl' => 'select', 'declared' => true, 'title' => 'Title'],
			$fields['searchable_fields']['title']
		);
	}//end testTheSearchableFieldsComeFromTheInjectedProfile()

	/**
	 * With the Search area's own profile, the answer is what that profile declares for the property.
	 *
	 * @return void
	 */
	public function testTheSearchProfileGivesTheSameAnswerAsBefore(): void {
		$profile = new PropertySearchProfile();
		$fields  = (new FacetableFieldDiscovery(logger: new NullLogger(), searchProfile: $profile, propertyRbac: null))->fieldsOf(schemas: [self::schema()]);

		$this->assertSame(
			$profile->declaredBy(property: ['type' => 'string', 'facetable' => true]) + ['title' => null],
			$fields['searchable_fields']['status']
		);
	}//end testTheSearchProfileGivesTheSameAnswerAsBefore()
}//end class
