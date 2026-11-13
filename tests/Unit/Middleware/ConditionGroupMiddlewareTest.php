<?php

/**
 * ConditionGroupMiddleware: a refused `_where` tree answers 400 with its
 * error and node path; every other exception passes through untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Exception\ConditionGroupException;
use OCA\OpenRegister\Middleware\ConditionGroupMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\ConditionGroupMiddleware
 * @uses   \OCA\OpenRegister\Exception\ConditionGroupException
 */
class ConditionGroupMiddlewareTest extends TestCase {

	public function testARefusedTreeAnswers400WithItsPath(): void {
		$response = (new ConditionGroupMiddleware())->afterException(
			$this->createMock(Controller::class),
			'index',
			new ConditionGroupException(message: 'The schema does not declare the property nope', path: '$.children[1]')
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'The schema does not declare the property nope', 'path' => '$.children[1]'], $response->getData());
	}

	public function testAnyOtherExceptionPassesThrough(): void {
		$other = new RuntimeException('db down');
		$this->expectExceptionObject($other);

		(new ConditionGroupMiddleware())->afterException($this->createMock(Controller::class), 'index', $other);
	}
}
