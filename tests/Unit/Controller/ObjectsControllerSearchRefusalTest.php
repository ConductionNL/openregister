<?php

/**
 * Tests that a malformed `_search` term is refused at the HTTP boundary.
 *
 * The refusal has to happen here rather than deeper in. The facet builders
 * below this point catch `\Exception` broadly by design, so a syntax refusal
 * raised in the mapper would be swallowed into an empty facet list and the
 * caller would be handed the "found nothing" that this whole change exists to
 * stop producing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Locks the 400 the objects endpoint returns for an unreadable search term.
 */
class ObjectsControllerSearchRefusalTest extends TestCase {

	/**
	 * Invoke the refusal guard on a controller built without its constructor:
	 * the guard touches no injected dependency, only the request parameters.
	 *
	 * @param array $params The raw request parameters.
	 *
	 * @phpstan-param array<string, mixed> $params
	 *
	 * @psalm-param array<string, mixed> $params
	 *
	 * @return JSONResponse|null The refusal, or null when the term reads.
	 */
	private function refuse(array $params): ?JSONResponse {
		$controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ObjectsController::class, 'refuseMalformedSearchTerm');
		$method->setAccessible(true);

		return $method->invoke($controller, $params);
	}//end refuse()

	/**
	 * The spec scenario: an unbalanced bracket is refused, naming the position,
	 * and holds no results.
	 *
	 * @return void
	 */
	public function testAnUnbalancedBracketIsRefusedWithThePosition(): void {
		$response = $this->refuse(['_search' => 'dakkapel AND (geweigerd']);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertSame(14, $data['position']);
		$this->assertStringContainsString('position 14', $data['error']);
		$this->assertSame('dakkapel AND (geweigerd', $data['term']);
		$this->assertArrayNotHasKey('results', $data);
	}//end testAnUnbalancedBracketIsRefusedWithThePosition()

	/**
	 * A readable boolean term passes the guard untouched.
	 *
	 * @return void
	 */
	public function testAReadableBooleanTermIsNotRefused(): void {
		$this->assertNull($this->refuse(['_search' => 'dakkapel AND NOT geweigerd']));
	}//end testAReadableBooleanTermIsNotRefused()

	/**
	 * A plain term never reaches the parser at all, so the guard cannot start
	 * refusing searches that worked yesterday.
	 *
	 * @return void
	 */
	public function testAPlainTermIsNotRefused(): void {
		$this->assertNull($this->refuse(['_search' => 'dakkapel geweigerd']));
		$this->assertNull($this->refuse(['_search' => '50% korting']));
	}//end testAPlainTermIsNotRefused()

	/**
	 * No search term, an empty one, or a non-string one is not this guard's
	 * business.
	 *
	 * @return void
	 */
	public function testAnAbsentOrEmptyTermIsNotRefused(): void {
		$this->assertNull($this->refuse([]));
		$this->assertNull($this->refuse(['_search' => '   ']));
		$this->assertNull($this->refuse(['_search' => ['array']]));
	}//end testAnAbsentOrEmptyTermIsNotRefused()
}//end class
