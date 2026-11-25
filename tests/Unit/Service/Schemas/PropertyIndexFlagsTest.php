<?php

/**
 * The property vocabulary accepts both index switches of the property editor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-the-property-editor-offers-both-index-kinds
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\TestCase;

/**
 * `indexed` and `searchable` are vocabulary keys; a key beside them that is not still refuses.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
 */
final class PropertyIndexFlagsTest extends TestCase {

	/**
	 * Both switches, on or off, pass the vocabulary.
	 *
	 * @return void
	 */
	public function testBothIndexSwitchesAreAcceptedOnAProperty(): void {
		self::assertTrue(
			condition: (new PropertyValidatorHandler())->validateProperty(property: ['type' => 'string', 'indexed' => true, 'searchable' => true])
		);
		self::assertTrue(
			condition: (new PropertyValidatorHandler())->validateProperty(property: ['type' => 'string', 'indexed' => false, 'searchable' => false])
		);
	}//end testBothIndexSwitchesAreAcceptedOnAProperty()

	/**
	 * Accepting them did not open the vocabulary: a made-up key beside them is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyBesideThemIsStillRefused(): void {
		$this->expectException(exception: PropertyVocabularyException::class);
		$this->expectExceptionMessage(message: "'textIndexed'");

		(new PropertyValidatorHandler())->validateProperty(property: ['type' => 'string', 'searchable' => true, 'textIndexed' => true]);
	}//end testAnUnknownKeyBesideThemIsStillRefused()
}//end class
