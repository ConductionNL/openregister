<?php

/**
 * The checks a record template's fields pass, and how they land on the entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RecordTemplate
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

namespace OCA\OpenRegister\Tests\Unit\Service\RecordTemplate;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateInput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\RecordTemplate\RecordTemplateInput
 */
class RecordTemplateInputTest extends TestCase {

	/**
	 * A full write needs a name, a register and a schema; a PATCH only checks what it sends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testAFullWriteNeedsItsThreeFieldsAndAPatchDoesNot(): void {
		$input = new RecordTemplateInput();
		$none = static fn (mixed $shares): array => [];

		try {
			$input->validated(data: ['description' => 'x'], partial: false, shareFindings: $none);
			self::fail('A full write without its required fields must be refused.');
		} catch (RecordTemplateRefused $e) {
			self::assertSame(['name.required', 'register.required', 'schema.required'], array_column($e->getFindings(), 'code'));
		}

		self::assertSame(['description' => 'x'], $input->validated(data: ['description' => 'x', 'owner' => 'me'], partial: true, shareFindings: $none));
	}//end testAFullWriteNeedsItsThreeFieldsAndAPatchDoesNot()

	/**
	 * A list is not values, an empty object is, a long name and a non-boolean flag are refused, and share findings are kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testShapeFindings(): void {
		$input = new RecordTemplateInput();
		$share = static fn (mixed $shares): array => [['code' => 'share.unknown-group', 'message' => 'no']];

		try {
			$input->validated(
				data: ['name' => str_repeat('a', 256), 'register' => 1, 'schema' => 's', 'values' => [1, 2], 'isPublic' => 1, 'sharedWith' => [['group' => 'x']]],
				partial: false,
				shareFindings: $share
			);
			self::fail('Bad shapes must be refused.');
		} catch (RecordTemplateRefused $e) {
			self::assertSame(400, $e->getCode());
			self::assertSame(['name.too-long', 'values.not-an-object', 'isPublic.not-a-boolean', 'share.unknown-group'], array_column($e->getFindings(), 'code'));
		}

		$ok = $input->validated(data: ['name' => ' Kort ', 'register' => 1, 'schema' => 's', 'values' => []], partial: false, shareFindings: static fn (mixed $s): array => []);
		self::assertSame(['name' => 'Kort', 'register' => '1', 'schema' => 's', 'values' => []], $ok);
	}//end testShapeFindings()

	/**
	 * Only the fields sent land on the entity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testOnlyTheFieldsSentLand(): void {
		$template = new RecordTemplate();
		$template->setName('Oud');
		$template->setValues(['a' => 1]);

		(new RecordTemplateInput())->apply(template: $template, fields: ['description' => ' Nieuw ', 'isPublic' => true]);

		self::assertSame('Oud', $template->getName());
		self::assertSame(['a' => 1], $template->getValues());
		self::assertSame('Nieuw', $template->getDescription());
		self::assertTrue($template->getIsPublic());
	}//end testOnlyTheFieldsSentLand()
}//end class
