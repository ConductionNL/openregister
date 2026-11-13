<?php

/**
 * BreakingSchemaChangeException: the 409 body carries who would be affected.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Exception\BreakingSchemaChangeException
 */
final class BreakingSchemaChangeExceptionReachTest extends TestCase {

	public function testTheReachFollowsTheExistingKeys(): void {
		$reach = ['affectedCallers' => ['recorded' => true, 'count' => 2, 'top' => []], 'followers' => 1];

		$body = (new BreakingSchemaChangeException([['property' => 'toelichting', 'kind' => 'removed']], 3))->withReach($reach)->toResponse();

		$this->assertSame(['error', 'classification', 'changes', 'invalidCount', 'affectedCallers', 'followers'], array_keys($body));
		$this->assertSame(2, $body['affectedCallers']['count']);
		$this->assertSame(1, $body['followers']);
	}//end testTheReachFollowsTheExistingKeys()

	public function testWithoutAReachTheBodyIsUnchanged(): void {
		$body = (new BreakingSchemaChangeException([]))->toResponse();

		$this->assertSame(['error' => 'Schema change classified breaking; acknowledgeBreaking required.', 'classification' => 'breaking', 'changes' => []], $body);
	}//end testWithoutAReachTheBodyIsUnchanged()
}//end class
