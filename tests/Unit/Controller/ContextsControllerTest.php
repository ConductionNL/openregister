<?php

/**
 * Unit tests for ContextsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ContextsController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContextsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ContextsController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $params = []): string {
				$r = ($params['register'] ?? '');
				$s = ($params['schema'] ?? '');
				return 'https://nc.test/api/contexts/' . $r . ($s !== '' ? '/' . $s : '');
			}
		);

		$contextService = new JsonLdContextService($urlGenerator);

		$this->controller = new ContextsController(
			'openregister',
			$this->request,
			$this->registerMapper,
			$this->schemaMapper,
			$contextService
		);
	}

	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('personen');
		$register->setSchemas([1]);
		$register->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));
		return $register;
	}

	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('persoon');
		$schema->setProperties(['name' => ['type' => 'string']]);
		$schema->setUpdated(new DateTime('2026-02-02T00:00:00+00:00'));
		return $schema;
	}

	public function testSchemaContextShape(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister());
		$this->schemaMapper->method('find')->willReturn($this->makeSchema());
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->schema('personen', 'persoon');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('@context', $data);
		$this->assertArrayHasKey('name', $data['@context']);

		// getHeaders() reads \OC::$server; only assert headers under NC.
		if ($this->hasOcServer() === true) {
			$this->assertSame('application/ld+json', $response->getHeaders()['Content-Type']);
			$this->assertArrayHasKey('ETag', $response->getHeaders());
		}
	}

	private function hasOcServer(): bool {
		return class_exists('\OC', false) === true && isset(\OC::$server) === true;
	}

	public function testRegisterContextZeroConfig(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister());
		$this->schemaMapper->method('find')->willReturn($this->makeSchema());
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->register('personen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('@context', $data);
		$this->assertArrayHasKey('name', $data['@context']);
	}

	public function testConditionalGetReturns304(): void {
		if ($this->hasOcServer() === false) {
			$this->markTestSkipped('Reading the ETag header requires the Nextcloud container (\OC::$server).');
		}

		$this->registerMapper->method('find')->willReturn($this->makeRegister());
		$this->schemaMapper->method('find')->willReturn($this->makeSchema());

		// First call to learn the ETag.
		$this->request->method('getHeader')->willReturnCallback(
			function (string $name): string {
				return ($name === 'If-None-Match') ? ($this->ifNoneMatch ?? '') : '';
			}
		);

		$this->ifNoneMatch = '';
		$first = $this->controller->schema('personen', 'persoon');
		$etag = $first->getHeaders()['ETag'];

		$this->ifNoneMatch = $etag;
		$second = $this->controller->schema('personen', 'persoon');

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $second->getStatus());
	}

	/** @var string */
	private string $ifNoneMatch = '';

	public function testUnknownRegisterReturns404(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->register('does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUnknownSchemaReturns404(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister());
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->request->method('getHeader')->willReturn('');

		$response = $this->controller->schema('personen', 'does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
