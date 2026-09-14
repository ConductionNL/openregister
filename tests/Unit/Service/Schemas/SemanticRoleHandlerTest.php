<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Schemas\SemanticRoleHandler}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use PHPUnit\Framework\TestCase;

class SemanticRoleHandlerTest extends TestCase {

	private SemanticRoleHandler $handler;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler = new SemanticRoleHandler();
	}//end setUp()

	/**
	 * One list component serves an unknown schema: the roles come back with
	 * the properties that hold them.
	 *
	 * @return void
	 */
	public function testTheRolesComeBackWithThePropertiesThatHoldThem(): void {
		$properties = [
			'onderwerp' => ['type' => 'string', 'x-openregister-role' => 'title'],
			'fase' => ['type' => 'string', 'x-openregister-role' => 'status'],
			'toelichting' => ['type' => 'string'],
		];

		$this->assertSame(
			['title' => 'onderwerp', 'status' => 'fase'],
			$this->handler->roles(properties: $properties)
		);
		$this->assertSame([], $this->handler->violations(properties: $properties));
	}//end testTheRolesComeBackWithThePropertiesThatHoldThem()

	/**
	 * Two titles are refused, and the refusal names BOTH properties: "duplicate
	 * role" without the names sends someone reading a forty-property schema
	 * by eye.
	 *
	 * @return void
	 */
	public function testTwoTitlesAreRefusedNamingBoth(): void {
		$errors = $this->handler->violations(
			properties: [
				'onderwerp' => ['x-openregister-role' => 'title'],
				'naam' => ['x-openregister-role' => 'title'],
			]
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('onderwerp', $errors[0]);
		$this->assertStringContainsString('naam', $errors[0]);
		$this->assertStringContainsString('title', $errors[0]);
	}//end testTwoTitlesAreRefusedNamingBoth()

	/**
	 * The role list is closed, so an invented role is refused rather than
	 * silently ignored: two apps inventing two names for one meaning is the
	 * situation a semantic role exists to end.
	 *
	 * @return void
	 */
	public function testAnInventedRoleIsRefused(): void {
		$errors = $this->handler->violations(
			properties: ['veld' => ['x-openregister-role' => 'subtitle']]
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('subtitle', $errors[0]);
		$this->assertSame([], $this->handler->roles(properties: ['veld' => ['x-openregister-role' => 'subtitle']]));
	}//end testAnInventedRoleIsRefused()

	/**
	 * Help text is read in the caller's language.
	 *
	 * @return void
	 */
	public function testHelpTextIsReadInTheCallersLanguage(): void {
		$properties = [
			'archiefnominatie' => [
				'x-openregister-help' => [
					'nl' => 'Bepaalt of dit dossier bewaard of vernietigd wordt.',
					'en' => 'Decides whether this file is kept or destroyed.',
				],
			],
		];

		$this->assertSame(
			['archiefnominatie' => 'Bepaalt of dit dossier bewaard of vernietigd wordt.'],
			$this->handler->helpTexts(properties: $properties, language: 'nl')
		);
		$this->assertSame(
			['archiefnominatie' => 'Decides whether this file is kept or destroyed.'],
			$this->handler->helpTexts(properties: $properties, language: 'en')
		);
	}//end testHelpTextIsReadInTheCallersLanguage()

	/**
	 * Showing help in the wrong language beats showing none, so an unknown
	 * tag falls back rather than answering null.
	 *
	 * @return void
	 */
	public function testAnUnknownLanguageFallsBackRatherThanAnsweringNothing(): void {
		$property = ['x-openregister-help' => ['nl' => 'Uitleg']];

		$this->assertSame('Uitleg', $this->handler->helpTextOf(property: $property, language: 'de'));
		$this->assertSame(
			'Uitleg',
			$this->handler->helpTextOf(property: ['x-openregister-help' => 'Uitleg'], language: 'de'),
			'A plain string is help text in every language.'
		);
		$this->assertNull($this->handler->helpTextOf(property: ['type' => 'string'], language: 'nl'));
	}//end testAnUnknownLanguageFallsBackRatherThanAnsweringNothing()

	/**
	 * A regional tag resolves through its primary subtag, so `nl-NL` reads
	 * the `nl` help text rather than falling through to English.
	 *
	 * @return void
	 */
	public function testARegionalTagResolvesThroughItsPrimarySubtag(): void {
		$property = ['x-openregister-help' => ['nl' => 'Uitleg', 'en' => 'Explanation']];

		$this->assertSame('Uitleg', $this->handler->helpTextOf(property: $property, language: 'nl-NL'));
	}//end testARegionalTagResolvesThroughItsPrimarySubtag()
}//end class
