<?php

/**
 * The portal rules on the objects API: answers filtered, refused writes answered with 403.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\PortalPropertyRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Middleware\PortalPropertyMiddleware;
use OCA\OpenRegister\Service\Schemas\PortalPropertyPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The spec's case schema behind the objects API, with and without a portal subject.
 */
#[CoversClass(PortalPropertyMiddleware::class)]
#[UsesClass(PortalPropertyPolicy::class)]
#[UsesClass(PortalPropertyRefusedException::class)]
#[UsesClass(Schema::class)]
#[UsesClass(ObjectEntity::class)]
class PortalPropertyMiddlewareTest extends TestCase {

	/**
	 * The stored case.
	 *
	 * @var array<string, string>
	 */
	private const STORED = ['subject' => 'Bin not collected', 'phone' => '0612345678', 'internalNote' => 'caller was rude'];

	/**
	 * The object service the middleware reads the stored case through.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * Set up the object service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectOperationsInterface::class);
		$stored = new ObjectEntity();
		$stored->setObject(self::STORED);
		$this->objects->method('getObject')->willReturn($stored);
	}//end setUp()

	/**
	 * A middleware over one request.
	 *
	 * @param string               $header The X-Portal-Subject header, '' for none.
	 * @param array<string, mixed> $params The request parameters, route parameters included.
	 *
	 * @return PortalPropertyMiddleware The middleware.
	 */
	private function middleware(string $header, array $params = []): PortalPropertyMiddleware {
		$params += ['register' => 'gemeente', 'schema' => 'case'];
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => (['X-Portal-Subject' => $header][$name] ?? ''));
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$schema = new Schema();
		$schema->setProperties(
			[
				'subject'      => ['type' => 'string', 'portal' => 'read'],
				'phone'        => ['type' => 'string', 'portal' => 'edit'],
				'internalNote' => ['type' => 'string'],
			]
		);
		$schemas = $this->createMock(originalClassName: SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		return new PortalPropertyMiddleware(request: $request, schemas: $schemas, objects: $this->objects, policy: new PortalPropertyPolicy());
	}//end middleware()

	/**
	 * The objects controller, as the middleware recognises it.
	 *
	 * @return ObjectsController The controller double.
	 */
	private function objectsController(): ObjectsController {
		return $this->createMock(originalClassName: ObjectsController::class);
	}//end objectsController()

	/**
	 * Scenario "an internal note stays off the portal", on show and on index.
	 *
	 * @return void
	 */
	public function testAPortalReadLeavesOutWhatThePortalMayNotSee(): void {
		$middleware = $this->middleware(header: 'assertion');
		$case       = ['id' => 'case-1'] + self::STORED + ['@self' => ['id' => 'case-1']];

		$shown = $middleware->afterController(controller: $this->objectsController(), methodName: 'show', response: new JSONResponse($case));
		$this->assertSame(
			expected: ['id' => 'case-1', 'subject' => 'Bin not collected', 'phone' => '0612345678', '@self' => ['id' => 'case-1']],
			actual: $shown->getData()
		);

		$list   = new JSONResponse(['results' => [$case], 'total' => 1]);
		$listed = $middleware->afterController(controller: $this->objectsController(), methodName: 'index', response: $list);
		$row    = $listed->getData()['results'][0];
		$this->assertSame(expected: ['subject', 'phone'], actual: array_values(array_diff(array_keys($row), ['id', '@self'])));
		$this->assertSame(expected: 1, actual: $listed->getData()['total']);
	}//end testAPortalReadLeavesOutWhatThePortalMayNotSee()

	/**
	 * A write answers with the object it wrote; for a portal caller that answer is filtered too.
	 *
	 * @return void
	 */
	public function testAPortalWriteAnswersWithoutWhatThePortalMayNotSee(): void {
		$answer = $this->middleware(header: 'assertion')->afterController(
			controller: $this->objectsController(),
			methodName: 'patch',
			response: new JSONResponse(self::STORED)
		);

		$this->assertArrayNotHasKey(key: 'internalNote', array: $answer->getData());
		$this->assertArrayHasKey(key: 'phone', array: $answer->getData());
	}//end testAPortalWriteAnswersWithoutWhatThePortalMayNotSee()

