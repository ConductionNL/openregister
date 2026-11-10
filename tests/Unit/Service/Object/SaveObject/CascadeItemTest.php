<?php

/**
 * Unit tests for recognising object identifiers and the register a cascaded item is saved in.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\CascadeItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Uuids, 32-hex ids, prefixed ids and numeric ids are identifiers; prose is not.
 * A property's own register wins over the parent's, read from its id when it is an entity array.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\CascadeItem
 */
class CascadeItemTest extends TestCase {

	/**
	 * Values and whether they are identifiers.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function values(): array {
		return [
			'uuid'          => ['3f2b8c1e-9d4a-4e6b-8f7c-1a2b3c4d5e6f', true],
			'32 hex'        => ['3f2b8c1e9d4a4e6b8f7c1a2b3c4d5e6f', true],
			'prefixed uuid' => ['zaak-3f2b8c1e-9d4a-4e6b-8f7c-1a2b3c4d5e6f', true],
			'numeric'       => ['42', true],
			'slug'          => ['my-object', false],
			'prose'         => ['Jansen en zoon', false],
			'31 hex'        => ['3f2b8c1e9d4a4e6b8f7c1a2b3c4d5e6', false],
		];

	}//end values()

	/**
	 * Only identifier-shaped values count as identifiers.
	 *
	 * @param string $value    The value.
	 * @param bool   $expected Whether it is an identifier.
	 *
	 * @return void
	 */
	#[DataProvider('values')]
	public function testRecognisesIdentifiers(string $value, bool $expected): void {
		$this->assertSame($expected, CascadeItem::isObjectIdentifier($value));

	}//end testRecognisesIdentifiers()

	/**
	 * The register comes from the property, from its id when it is an entity array, else from the parent.
	 *
	 * @return void
	 */
	public function testTheRegisterOfACascadedItem(): void {
		$this->assertSame(7, CascadeItem::register(['register' => ['id' => 7, 'slug' => 'crm']], 3));
		$this->assertSame(['slug' => 'crm'], CascadeItem::register(['register' => ['slug' => 'crm']], 3));
		$this->assertSame('crm', CascadeItem::register(['register' => 'crm'], 3));
		$this->assertSame(3, CascadeItem::register([], 3));

	}//end testTheRegisterOfACascadedItem()
}//end class
