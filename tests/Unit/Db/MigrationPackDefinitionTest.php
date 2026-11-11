<?php

/**
 * MigrationPack decodes its stored definition and serialises it decoded.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MigrationPack;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MigrationPack
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJsonTrait
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 */
class MigrationPackDefinitionTest extends TestCase {

	/**
	 * A pack with the given stored definition.
	 *
	 * @param string|null $definition The stored definition.
	 *
	 * @return MigrationPack
	 */
	private static function pack(?string $definition): MigrationPack {
		$pack = new MigrationPack();
		$pack->setDefinition($definition);
		return $pack;
	}//end pack()

	/**
	 * Every definition that is not a JSON object or array decodes to an empty array.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function emptyDefinitions(): array {
		return [
			'unset' => [null],
			'empty string' => [''],
			'invalid json' => ['{not json'],
			'json scalar' => ['42'],
			'json string' => ['"text"'],
		];
	}//end emptyDefinitions()

	/**
	 * An unset, empty, invalid or scalar definition decodes to an empty array.
	 *
	 * @param string|null $definition The stored definition.
	 *
	 * @return void
	 *
	 * @dataProvider emptyDefinitions
	 */
	public function testANonDocumentDefinitionDecodesEmpty(?string $definition): void {
		$this->assertSame([], self::pack($definition)->getDefinitionArray());
	}//end testANonDocumentDefinitionDecodesEmpty()

	/**
	 * A stored document decodes to its array.
	 *
	 * @return void
	 */
	public function testADocumentDefinitionDecodes(): void {
		$pack = self::pack('{"mappings":[{"from":"a","to":"b"}]}');

		$this->assertSame(['mappings' => [['from' => 'a', 'to' => 'b']]], $pack->getDefinitionArray());
	}//end testADocumentDefinitionDecodes()

	/**
	 * The serialised pack carries the decoded definition, not the stored string.
	 *
	 * @return void
	 */
	public function testTheSerialisedPackCarriesTheDecodedDefinition(): void {
		$pack = self::pack('{"version":2}');
		$pack->setPackSlug('zaaksysteem');

		$json = $pack->jsonSerialize();

		$this->assertSame(['version' => 2], $json['definition']);
		$this->assertSame('zaaksysteem', $json['packSlug']);
	}//end testTheSerialisedPackCarriesTheDecodedDefinition()
}//end class
