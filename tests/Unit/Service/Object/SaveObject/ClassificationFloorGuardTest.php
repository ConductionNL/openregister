<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\SaveObject\ClassificationFloorGuard;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use PHPUnit\Framework\TestCase;

/**
 * A write may raise a classification and not lower it below its floor.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ClassificationFloorGuard
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 */
final class ClassificationFloorGuardTest extends TestCase {

	private const LEVELS = ['openbaar', 'intern', 'zaakvertrouwelijk', 'geheim'];

	/**
	 * A document schema whose block declares a floor.
	 *
	 * @param array<string, string>|null $floor The floor entry, or null for none.
	 *
	 * @return Schema
	 */
	private static function schema(?array $floor): Schema {
		$block = ['property' => 'vertrouwelijkheidaanduiding', 'levels' => self::LEVELS];
		if ($floor !== null) {
			$block['floor'] = $floor;
		}

		$schema = new Schema();
		$schema->setSlug('document');
		$schema->setProperties(
			[
				'vertrouwelijkheidaanduiding' => ['type' => 'string'],
				'minimum' => ['type' => 'string'],
				'documentType' => ['type' => 'string'],
			]
		);
		$schema->setConfiguration([ClassificationScale::ANNOTATION => $block]);
		return $schema;
	}

	/**
	 * A guard whose references resolve from a map, recording what it was asked.
	 *
	 * @param array<string, array<string, mixed>> $objects Referenced objects by uuid.
	 * @param array<int, string>                  $asked   Receives every reference asked for.
	 *
	 * @return ClassificationFloorGuard
	 */
	private static function guard(array $objects=[], array &$asked=[]): ClassificationFloorGuard {
		return new ClassificationFloorGuard(
			referencedData: static function (string $reference) use ($objects, &$asked): ?array {
				$asked[] = $reference;
				return ($objects[$reference] ?? null);
			}
		);
	}

	public function testLoweringBelowTheTypeDefaultIsRefusedNamingBothLevels(): void {
		$asked = [];
		$guard = self::guard(['type-1' => ['minimum' => 'zaakvertrouwelijk']], $asked);

		try {
			$guard->refuseBelowFloor(
				self::schema(['property' => 'minimum', 'sourceRelation' => 'documentType']),
				['vertrouwelijkheidaanduiding' => 'openbaar', 'documentType' => 'type-1']
			);
			$this->fail('A write below the floor must be refused.');
		} catch (ValidationException $e) {
			$this->assertSame('Classification "openbaar" is below the floor "zaakvertrouwelijk" for this object', $e->getMessage());
		}

		$this->assertSame(['type-1'], $asked);
	}

	public function testRaisingAboveOrMatchingTheFloorIsAllowed(): void {
		$guard = self::guard(['type-1' => ['minimum' => 'zaakvertrouwelijk']]);
		$schema = self::schema(['property' => 'minimum', 'sourceRelation' => 'documentType']);

		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'geheim', 'documentType' => 'type-1']);
		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk', 'documentType' => ['id' => 'type-1']]);

		$this->addToAssertionCount(1);
	}

	public function testAFloorOnTheObjectItselfIsRead(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Classification "intern" is below the floor "geheim" for this object');

		self::guard()->refuseBelowFloor(
			self::schema(['property' => 'minimum']),
			['vertrouwelijkheidaanduiding' => 'intern', 'minimum' => 'geheim']
		);
	}

	public function testAFloorThatCannotBeReadOrPlacedRefusesNothing(): void {
		$asked = [];
		$guard = self::guard(['type-1' => ['minimum' => 'onbekend']], $asked);
		$schema = self::schema(['property' => 'minimum', 'sourceRelation' => 'documentType']);

		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'openbaar', 'documentType' => 'type-1']);
		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'openbaar', 'documentType' => 'missing']);
		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'openbaar', 'documentType' => '  ']);
		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'openbaar']);

		$this->assertSame(['type-1', 'missing'], $asked);
	}

	public function testAnUnknownOrAbsentRequestedLabelRanksMostRestrictiveAndIsNotBelowAFloor(): void {
		$guard = self::guard();
		$schema = self::schema(['property' => 'minimum']);

		$guard->refuseBelowFloor($schema, ['vertrouwelijkheidaanduiding' => 'onbekend', 'minimum' => 'geheim']);
		$guard->refuseBelowFloor($schema, ['minimum' => 'geheim']);

		$this->addToAssertionCount(1);
	}

	public function testASchemaWithoutABlockOrWithoutAFloorAsksNothing(): void {
		$asked = [];
		$guard = self::guard([], $asked);

		$guard->refuseBelowFloor(new Schema(), ['vertrouwelijkheidaanduiding' => 'openbaar']);
		$guard->refuseBelowFloor(self::schema(null), ['vertrouwelijkheidaanduiding' => 'openbaar', 'documentType' => 'type-1']);

		$this->assertSame([], $asked);
	}
}
