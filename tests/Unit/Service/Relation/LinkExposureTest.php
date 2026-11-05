<?php

/**
 * What a link hands over.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Service\Relation\LinkExposure;
use PHPUnit\Framework\TestCase;

/**
 * Verifies row 13.36.
 */
class LinkExposureTest extends TestCase {

	/**
	 * A relation type with an `exposes` set.
	 *
	 * @return array<string, mixed> The type.
	 */
	private function exposingType(): array {
		return ['key' => 'gerelateerd', LinkExposure::KEY => ['zaaknummer', 'status']];
	}//end exposingType()

	/**
	 * 🔴 A link shows the two fields it declares, and withholds the rest.
	 *
	 * @return void
	 */
	public function testALinkShowsWhatItDeclaresAndWithholdsTheRest(): void {
		$far = ['zaaknummer' => 'Z-1', 'status' => 'open', 'toelichting' => 'gevoelig', 'bsn' => '123'];

		$projection = (new LinkExposure())->project(
			farObject: $far,
			relationType: $this->exposingType(),
			readable: ['zaaknummer', 'status', 'toelichting', 'bsn']
		);

		$this->assertSame('Z-1', $projection['zaaknummer']);
		$this->assertSame('open', $projection['status']);
		$this->assertSame(LinkExposure::WITHHELD, $projection['toelichting']);
		$this->assertArrayHasKey(
			'bsn',
			$projection,
			'withheld, not absent: empty reads as "there is none" and withheld reads as "you may not see it"'
		);
		$this->assertSame(LinkExposure::WITHHELD, $projection['bsn']);
	}//end testALinkShowsWhatItDeclaresAndWithholdsTheRest()

	/**
	 * 🔴 Exposure NARROWS and never widens: a field the reader's own rules
	 * withhold stays withheld, whatever the link declares.
	 *
	 * A link that handed over whatever its schema author listed would turn
	 * every relation into an access decision made by whoever wrote the schema.
	 *
	 * @return void
	 */
	public function testExposureNeverWidensBeyondTheReadersOwnRules(): void {
		$exposure = new LinkExposure();
		$type = ['key' => 'gerelateerd', LinkExposure::KEY => ['zaaknummer', 'bsn']];

		$visible = $exposure->visibleProperties(relationType: $type, readable: ['zaaknummer', 'status']);

		$this->assertSame(['zaaknummer'], $visible, 'the link may not hand over a field this reader may not see');

		$projection = $exposure->project(
			farObject: ['zaaknummer' => 'Z-1', 'bsn' => '123'],
			relationType: $type,
			readable: ['zaaknummer', 'status']
		);
		$this->assertSame(LinkExposure::WITHHELD, $projection['bsn']);
	}//end testExposureNeverWidensBeyondTheReadersOwnRules()

	/**
	 * 🔴 A type declaring NO exposure narrows nothing — the behaviour every
	 * relation type has today.
	 *
	 * @return void
	 */
	public function testATypeDeclaringNoExposureNarrowsNothing(): void {
		$exposure = new LinkExposure();

		$this->assertFalse($exposure->declaresExposure(relationType: ['key' => 'gerelateerd']));
		$this->assertSame(
			['zaaknummer', 'status'],
			$exposure->visibleProperties(relationType: ['key' => 'gerelateerd'], readable: ['zaaknummer', 'status']),
			'every schema saved before this must keep behaving as it did'
		);
	}//end testATypeDeclaringNoExposureNarrowsNothing()

	/**
	 * 🔴 But a PRESENT-BUT-EMPTY exposure exposes nothing, which is a different
	 * statement and a legitimate one.
	 *
	 * @return void
	 */
	public function testAPresentButEmptyExposureExposesNothing(): void {
		$exposure = new LinkExposure();
		$type = ['key' => 'gerelateerd', LinkExposure::KEY => []];

		$this->assertTrue($exposure->declaresExposure(relationType: $type));
		$this->assertSame(
			[],
			$exposure->visibleProperties(relationType: $type, readable: ['zaaknummer', 'status']),
			'"related, and you may see none of it" is a thing a link is allowed to say'
		);
	}//end testAPresentButEmptyExposureExposesNothing()

	/**
	 * The exposed fields come back in the order the schema author listed them.
	 *
	 * @return void
	 */
	public function testTheExposedFieldsKeepTheDeclaredOrder(): void {
		$visible = (new LinkExposure())->visibleProperties(
			relationType: ['key' => 'g', LinkExposure::KEY => ['status', 'zaaknummer']],
			readable: ['zaaknummer', 'status']
		);

		$this->assertSame(['status', 'zaaknummer'], $visible, 'not the order the permission layer happened to answer in');
	}//end testTheExposedFieldsKeepTheDeclaredOrder()

	/**
	 * An exposed property the far schema does not declare is refused at save.
	 *
	 * @return void
	 */
	public function testAnExposedPropertyTheFarSchemaLacksIsRefusedAtSave(): void {
		$refusal = (new LinkExposure())->refusalFor(
			relationType: ['key' => 'g', LinkExposure::KEY => ['zaaknummer', 'zaknummer']],
			farProperties: ['zaaknummer', 'status'],
			typeName: 'gerelateerd'
		);

		$this->assertNotNull($refusal, 'a typo would be silently absent from every projection afterwards');
		$this->assertStringContainsString('zaknummer', (string)$refusal);
	}//end testAnExposedPropertyTheFarSchemaLacksIsRefusedAtSave()

	/**
	 * The control: a well-formed exposure saves.
	 *
	 * @return void
	 */
	public function testAWellFormedExposureIsAccepted(): void {
		$this->assertNull(
			(new LinkExposure())->refusalFor(
				relationType: $this->exposingType(),
				farProperties: ['zaaknummer', 'status', 'toelichting'],
				typeName: 'gerelateerd'
			),
			'the control: an exposure naming real properties is accepted'
		);
	}//end testAWellFormedExposureIsAccepted()
}//end class
