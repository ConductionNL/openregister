<?php

/**
 * Unit test for GitHubTokenFailure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use Exception;
use OCA\OpenRegister\Controller\Support\GitHubTokenFailure;
use PHPUnit\Framework\TestCase;

/**
 * The answer a failed token operation gives the caller.
 *
 * @covers \OCA\OpenRegister\Controller\Support\GitHubTokenFailure
 */
class GitHubTokenFailureTest extends TestCase {
	public function testTheAnswerHidesTheReasonByDefault(): void {
		$failure = new GitHubTokenFailure('Token save failed', 'Could not save the token');

		$this->assertSame('Could not save the token', $failure->answerFor(new Exception('db detail')));
		$this->assertSame('Token save failed', $failure->logged);
		$this->assertFalse($failure->appendReason);
	}

	public function testTheReasonIsAppendedWhenAsked(): void {
		$failure = new GitHubTokenFailure('x', 'Token check failed', true);

		$this->assertSame('Token check failed: bad credentials', $failure->answerFor(new Exception('bad credentials')));
	}
}
