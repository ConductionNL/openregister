<?php

/**
 * SchemaChangeHeaderMiddleware: a recent breaking change named on the schema's object responses.
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
use DateTime;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Middleware\SchemaChangeHeaderMiddleware;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\SchemaChangeHeaderMiddleware
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaChangelog
 */
final class SchemaChangeHeaderMiddlewareTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private SchemaChangelogMapperInterface&MockObject $changelog;

	private ObjectsController&MockObject $objects;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->changelog = $this->createMock(SchemaChangelogMapperInterface::class);
		$this->objects = $this->createMock(ObjectsController::class);
	}//end setUp()

	private function middleware(string $schema = 'melding'): SchemaChangeHeaderMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('schema', '')->willReturn($schema);
		return new SchemaChangeHeaderMiddleware($request, $this->schemas, $this->changelog);
	}//end middleware()

	private function brokeYesterday(): SchemaChangelog {
		$entry = new SchemaChangelog();
		$entry->setSchemaId(12);
		$entry->setVersion('2.0.0');
		$entry->setCreated(new DateTime('2026-11-12T09:30:00+00:00'));
		return $entry;
	}//end brokeYesterday()

	public function testAListOfManyObjectsDoesOneLookupAndCarriesTheHeaders(): void {
		$schema = new Schema();
		$schema->setId(12);
		$this->schemas->expects($this->once())->method('find')->with('melding', [], false, false)->willReturn($schema);
		$this->changelog->expects($this->once())->method('findLatestBreakingSince')
			->with(12, $this->callback(static fn (DateTime $since): bool => abs($since->getTimestamp() - strtotime('-30 days')) < 60))
			->willReturn($this->brokeYesterday());
		$middleware = $this->middleware();

		for ($i = 0; $i < 500; $i++) {
			$response = $middleware->afterController($this->objects, ($i === 0 ? 'index' : 'show'), new JSONResponse(['results' => []]));
		}

		$headers = $response->getHeaders();
		$this->assertSame('version="2.0.0", at="2026-11-12T09:30:00+00:00", breaking', $headers['OpenRegister-Schema-Changed']);
		$this->assertSame('</index.php/apps/openregister/api/schemas/12/changelog>; rel="describedby"', $headers['Link']);
	}//end testAListOfManyObjectsDoesOneLookupAndCarriesTheHeaders()

	public function testWithoutARecentBreakingChangeThereIsNoHeader(): void {
		$schema = new Schema();
		$schema->setId(12);
		$this->schemas->method('find')->willReturn($schema);
		$this->changelog->method('findLatestBreakingSince')->willReturn(null);

		$response = $this->middleware()->afterController($this->objects, 'index', new JSONResponse([]));

		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $response->getHeaders());
	}//end testWithoutARecentBreakingChangeThereIsNoHeader()

	public function testOtherRoutesFailuresAndUnresolvableSchemasAreLeftAlone(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('not found'));
		$this->changelog->expects($this->never())->method('findLatestBreakingSince');

		$schemas = $this->createMock(SchemasController::class);
		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $this->middleware()->afterController($schemas, 'index', new JSONResponse([]))->getHeaders());
		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $this->middleware()->afterController($this->objects, 'create', new JSONResponse([]))->getHeaders());
		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $this->middleware()->afterController($this->objects, 'index', new JSONResponse([], 404))->getHeaders());
		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $this->middleware()->afterController($this->objects, 'index', new JSONResponse([]))->getHeaders());
		$this->assertArrayNotHasKey('OpenRegister-Schema-Changed', $this->middleware('')->afterController($this->objects, 'index', new JSONResponse([]))->getHeaders());
	}//end testOtherRoutesFailuresAndUnresolvableSchemasAreLeftAlone()
}//end class
