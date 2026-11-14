<?php

/**
 * Properties of a structured extended type share one named component in the OpenAPI document.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasExtendedTypes;
use OCA\OpenRegister\Service\Oas\OasPropertySanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Oas\OasExtendedTypes
 * @covers \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses   \OCA\OpenRegister\Service\Oas\OasNames
 */
class OasExtendedTypesTest extends TestCase {

	public function testEachTypeIsDescribedByAReferenceToItsComponent(): void {
		$sanitizer = new OasPropertySanitizer();

		$this->assertSame(['$ref' => '#/components/schemas/CalendarRange'], $sanitizer->sanitize(['type' => 'calendar-range', 'format' => 'date']));
		$this->assertSame(
			['$ref' => '#/components/schemas/ImageUrl', 'description' => 'The cover'],
			$sanitizer->sanitize(['type' => 'image-url', 'description' => 'The cover', 'objectConfiguration' => []])
		);
		$this->assertSame(['type' => 'string', 'description' => 'Property value'], $sanitizer->sanitize(['type' => 'color']));
	}//end testEachTypeIsDescribedByAReferenceToItsComponent()

	public function testANestedPropertyIsReferencedToo(): void {
		$clean = (new OasPropertySanitizer())->sanitize(['type' => 'array', 'items' => ['type' => 'image-url']]);

		$this->assertSame(['$ref' => '#/components/schemas/ImageUrl'], $clean['items']);
	}//end testANestedPropertyIsReferencedToo()

	public function testTwoPropertiesOfOneTypeShareOneComponent(): void {
		$sanitizer = new OasPropertySanitizer();
		$oas = [
			'components' => [
				'schemas' => [
					'Booking' => ['type' => 'object', 'properties' => ['period' => $sanitizer->sanitize(['type' => 'calendar-range'])]],
					'Reservation' => ['type' => 'object', 'properties' => ['window' => $sanitizer->sanitize(['type' => 'calendar-range'])]],
				],
			],
		];

		$document = OasExtendedTypes::withUsedComponents($oas);

		$this->assertSame('#/components/schemas/CalendarRange', $document['components']['schemas']['Booking']['properties']['period']['$ref']);
		$this->assertSame('#/components/schemas/CalendarRange', $document['components']['schemas']['Reservation']['properties']['window']['$ref']);
		$this->assertSame(['start', 'end'], $document['components']['schemas']['CalendarRange']['required']);
		$this->assertArrayNotHasKey('ImageUrl', $document['components']['schemas']);
		$this->assertSame(['Booking', 'Reservation', 'CalendarRange'], array_keys($document['components']['schemas']));
	}//end testTwoPropertiesOfOneTypeShareOneComponent()

	public function testAReferenceFromAPathAddsTheComponentAndAnExistingOneIsKept(): void {
		$ownImage = ['type' => 'object', 'description' => 'A schema the register itself titled ImageUrl'];
		$oas = [
			'paths' => ['/x' => ['get' => ['schema' => ['$ref' => '#/components/schemas/CalendarRange']]]],
			'components' => ['schemas' => ['ImageUrl' => $ownImage, 'Other' => ['$ref' => '#/components/schemas/ImageUrl']]],
		];

		$document = OasExtendedTypes::withUsedComponents($oas);

		$this->assertSame(OasExtendedTypes::COMPONENTS['CalendarRange'], $document['components']['schemas']['CalendarRange']);
		$this->assertSame($ownImage, $document['components']['schemas']['ImageUrl']);
	}//end testAReferenceFromAPathAddsTheComponentAndAnExistingOneIsKept()

	public function testADocumentWithoutTheseTypesIsUnchanged(): void {
		$oas = ['components' => ['schemas' => ['Booking' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]]];

		$this->assertSame($oas, OasExtendedTypes::withUsedComponents($oas));
	}//end testADocumentWithoutTheseTypesIsUnchanged()
}//end class
