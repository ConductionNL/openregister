<?php

/**
 * Unit test for LinkedSourceResponses.
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
use JsonSerializable;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use PHPUnit\Framework\TestCase;

/**
 * The replies the linked-source endpoints send.
 *
 * @covers \OCA\OpenRegister\Controller\Support\LinkedSourceResponses
 */
class LinkedSourceResponsesTest extends TestCase {
	public function testCreatedSendsTheSerialisedLinkWith201(): void {
		$link = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 7, 'title' => 'Card'];
			}
		};

		$response = LinkedSourceResponses::created($link);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 7, 'title' => 'Card'], $response->getData());
	}

	public function testUnlinkedReportsSuccess(): void {
		$response = LinkedSourceResponses::unlinked();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	public function testRequiredNamesTheMissingParameterWith400(): void {
		$response = LinkedSourceResponses::required('cardId');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'cardId is required'], $response->getData());
	}

	public function testListedCountsTheItems(): void {
		$this->assertSame(['results' => ['a', 'b'], 'total' => 2], LinkedSourceResponses::listed(['a', 'b'])->getData());
		$this->assertSame(['results' => [], 'total' => 0], LinkedSourceResponses::listed([])->getData());
	}

	public function testAPassThroughCodeKeepsItsStatus(): void {
		$response = LinkedSourceResponses::failure(new Exception('gone', 404), [403, 404]);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'gone'], $response->getData());
	}

	public function testAnyOtherCodeBecomes400(): void {
		$this->assertSame(400, LinkedSourceResponses::failure(new Exception('boom', 500), [404])->getStatus());
		$this->assertSame(400, LinkedSourceResponses::failure(new Exception('boom'), [404])->getStatus());
	}

	public function testSourceUnavailableCarriesTheCauseWith503(): void {
		$response = LinkedSourceResponses::sourceUnavailable('Deck is not installed', ['app' => 'deck']);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'Deck is not installed', 'details' => ['cause' => ['app' => 'deck']]], $response->getData());
	}
}
