<?php

/**
 * Unit tests for reading the parts of one declared action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionDeclarationReader;
use PHPUnit\Framework\TestCase;

class ObjectActionDeclarationReaderTest extends TestCase {

	public function testTheIdentityTrimsBlankStringsToNull(): void {
		$identity = ObjectActionDeclarationReader::identity('publish', ['name' => 'Publish', 'description' => ' ', 'icon' => 'Eye']);

		$this->assertSame('publish', $identity->key);
		$this->assertSame('Publish', $identity->label);
		$this->assertNull($identity->description);
		$this->assertSame('Eye', $identity->icon);
	}//end testTheIdentityTrimsBlankStringsToNull()

	public function testTheOfferDefaultsItsSurfacesAndDropsANonArrayInputs(): void {
		$offer = ObjectActionDeclarationReader::offer(['inputs' => 'nope', 'when' => true, 'whenMessage' => 'No.']);

		$this->assertSame(['header', 'row'], $offer->surfaces);
		$this->assertNull($offer->inputs);
		$this->assertTrue($offer->when);
		$this->assertSame('No.', $offer->whenMessage);
	}//end testTheOfferDefaultsItsSurfacesAndDropsANonArrayInputs()

	public function testTheBodyIsTheFirstBodyKeyInKindOrder(): void {
		$body = ObjectActionDeclarationReader::body(['steps' => [], 'flow' => 'f', 'mode' => 'async', 'next' => 'list']);

		$this->assertSame('flow', $body->kind);
		$this->assertSame('f', $body->body);
		$this->assertSame('async', $body->mode);
		$this->assertSame('list', $body->next);
		$this->assertSame(['flow', 'steps'], ObjectActionDeclarationReader::bodiesOf(['steps' => [], 'flow' => 'f']));
	}//end testTheBodyIsTheFirstBodyKeyInKindOrder()

	public function testThePolicyIsReversibleOnlyOnALiteralTrue(): void {
		$elevate = [['register' => 'r', 'schema' => 's', 'verbs' => ['read']]];

		$this->assertTrue(ObjectActionDeclarationReader::policy(['reversible' => true], $elevate)->reversible);
		$this->assertFalse(ObjectActionDeclarationReader::policy(['reversible' => 1], [])->reversible);
		$this->assertSame($elevate, ObjectActionDeclarationReader::policy(['confirm' => 'Sure?'], $elevate)->elevate);
		$this->assertSame('Sure?', ObjectActionDeclarationReader::policy(['confirm' => 'Sure?'], [])->confirm);
	}//end testThePolicyIsReversibleOnlyOnALiteralTrue()

	public function testDeclarationsSkipEntriesWithoutAStringKeyOrAnArrayBody(): void {
		$declared = ObjectActionDeclarationReader::declarations(['x-openregister-action' => ['a' => [], 'b' => 1, 3 => []]]);

		$this->assertSame(['a' => []], $declared);
	}//end testDeclarationsSkipEntriesWithoutAStringKeyOrAnArrayBody()
}//end class
