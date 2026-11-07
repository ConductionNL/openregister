<?php

/**
 * Shared arrange helpers for the A24 golden tests of the Tier-2 link controllers
 * (Xwiki, Poll, Talk, OpenProject, TimeTracker, Photo).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Base for the link controller golden tests: a request with a param map and an object resolver.
 */
abstract class LinkedSourceGoldenTestCase extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	protected IRequest&MockObject $request;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	protected ObjectOperationsInterface&MockObject $objectService;

	/**
	 * Request params by name.
	 *
	 * @var array<string, mixed>
	 */
	protected array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				if (array_key_exists($key, $this->params) === true) {
					return $this->params[$key];
				}

				return $default;
			}
		);

		$this->objectService = $this->createMock(ObjectOperationsInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
	}//end setUp()

	protected function givenObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('abc-123');
		$object->setRegister(1);
		$object->setSchema(2);
		$this->objectService->method('getObject')->willReturn($object);
		return $object;
	}

	protected function givenNoObject(): void {
		$this->objectService->method('getObject')->willReturn(null);
	}

	protected function givenObjectLookupThrows(): void {
		$this->objectService->method('getObject')->willThrowException(new DoesNotExistException('gone'));
	}

	protected function givenObjectNeverResolved(): void {
		$this->objectService->expects($this->never())->method('getObject');
	}

	protected function target(): LinkTarget {
		return (new LinkTarget('abc-123', 1))->inSchema(2);
	}

	protected function assertResponse(int $status, array $data, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}

	protected function assertNotFound(JSONResponse $response): void {
		$this->assertResponse(404, ['error' => 'Object not found'], $response);
	}
}//end class
