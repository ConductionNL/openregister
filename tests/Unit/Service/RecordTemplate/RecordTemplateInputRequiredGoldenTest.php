<?php

/**
 * Golden test for the required-field findings of RecordTemplateInput::validated().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RecordTemplate;

use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateInput;
use PHPUnit\Framework\TestCase;

/**
 * Required fields in a full write and in a patch, pinned.
 */
class RecordTemplateInputRequiredGoldenTest extends TestCase {

	/**
	 * The findings a refused write carries, or the fields an accepted one returns.
	 *
	 * @param array<string, mixed> $data    The request body.
	 * @param bool                 $partial Whether it is a patch.
	 *
	 * @return array<mixed> The findings or the fields.
	 */
	private static function answer(array $data, bool $partial): array {
		try {
			return (new RecordTemplateInput(shareFindings: static fn (mixed $shares): array => []))->validated(data: $data, partial: $partial);
		} catch (RecordTemplateRefused $e) {
			return $e->getFindings();
		}
	}//end answer()

	public function testRequiredFieldsAreJudgedInOrderAndTrimmed(): void {
		$this->assertSame(
			[
				['code' => 'name.required', 'message' => 'name is required.'],
				['code' => 'schema.required', 'message' => 'schema is required.'],
			],
			self::answer(data: ['name' => '  ', 'register' => ' r ', 'schema' => ['x']], partial: false)
		);
		$this->assertSame(
			[['code' => 'register.required', 'message' => 'register is required.']],
			self::answer(data: ['register' => ' ', 'description' => 'd'], partial: true)
		);
		$this->assertSame(['name' => 'N', 'schema' => '7'], self::answer(data: ['name' => ' N ', 'schema' => 7], partial: true));
		$this->assertSame(['name' => 'N', 'register' => 'r', 'schema' => 's'], self::answer(data: ['name' => 'N', 'register' => ' r', 'schema' => 's '], partial: false));
	}//end testRequiredFieldsAreJudgedInOrderAndTrimmed()
}//end class
