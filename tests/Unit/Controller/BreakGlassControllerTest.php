<?php

/**
 * The break-glass endpoint: the declaration first, then the record, then the take (REQ-GSR-004).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\BreakGlassController;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use OCA\OpenRegister\Service\Rbac\BreakGlassService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The controller over mocked mappers and a mocked service.
 *
 * @covers \OCA\OpenRegister\Controller\BreakGlassController
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration
 */
class BreakGlassControllerTest extends TestCase {

	/**
	 * The object finder.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $objects;

	/**
	 * The service.
	 *
	 * @var BreakGlassService&MockObject
	 */
	private BreakGlassService $service;

	/**
	 * The schema the schema mapper answers.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->service = $this->createMock(BreakGlassService::class);
		$this->schema = new Schema();
		$this->schema->setSlug('zaak');
		$this->schema->setConfiguration(
			[BreakGlassDeclaration::ANNOTATION => ['verbs' => ['read'], 'maxDuration' => 'PT4H', 'notify' => ['users' => ['teamlead']]]]
		);
	}//end setUp()

	/**
	 * The controller with a request carrying this reason.
	 *
	 * @param mixed $reason The posted reason.
	 *
	 * @return BreakGlassController The controller.
	 */
	private function controller(mixed $reason = 'balie'): BreakGlassController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($key === 'reason' ? $reason : null));

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(fn (): Schema => $this->schema);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());

		return new BreakGlassController(
			request: $request,
			registers: $registers,
			schemas: $schemas,
			objects: $this->objects,
			breakGlass: $this->service,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A take answers 201 with the grant, the record looked up without the read check.
	 *
	 * @return void
	 */
	public function testATakeAnswersTheGrant(): void {
		$object = new ObjectEntity();
		$this->objects->expects($this->once())->method('find')
			->with('uuid-1', $this->isInstanceOf(Register::class), $this->schema, false, false)
			->willReturn($object);
		$this->service->expects($this->once())->method('take')
			->with($object, $this->schema, 'balie')
			->willReturn(['grantee' => 'ana', 'verbs' => ['read']]);

		$response = $this->controller()->take(register: 'zaken', schema: 'zaak', id: 'uuid-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['grantee' => 'ana', 'verbs' => ['read']], $response->getData());
	}//end testATakeAnswersTheGrant()

	/**
	 * An undeclaring schema is refused naming the schema, before any record is looked up.
	 *
	 * @return void
	 */
	public function testAnUndeclaringSchemaIsRefusedBeforeTheLookup(): void {
		$this->schema = new Schema();
		$this->schema->setSlug('dossier');
		$this->objects->expects($this->never())->method('find');
		$this->service->expects($this->never())->method('take');

		$response = $this->controller()->take(register: 'zaken', schema: 'dossier', id: 'uuid-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Schema "dossier" does not offer emergency access'], $response->getData());
	}//end testAnUndeclaringSchemaIsRefusedBeforeTheLookup()

	/**
	 * The service's refusals map to 400 and 403; a missing record to 404; anything else to a generic 500.
	 *
	 * @return void
	 */
	public function testFailuresMapToTheirStatus(): void {
		$this->objects->method('find')->willReturn(new ObjectEntity());

		$cases = [
			[new InvalidArgumentException('A reason is required to take emergency access'), 400, 'A reason is required to take emergency access'],
			[new NotAuthorizedException(message: 'You may not take emergency access on this schema'), 403, 'You may not take emergency access on this schema'],
			[new DoesNotExistException('gone'), 404, 'Object not found'],
			[new RuntimeException('database detail'), 500, 'Could not take emergency access'],
		];
		foreach ($cases as [$exception, $status, $message]) {
			$this->service = $this->createMock(BreakGlassService::class);
			$this->service->method('take')->willThrowException($exception);

			$response = $this->controller()->take(register: 'zaken', schema: 'zaak', id: 'uuid-1');

			$this->assertSame($status, $response->getStatus(), get_class($exception));
			$this->assertSame(['message' => $message], $response->getData());
		}
	}//end testFailuresMapToTheirStatus()
}//end class
