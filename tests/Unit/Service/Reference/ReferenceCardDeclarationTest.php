<?php

/**
 * What a schema's declared reference card reads as, and what it refuses.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Reference;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the card a schema declares.
 *
 * @covers \OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class ReferenceCardDeclarationTest extends TestCase {

	/**
	 * A schema declaring the given card block.
	 *
	 * @param mixed $block The block, or null to declare none.
	 *
	 * @return Schema The schema.
	 */
	private function schema(mixed $block): Schema {
		$schema = new Schema();
		if ($block !== null) {
			$schema->setConfiguration([ReferenceCardDeclaration::ANNOTATION => $block]);
		}

		return $schema;
	}//end schema()

	/**
	 * A declared card names its title, its summary properties and its image.
	 *
	 * @return void
	 */
	public function testADeclaredCardNamesWhatItShows(): void {
		$card = ReferenceCardDeclaration::of(
			schema: $this->schema(
				['titleProperty' => ' adres ', 'summary' => ['bouwjaar', 'gebruiksdoel'], 'icon' => 'home', 'imageProperty' => 'foto']
			)
		);

		$this->assertNotNull($card);
		$this->assertSame('adres', $card->titleProperty);
		$this->assertSame(['bouwjaar', 'gebruiksdoel'], $card->summary);
		$this->assertSame('home', $card->icon);
		$this->assertSame('foto', $card->imageProperty);
	}//end testADeclaredCardNamesWhatItShows()

	/**
	 * At most three summary properties are kept, and unusable entries are dropped.
	 *
	 * @return void
	 */
	public function testSummaryIsCappedAtThreeUsableProperties(): void {
		$card = ReferenceCardDeclaration::of(
			schema: $this->schema(['summary' => ['a', '', 'b', 7, 'c', 'd']])
		);

		$this->assertSame(['a', 'b', 'c'], $card?->summary);
	}//end testSummaryIsCappedAtThreeUsableProperties()

	/**
	 * A schema that declares nothing usable has no card at all.
	 *
	 * @return void
	 */
	public function testNoUsableDeclarationIsNoCard(): void {
		$this->assertNull(ReferenceCardDeclaration::of(schema: $this->schema(null)));
		$this->assertNull(ReferenceCardDeclaration::of(schema: $this->schema('a string')));
		$this->assertNull(ReferenceCardDeclaration::of(schema: $this->schema([])));
		$this->assertNull(ReferenceCardDeclaration::of(schema: $this->schema(['titleProperty' => '  ', 'summary' => []])));
	}//end testNoUsableDeclarationIsNoCard()

	/**
	 * The title and the summary rows come from the object, and an empty value is left out.
	 *
	 * @return void
	 */
	public function testTheCardReadsTheObject(): void {
		$card = ReferenceCardDeclaration::of(
			schema: $this->schema(['titleProperty' => 'adres', 'summary' => ['bouwjaar', 'leeg', 'open']])
		);
		$object = ['adres' => 'Lindelaan 2', 'bouwjaar' => 1923, 'leeg' => '  ', 'open' => false, 'foto' => 'x'];

		$this->assertSame('Lindelaan 2', $card?->title(objectData: $object));
		$this->assertSame(
			[['label' => 'Bouwjaar', 'value' => '1923'], ['label' => 'Open', 'value' => 'false']],
			$card?->summaryRows(objectData: $object)
		);
	}//end testTheCardReadsTheObject()

	/**
	 * A title the object does not fill, and a card that names none, read as no title.
	 *
	 * @return void
	 */
	public function testAnUnfilledTitleIsNoTitle(): void {
		$named = ReferenceCardDeclaration::of(schema: $this->schema(['titleProperty' => 'adres']));
		$unnamed = ReferenceCardDeclaration::of(schema: $this->schema(['summary' => ['bouwjaar']]));

		$this->assertNull($named?->title(objectData: ['adres' => '']));
		$this->assertNull($named?->title(objectData: ['adres' => ['a']]));
		$this->assertNull($unnamed?->title(objectData: ['adres' => 'Lindelaan 2']));
	}//end testAnUnfilledTitleIsNoTitle()

	/**
	 * An image is read as a string, or from a file object's download url.
	 *
	 * @return void
	 */
	public function testTheImageIsReadAsAStringOrAFilesUrl(): void {
		$card = ReferenceCardDeclaration::of(schema: $this->schema(['imageProperty' => 'foto']));

		$this->assertSame('https://cloud.example/foto.png', $card?->image(objectData: ['foto' => 'https://cloud.example/foto.png']));
		$this->assertSame('https://cloud.example/dl', $card?->image(objectData: ['foto' => ['downloadUrl' => 'https://cloud.example/dl']]));
		$this->assertSame('https://cloud.example/u', $card?->image(objectData: ['foto' => ['url' => 'https://cloud.example/u']]));
		$this->assertNull($card?->image(objectData: ['foto' => []]));
		$this->assertNull($card?->image(objectData: []));
		$this->assertNull(ReferenceCardDeclaration::of(schema: $this->schema(['titleProperty' => 'adres']))?->image(objectData: ['foto' => 'x']));
	}//end testTheImageIsReadAsAStringOrAFilesUrl()

	/**
	 * A card naming a property the schema does not have is refused, with the property named.
	 *
	 * @return void
	 */
	public function testACardNamingAnAbsentPropertyIsRefused(): void {
		$properties = ['adres' => ['type' => 'string'], 'foto' => ['type' => 'file']];

		$this->assertSame([], ReferenceCardDeclaration::faults(block: null, properties: $properties));
		$this->assertSame(
			[],
			ReferenceCardDeclaration::faults(
				block: ['titleProperty' => 'adres', 'summary' => ['adres'], 'imageProperty' => 'foto', 'icon' => 'home'],
				properties: $properties
			)
		);
		$this->assertSame(
			['x-openregister-card must be an object.'],
			ReferenceCardDeclaration::faults(block: 'nope', properties: $properties)
		);
		$this->assertSame(
			['x-openregister-card.titleProperty ""straat"" is not a property of this schema.'],
			ReferenceCardDeclaration::faults(block: ['titleProperty' => 'straat'], properties: $properties)
		);
		$this->assertSame(
			['x-openregister-card.imageProperty ""plaatje"" is not a property of this schema.'],
			ReferenceCardDeclaration::faults(block: ['imageProperty' => 'plaatje'], properties: $properties)
		);
		$this->assertSame(
			['x-openregister-card.summary must be a list of property names.'],
			ReferenceCardDeclaration::faults(block: ['summary' => 'adres'], properties: $properties)
		);
		$this->assertSame(
			['x-openregister-card.summary ""straat"" is not a property of this schema.'],
			ReferenceCardDeclaration::faults(block: ['summary' => ['adres', 'straat']], properties: $properties)
		);
		$this->assertSame(
			['x-openregister-card.summary names more than 3 properties.'],
			ReferenceCardDeclaration::faults(block: ['summary' => ['adres', 'adres', 'adres', 'adres']], properties: $properties)
		);
	}//end testACardNamingAnAbsentPropertyIsRefused()
}//end class
