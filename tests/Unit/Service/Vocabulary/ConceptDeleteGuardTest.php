<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard}.
 *
 * The count is the useful half of the refusal. "Cannot delete" tells someone
 * nothing; "cannot delete, 1,284 objects hold it" tells them what to do next,
 * which is to retire it instead.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConceptDeleteGuardTest extends TestCase {

	private const SCHEME = 'https://example.org/resultaattypen';

	private SchemaMapper&MockObject $schemas;

	private MagicMapper&MockObject $objects;

	private ConceptDeleteGuard $guard;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->objects = $this->createMock(MagicMapper::class);

		$this->guard = new ConceptDeleteGuard(
			lifecycle: new ConceptLifecycle(),
			schemas: $this->schemas,
			objects: $this->objects
		);
	}//end setUp()

	/**
	 * A schema holding a coded property on our scheme, plus one that does not.
	 *
	 * @return array<int,Schema> The schemas.
	 */
	private function fleetSchemas(): array {
		$zaak = new Schema();
		$zaak->setSlug('zaak');
		$zaak->setProperties(
			[
				'resultaat' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME],
				],
			]
		);

		$unrelated = new Schema();
		$unrelated->setSlug('contact');
		$unrelated->setProperties(['email' => ['type' => 'string']]);

		$otherScheme = new Schema();
		$otherScheme->setSlug('besluit');
		$otherScheme->setProperties(
			[
				'soort' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/anders'],
				],
			]
		);

		return [$zaak, $unrelated, $otherScheme];
	}//end fleetSchemas()

	/**
	 * A concept is recognised by its schema's slug, not by sniffing the
	 * payload: a case object carrying an `inScheme` key is not a concept.
	 *
	 * @return void
	 */
	public function testAConceptIsRecognisedByItsSchemaSlug(): void {
		$concept = new Schema();
		$concept->setSlug('concept');
		$this->assertTrue($this->guard->isConcept(schema: $concept));

		$zaak = new Schema();
		$zaak->setSlug('zaak');
		$this->assertFalse($this->guard->isConcept(schema: $zaak));
	}//end testAConceptIsRecognisedByItsSchemaSlug()

	/**
	 * A system-defined value cannot be deleted, and the refusal points at the
	 * operation that IS safe.
	 *
	 * @return void
	 */
	public function testASystemDefinedValueCannotBeDeleted(): void {
		$concept = ['uri' => 'urn:afgehandeld', 'systemDefined' => true];

		$this->assertTrue($this->guard->isSystemDefined(concept: $concept));

		$message = $this->guard->systemDefinedMessage(concept: $concept);
		$this->assertStringContainsString('urn:afgehandeld', $message);
		$this->assertStringContainsString('validity window', $message);
	}//end testASystemDefinedValueCannotBeDeleted()

	/**
	 * A value in use refuses deletion NAMING THE COUNT, and only the schemas
	 * bound to its scheme are counted.
	 *
	 * @return void
	 */
	public function testAValueInUseIsCountedOnlyWhereItCouldBeHeld(): void {
		$this->schemas->method('findAll')->willReturn($this->fleetSchemas());
		$this->objects->expects($this->once())
			->method('countAll')
			->willReturn(1284);

		$usage = $this->guard->usage(
			concept: ['uri' => 'urn:afgehandeld', 'notation' => 'AFG'],
			schemeUri: self::SCHEME
		);

		$this->assertSame(1284, $usage['count']);
		$this->assertSame(
			[['schema' => 'zaak', 'property' => 'resultaat', 'count' => 1284]],
			$usage['holders']
		);

		$message = $this->guard->inUseMessage(
			concept: ['uri' => 'urn:afgehandeld'],
			usage: $usage
		);
		$this->assertStringContainsString('1284', $message);
		$this->assertStringContainsString('zaak.resultaat (1284)', $message);
		$this->assertStringContainsString('validity window', $message);
	}//end testAValueInUseIsCountedOnlyWhereItCouldBeHeld()

	/**
	 * A value nothing holds is deletable: the guard reports zero and refuses
	 * nothing.
	 *
	 * @return void
	 */
	public function testAValueNothingHoldsIsDeletable(): void {
		$this->schemas->method('findAll')->willReturn($this->fleetSchemas());
		$this->objects->method('countAll')->willReturn(0);

		$usage = $this->guard->usage(concept: ['uri' => 'urn:ongebruikt'], schemeUri: self::SCHEME);

		$this->assertSame(0, $usage['count']);
		$this->assertSame([], $usage['holders']);
	}//end testAValueNothingHoldsIsDeletable()

	/**
	 * A guard that cannot read the schema table counts nothing rather than
	 * blocking every delete.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaTableCountsNothing(): void {
		$this->schemas->method('findAll')->willThrowException(new \RuntimeException('database down'));

		$this->assertSame(
			['count' => 0, 'holders' => []],
			$this->guard->usage(concept: ['uri' => 'urn:x'], schemeUri: self::SCHEME)
		);
	}//end testAnUnreadableSchemaTableCountsNothing()

	/**
	 * A notation-storing property is counted on the notation, because that is
	 * what its objects hold. Counting on the uri there would report zero and
	 * let the delete through.
	 *
	 * @return void
	 */
	public function testANotationStoringPropertyIsCountedOnItsNotation(): void {
		$zaak = new Schema();
		$zaak->setSlug('zaak');
		$zaak->setProperties(
			[
				'resultaat' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME, 'store' => 'notation'],
				],
			]
		);

		$this->schemas->method('findAll')->willReturn([$zaak]);

		$seen = [];
		$this->objects->method('countAll')->willReturnCallback(
			function (?array $_filters = null, ?Schema $schema = null) use (&$seen): int {
				$seen[] = $_filters;

				return 3;
			}
		);

		$this->guard->usage(
			concept: ['uri' => 'urn:afgehandeld', 'notation' => 'AFG'],
			schemeUri: self::SCHEME
		);

		$this->assertSame([['resultaat' => 'AFG']], $seen);
	}//end testANotationStoringPropertyIsCountedOnItsNotation()
}//end class
