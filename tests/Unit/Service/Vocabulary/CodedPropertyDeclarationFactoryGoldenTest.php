<?php

/**
 * Golden test for every field CodedPropertyDeclarationFactory::fromProperty() reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use PHPUnit\Framework\TestCase;

/**
 * Every declaration field, pinned.
 */
class CodedPropertyDeclarationFactoryGoldenTest extends TestCase {

	/**
	 * The fields of the declaration read off one annotation, or null.
	 *
	 * @param mixed $annotation The `x-openregister-concepts` value.
	 *
	 * @return array<string, mixed>|null The fields.
	 */
	private static function fields(mixed $annotation): ?array {
		$declaration = (new CodedPropertyDeclarationFactory())->fromProperty(
			['type' => 'string', CodedPropertyDeclaration::ANNOTATION => $annotation]
		);
		if ($declaration === null) {
			return null;
		}

		return [
			'scheme' => $declaration->scheme,
			'store' => $declaration->store,
			'allowDeprecated' => $declaration->allowDeprecated,
			'leafOnly' => $declaration->leafOnly,
			'branch' => $declaration->branch,
			'maxDepth' => $declaration->maxDepth,
			'contextProperty' => $declaration->contextProperty,
			'contextKey' => $declaration->contextKey,
			'scoreProperty' => $declaration->scoreProperty,
		];
	}//end fields()

	public function testAFullAnnotationReadsEveryField(): void {
		$this->assertSame(
			[
				'scheme' => 'zaaktypen',
				'store' => 'notation',
				'allowDeprecated' => true,
				'leafOnly' => true,
				'branch' => 'b-1',
				'maxDepth' => 3,
				'contextProperty' => 'domain',
				'contextKey' => 'dk',
				'scoreProperty' => 'score',
			],
			self::fields(
				[
					'scheme' => ' zaaktypen ',
					'store' => ' notation ',
					'allowDeprecated' => true,
					'leafOnly' => true,
					'branch' => ' b-1 ',
					'maxDepth' => '3',
					'contextProperty' => 'domain',
					'contextKey' => 'dk',
					'score' => ['property' => ' score '],
				]
			)
		);
	}//end testAFullAnnotationReadsEveryField()

	public function testDefaultsAndLooseValues(): void {
		$this->assertSame(
			[
				'scheme' => 's',
				'store' => 'uri',
				'allowDeprecated' => false,
				'leafOnly' => false,
				'branch' => null,
				'maxDepth' => null,
				'contextProperty' => null,
				'contextKey' => null,
				'scoreProperty' => 'total',
			],
			self::fields(
				['scheme' => 's', 'store' => 'label', 'allowDeprecated' => 'yes', 'leafOnly' => 1, 'branch' => ' ', 'maxDepth' => 'deep', 'score' => 'total']
			)
		);
		$this->assertNull(self::fields(['scheme' => '  ']));
		$this->assertNull(self::fields(['store' => 'notation']));
	}//end testDefaultsAndLooseValues()
}//end class
