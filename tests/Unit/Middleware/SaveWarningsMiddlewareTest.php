<?php

/**
 * SaveWarningsMiddleware: a successful save's warnings on its response.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
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

namespace OCA\OpenRegister\Tests\Unit\Middleware;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Middleware\SaveWarningsMiddleware;
use OCA\OpenRegister\Service\Rules\SaveWarnings;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Middleware\SaveWarningsMiddleware
 * @uses \OCA\OpenRegister\Service\Rules\SaveWarnings
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class SaveWarningsMiddlewareTest extends TestCase {

	private const WARNING = ['validation' => 'mandaat', 'message' => 'Boven 50.000 euro is een mandaat verplicht', 'properties' => ['mandaat']];

	public function testASavedObjectCarriesItsWarningsInSelfAndTheHeader(): void {
		$warnings = new SaveWarnings();
		$warnings->add(object: 'uuid-1', warning: self::WARNING);
		$response = new JSONResponse(data: ['bedrag' => 60000, '@self' => ['id' => 'uuid-1']], statusCode: 201);

		$result = (new SaveWarningsMiddleware(warnings: $warnings))->afterController(new stdClass(), 'create', $response);

		$expected = [array_merge(self::WARNING, ['object' => 'uuid-1'])];
		$this->assertSame(['bedrag' => 60000, '@self' => ['id' => 'uuid-1', 'warnings' => $expected]], $result->getData());
		$this->assertSame($expected, json_decode($result->getHeaders()[SaveWarningsMiddleware::HEADER], true));
		$this->assertSame([], $warnings->drain());
	}//end testASavedObjectCarriesItsWarningsInSelfAndTheHeader()

	public function testAResponseWithoutWarningsIsUntouched(): void {
		$response = new JSONResponse(data: ['@self' => ['id' => 'uuid-1']]);

		$result = (new SaveWarningsMiddleware(warnings: new SaveWarnings()))->afterController(new stdClass(), 'update', $response);

		$this->assertSame(['@self' => ['id' => 'uuid-1']], $result->getData());
		$this->assertArrayNotHasKey(SaveWarningsMiddleware::HEADER, $result->getHeaders());
	}//end testAResponseWithoutWarningsIsUntouched()

	public function testAFailedResponseOnlyClearsTheCollector(): void {
		$warnings = new SaveWarnings();
		$warnings->add(object: 'uuid-1', warning: self::WARNING);
		$response = new JSONResponse(data: ['message' => 'refused'], statusCode: 422);

		$result = (new SaveWarningsMiddleware(warnings: $warnings))->afterController(new stdClass(), 'create', $response);

		$this->assertSame(['message' => 'refused'], $result->getData());
		$this->assertArrayNotHasKey(SaveWarningsMiddleware::HEADER, $result->getHeaders());
		$this->assertSame([], $warnings->drain());
	}//end testAFailedResponseOnlyClearsTheCollector()

	public function testAListBodyGetsTheHeaderOnly(): void {
		$warnings = new SaveWarnings();
		$warnings->add(object: 'a', warning: self::WARNING);
		$warnings->add(object: 'b', warning: self::WARNING);
		$response = new JSONResponse(data: ['results' => [['@self' => ['id' => 'a']]], 'total' => 1]);

		$result = (new SaveWarningsMiddleware(warnings: $warnings))->afterController(new stdClass(), 'import', $response);

		$this->assertSame(['results' => [['@self' => ['id' => 'a']]], 'total' => 1], $result->getData());
		$header = json_decode($result->getHeaders()[SaveWarningsMiddleware::HEADER], true);
		$this->assertSame(['a', 'b'], array_column($header, 'object'));
	}//end testAListBodyGetsTheHeaderOnly()

	public function testANonJsonResponseGetsTheHeader(): void {
		$warnings = new SaveWarnings();
		$warnings->add(object: 'a', warning: self::WARNING);
		$response = new DataResponse(data: ['@self' => ['id' => 'a']]);

		$result = (new SaveWarningsMiddleware(warnings: $warnings))->afterController(new stdClass(), 'create', $response);

		$this->assertSame(['@self' => ['id' => 'a']], $result->getData());
		$this->assertSame('a', json_decode($result->getHeaders()[SaveWarningsMiddleware::HEADER], true)[0]['object']);
	}//end testANonJsonResponseGetsTheHeader()
}//end class
