<?php

/**
 * A record template read back from its row answers the shape the API promises.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Db\RecordTemplateMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RecordTemplate
 * @covers \OCA\OpenRegister\Db\RecordTemplateMapper
 */
class RecordTemplateTest extends TestCase {

	/**
	 * The row's `template_values` and `shared_with` come back as the API's `values` and `sharedWith`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testARowReadsBackAsTheApiShape(): void {
		$template = RecordTemplate::fromRow(
			[
				'id' => 7,
				'uuid' => 'tpl-7',
				'name' => 'Melding wateroverlast',
				'description' => null,
				'register' => 'zaken',
				'schema' => 'meldingen',
				'template_values' => '{"categorie":"water"}',
				'owner' => 'anna',
				'is_public' => false,
				'shared_with' => '[{"group":"kcc","mode":"read"}]',
				'created' => null,
				'updated' => null,
			]
		);

		$json = $template->jsonSerialize();
		self::assertSame(['categorie' => 'water'], $json['values']);
		self::assertSame([['group' => 'kcc', 'mode' => 'read']], $json['sharedWith']);
		self::assertSame('meldingen', $json['schema']);
		self::assertNull($json['@self']['access'], 'No access is guessed before somebody resolves it.');

		$template->setAccess('owner');
		self::assertSame('owner', $template->jsonSerialize()['@self']['access']);
	}//end testARowReadsBackAsTheApiShape()

	/**
	 * Values and shares that were never set answer empty lists, not null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testUnsetValuesAndSharesAreEmpty(): void {
		$template = new RecordTemplate();
		$template->setValues([]);
		$template->setSharedWith([]);

		self::assertSame([], $template->getValues());
		self::assertSame([], $template->getSharedWith());
		self::assertArrayHasKey('templateValues', $template->getUpdatedFields());
	}//end testUnsetValuesAndSharesAreEmpty()

	/**
	 * The mapper stores in its own table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testTheMapperOwnsItsTable(): void {
		$mapper = new RecordTemplateMapper(db: $this->createMock(IDBConnection::class));

		self::assertSame('openregister_record_templates', $mapper->getTableName());
	}//end testTheMapperOwnsItsTable()
}//end class
