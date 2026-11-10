<?php

/**
 * Golden test for ContactLink serialisation: metadata decoding, kind,
 * plain validity dates and the primary-party flag.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the serialised form of a contact link.
 */
class ContactLinkJsonGoldenTest extends TestCase {

	/**
	 * Stored metadata and what it serialises to.
	 *
	 * @return array<string, array{string|null, array|null}>
	 */
	public static function metadataCases(): array {
		return [
			'object' => ['{"a":1}', ['a' => 1]],
			'list' => ['[1,2]', [1, 2]],
			'scalar' => ['"x"', null],
			'empty' => ['', null],
			'null' => [null, null],
			'corrupt' => ['{broken', null],
		];
	}//end metadataCases()

	/**
	 * Metadata is decoded, and anything but an array is null.
	 *
	 * @param string|null $stored   The stored text.
	 * @param array|null  $expected The serialised value.
	 *
	 * @return void
	 */
	#[DataProvider('metadataCases')]
	public function testMetadata(?string $stored, ?array $expected): void {
		$link = new ContactLink();
		$link->setMetadata($stored);
		$this->assertSame($expected, $link->jsonSerialize()['metadata']);
	}//end testMetadata()

	/**
	 * Kind, active, dates and the primary-party flag, with the key order.
	 *
	 * @return void
	 */
	public function testDerivedFields(): void {
		$link = new ContactLink();
		$this->assertSame('contact', $link->jsonSerialize()['kind']);
		$this->assertTrue($link->jsonSerialize()['active']);
		$this->assertFalse($link->jsonSerialize()['primaryParty']);

		$link->setUserId('u1');
		$this->assertSame('user', $link->jsonSerialize()['kind']);
		$link->setPartyUuid('p1');
		$link->setPrimaryParty(true);
		$link->setValidFrom(new DateTime('2020-03-04T10:00:00+00:00'));
		$link->setValidUntil(new DateTime('2020-05-06T10:00:00+00:00'));
		$json = $link->jsonSerialize();
		$this->assertSame('party', $json['kind']);
		$this->assertFalse($json['active']);
		$this->assertTrue($json['primaryParty']);
		$this->assertSame('2020-03-04', $json['validFrom']);
		$this->assertSame('2020-05-06', $json['validUntil']);
		$this->assertSame(
			['id', 'objectUuid', 'registerId', 'schemaId', 'contactUid', 'addressbookId', 'contactUri', 'displayName', 'email', 'phone', 'org', 'avatarUrl', 'role', 'metadata', 'linkedBy', 'linkedAt', 'kind', 'userId', 'validFrom', 'validUntil', 'note', 'active', 'partyUuid', 'partyKind', 'primaryParty'],
			array_keys($json)
		);
	}//end testDerivedFields()
}//end class
