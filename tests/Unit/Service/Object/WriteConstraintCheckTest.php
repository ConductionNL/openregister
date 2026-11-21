<?php

/**
 * Unit tests for WriteConstraintCheck — readOnly and immutable property violations.
 *
 * A readOnly property may not change at all; an immutable one may be set once (from null or empty) and not changed after. Sending the same value, or not sending it, is never a violation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Object\WriteConstraintCheck;
use PHPUnit\Framework\TestCase;

final class WriteConstraintCheckTest extends TestCase {

	public function testAChangedReadOnlyPropertyIsAViolation(): void {
		$check = new WriteConstraintCheck(['code' => 'B', 'same' => 1, 'new' => 'x'], ['code' => 'A', 'same' => 1]);

		$this->assertSame(
			[
				['property' => 'code', 'attempted' => 'B', 'stored' => 'A', 'message' => "Property 'code' is declared readOnly and cannot be modified after creation."],
				['property' => 'new', 'attempted' => 'x', 'stored' => null, 'message' => "Property 'new' is declared readOnly and cannot be modified after creation."],
			],
			$check->readOnlyViolations(['code', 'same', 'new', 'absent'])
		);
	}

	public function testAReadOnlyCompareIsStrict(): void {
		$check = new WriteConstraintCheck(['n' => '1'], ['n' => 1]);

		$this->assertCount(1, $check->readOnlyViolations(['n']));
	}

	public function testAnImmutablePropertyMayBeSetOnceButNotChanged(): void {
		$check = new WriteConstraintCheck(
			['bsn' => '222', 'fromNull' => 'a', 'fromEmpty' => 'b', 'kept' => 'k'],
			['bsn' => '111', 'fromNull' => null, 'fromEmpty' => '', 'kept' => 'k']
		);

		$this->assertSame(
			[['property' => 'bsn', 'attempted' => '222', 'stored' => '111', 'message' => "Property 'bsn' is immutable once set and cannot be changed."]],
			$check->immutableViolations(['bsn', 'fromNull', 'fromEmpty', 'kept', 'notSent'])
		);
	}

	public function testNoNamesMeansNoViolations(): void {
		$check = new WriteConstraintCheck(['a' => 1], ['a' => 2]);

		$this->assertSame([], $check->readOnlyViolations([]));
		$this->assertSame([], $check->immutableViolations([]));
	}
}