	/**
	 * Without the header nothing changes: the note stays in the answer and every write goes ahead.
	 *
	 * @return void
	 */
	public function testACallerWithoutAPortalSubjectSeesNoChange(): void {
		$middleware = $this->middleware(header: '', params: ['id' => 'case-1', 'subject' => 'changed by staff']);

		$shown = $middleware->afterController(controller: $this->objectsController(), methodName: 'show', response: new JSONResponse(self::STORED));
		$this->assertSame(expected: self::STORED, actual: $shown->getData());

		$this->objects->expects($this->never())->method('setObject');
		$middleware->beforeController(controller: $this->objectsController(), methodName: 'patch');
	}//end testACallerWithoutAPortalSubjectSeesNoChange()

	/**
	 * Scenario "the resident changes only what they may change": subject refused by name, phone allowed.
	 *
	 * @return void
	 */
	public function testAPortalWriteToAReadPropertyIsRefusedNamingIt(): void {
		$this->middleware(header: 'assertion', params: ['id' => 'case-1', 'phone' => '0699999999'])
			->beforeController(controller: $this->objectsController(), methodName: 'patch');

		try {
			$this->middleware(header: 'assertion', params: ['id' => 'case-1', 'subject' => 'changed'])
				->beforeController(controller: $this->objectsController(), methodName: 'patch');
			$this->fail(message: 'A change to subject must be refused.');
		} catch (PortalPropertyRefusedException $refused) {
			$this->assertSame(expected: ['subject'], actual: $refused->getProperties());
		}
	}//end testAPortalWriteToAReadPropertyIsRefusedNamingIt()

	/**
	 * A PUT replaces: leaving out the stored subject and note empties them, so it is refused for both.
	 *
	 * @return void
	 */
	public function testAPortalReplaceThatDropsReadPropertiesIsRefused(): void {
		$this->expectException(exception: PortalPropertyRefusedException::class);
		$this->expectExceptionMessage(message: 'subject, internalNote');

		$this->middleware(header: 'assertion', params: ['id' => 'case-1', 'phone' => '1'])
			->beforeController(controller: $this->objectsController(), methodName: 'update');
	}//end testAPortalReplaceThatDropsReadPropertiesIsRefused()

	/**
	 * An object the caller cannot read is judged as a create: only editable properties may be sent.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIsJudgedAsACreate(): void {
		$this->objects->method('setObject')->willThrowException(new DoesNotExistException('not found'));

		$this->expectException(exception: PortalPropertyRefusedException::class);
		$this->middleware(header: 'assertion', params: ['id' => 'case-1', 'subject' => 'Bin not collected'])
			->beforeController(controller: $this->objectsController(), methodName: 'patch');
	}//end testAnUnreadableObjectIsJudgedAsACreate()

	/**
	 * A refusal is answered with 403 naming the properties; any other exception passes through untouched.
	 *
	 * @return void
	 */
	public function testARefusalIsAnswered403AndOtherExceptionsPassThrough(): void {
		$middleware = $this->middleware(header: 'assertion');
		$answer     = $middleware->afterException(
			controller: $this->objectsController(),
			methodName: 'patch',
			exception: new PortalPropertyRefusedException(properties: ['subject'])
		);

		$this->assertSame(expected: 403, actual: $answer->getStatus());
		$this->assertSame(expected: ['subject'], actual: $answer->getData()['properties']);
		$this->assertStringContainsString(needle: 'subject', haystack: $answer->getData()['message']);

		$this->expectException(exception: RuntimeException::class);
		$middleware->afterException(controller: $this->objectsController(), methodName: 'patch', exception: new RuntimeException('boom'));
	}//end testARefusalIsAnswered403AndOtherExceptionsPassThrough()

	/**
	 * Another controller is never touched, header or not.
	 *
	 * @return void
	 */
	public function testAnotherControllerIsNotTouched(): void {
		$other  = $this->createMock(originalClassName: Controller::class);
		$answer = $this->middleware(header: 'assertion')->afterController(controller: $other, methodName: 'show', response: new JSONResponse(self::STORED));

		$this->assertSame(expected: self::STORED, actual: $answer->getData());
	}//end testAnotherControllerIsNotTouched()
}//end class
