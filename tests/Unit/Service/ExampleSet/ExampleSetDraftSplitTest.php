<?php

/**
 * ExampleSetDraft::split takes an object's `@self` draft declaration off its
 * data and answers the scoped draft owner it names, or why it cannot be written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetDraft;
use OCA\OpenRegister\Service\Object\DraftOwner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDraft
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 */
class ExampleSetDraftSplitTest extends TestCase {

	public function testDataWithoutSelfIsKeptWholeWithNoOwner(): void {
		$split = (new ExampleSetDraft())->split(['title' => 'Aanvraag', 'amount' => 3]);

		$this->assertSame(['data' => ['title' => 'Aanvraag', 'amount' => 3], 'owner' => null, 'refusal' => ''], $split);
	}

	public function testAScopedDraftDeclarationNamesItsOwnerAndLeavesTheData(): void {
		$split = (new ExampleSetDraft())->split(['title' => 'Aanvraag', '@self' => ['status' => 'draft', 'owner' => 'portal:subject-42']]);

		$this->assertSame(['title' => 'Aanvraag'], $split['data']);
		$this->assertSame('', $split['refusal']);
		$this->assertInstanceOf(DraftOwner::class, $split['owner']);
		$this->assertSame('portal:subject-42', $split['owner']->value());
		$this->assertTrue($split['owner']->isScoped());
	}

	public function testOnlyTheFirstColonSplitsScopeFromReference(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['owner' => 'kvk:12:34', 'status' => 'draft']]);

		$this->assertSame([], $split['data']);
		$this->assertSame('', $split['refusal']);
		$this->assertSame('kvk:12:34', $split['owner']->value());
	}

	public function testASelfThatIsNotAMapIsRefused(): void {
		$split = (new ExampleSetDraft())->split(['title' => 'X', '@self' => 'draft']);

		$this->assertSame(['data' => ['title' => 'X'], 'owner' => null, 'refusal' => '@self may declare status and owner only'], $split);
	}

	public function testASelfWithAnotherFieldIsRefused(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['status' => 'draft', 'owner' => 'portal:a', 'register' => 5]]);

		$this->assertSame(['data' => [], 'owner' => null, 'refusal' => '@self may declare status and owner only'], $split);
	}

	public function testAStatusOtherThanDraftIsRefused(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['status' => 'published', 'owner' => 'portal:a']]);

		$this->assertSame(['data' => [], 'owner' => null, 'refusal' => '@self status may only be "draft"'], $split);
	}

	public function testAMissingStatusIsRefused(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['owner' => 'portal:a']]);

		$this->assertSame('@self status may only be "draft"', $split['refusal']);
		$this->assertNull($split['owner']);
	}

	public function testAnOwnerWithoutAScopeIsRefused(): void {
		$split = (new ExampleSetDraft())->split(['n' => 1, '@self' => ['status' => 'draft', 'owner' => 'alice']]);

		$this->assertSame(['data' => ['n' => 1], 'owner' => null, 'refusal' => 'a draft owner is "<scope>:<reference>", not "alice"'], $split);
	}

	public function testAMissingOwnerIsRefusedAsEmpty(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['status' => 'draft']]);

		$this->assertSame('a draft owner is "<scope>:<reference>", not ""', $split['refusal']);
	}

	public function testAnInvalidScopeIsRefusedWithTheOwnersReason(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['status' => 'draft', 'owner' => 'Portal:abc']]);

		$this->assertSame(
			['data' => [], 'owner' => null, 'refusal' => 'draft owner "Portal:abc": A draft owner scope is lower case letters, digits and hyphens.'],
			$split
		);
	}

	public function testAnEmptyReferenceIsRefusedWithTheOwnersReason(): void {
		$split = (new ExampleSetDraft())->split(['@self' => ['status' => 'draft', 'owner' => 'portal:  ']]);

		$this->assertSame('draft owner "portal:  ": A draft owner reference is a non-empty value without control characters.', $split['refusal']);
		$this->assertNull($split['owner']);
	}
}
