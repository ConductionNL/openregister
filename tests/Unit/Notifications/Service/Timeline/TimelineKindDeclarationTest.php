<?php

/**
 * TimelineKindDeclaration: reading a timeline kind declaration and writing it onto the entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Db\TimelineKind;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindDeclaration;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindDeclaration
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException
 * @uses   \OCA\OpenRegister\Notifications\Db\TimelineKind
 * @uses   \OCA\OpenRegister\Notifications\Db\AdministeredDeclaration
 */
class TimelineKindDeclarationTest extends TestCase {

	public function testTheSlugIsTrimmedAndLowercased(): void {
		$this->assertSame('phone-call', (new TimelineKindDeclaration(['slug' => '  Phone-Call ']))->slug());
	}

	public function testAMissingBlankOrNonStringSlugIsRefused(): void {
		foreach ([[], ['slug' => '   '], ['slug' => 42]] as $data) {
			try {
				new TimelineKindDeclaration($data);
				$this->fail('A declaration without a usable slug was accepted.');
			} catch (TimelineValidationException $e) {
				$this->assertSame(['slug' => 'A kind needs a slug'], $e->getErrors());
			}
		}
	}

	public function testNonArrayPropertiesAreRefused(): void {
		try {
			new TimelineKindDeclaration(['slug' => 'call', 'properties' => 'duration']);
			$this->fail('Scalar properties were accepted.');
		} catch (TimelineValidationException $e) {
			$this->assertSame(
				['properties' => 'Properties must be a map of property names to declarations'],
				$e->getErrors()
			);
		}
	}

	public function testWriteOntoCopiesTheDeclaration(): void {
		$declaration = new TimelineKindDeclaration([
			'slug' => 'call',
			'title' => '  Phone call ',
			'description' => 'A call with the customer',
			'properties' => ['duration' => ['type' => 'integer']],
			'required' => ['duration', 7, 'notes'],
			'followUp' => 1,
			'register' => 'crm',
			'schema' => 'contact',
		]);
		$kind = new TimelineKind();

		$this->assertSame($kind, $declaration->writeOnto($kind));
		$this->assertSame('Phone call', $kind->getTitle());
		$this->assertSame('A call with the customer', $kind->getDescription());
		$this->assertSame(['duration' => ['type' => 'integer']], $kind->getProperties());
		$this->assertSame(['duration', 'notes'], $kind->getRequired());
		$this->assertTrue($kind->getFollowUp());
		$this->assertSame('crm', $kind->getRegister());
		$this->assertSame('contact', $kind->getSchema());
		$this->assertNotNull($kind->getUpdated());
	}

	public function testOptionalFieldsFallBackToEmptyValues(): void {
		$kind = (new TimelineKindDeclaration([
			'slug' => 'note',
			'title' => '   ',
			'description' => ['not', 'a', 'string'],
			'required' => 'duration',
		]))->writeOnto(new TimelineKind());

		$this->assertNull($kind->getTitle());
		$this->assertNull($kind->getDescription());
		$this->assertSame([], $kind->getProperties());
		$this->assertSame([], $kind->getRequired());
		$this->assertFalse($kind->getFollowUp());
		$this->assertNull($kind->getRegister());
		$this->assertNull($kind->getSchema());
	}
}
