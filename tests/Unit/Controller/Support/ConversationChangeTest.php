<?php

/**
 * Unit test for ConversationChange.
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

use OCA\OpenRegister\Controller\Support\ConversationChange;
use PHPUnit\Framework\TestCase;

/**
 * The failure and denial texts of a change to a conversation.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ConversationChange
 * @uses   \OCA\OpenRegister\Controller\Support\ConversationFailure
 */
class ConversationChangeTest extends TestCase {
	public function testTheFailureCarriesUuidAndError(): void {
		$failure = (new ConversationChange('c-1', 'archive', 'Failed to archive conversation'))->failure();

		$this->assertSame('c-1', $failure->uuid);
		$this->assertSame('Failed to archive conversation', $failure->error);
		$this->assertSame('Failed to archive conversation', $failure->logMessage());
	}

	public function testTheDeniedMessageNamesTheVerb(): void {
		$this->assertSame(
			'You do not have permission to delete this conversation',
			(new ConversationChange('c-1', 'delete', 'x'))->deniedMessage()
		);
	}
}
