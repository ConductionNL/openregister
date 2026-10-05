<?php

/**
 * CalculationsController::evaluate() refuses an object the caller may not read.
 *
 * The trial endpoint is `#[NoAdminRequired]` and takes a caller-supplied
 * register, schema and object id. Its per-object guard is the lookup itself:
 * every mapper call runs with RBAC and tenancy on, so an object outside the
 * caller's read permission or organisation yields no row and a not-found.
 *
 * These tests pin the controller's half of that contract: the lookup keeps
 * both flags on, a refused lookup answers 404 with no value, and nothing is
 * evaluated against an object the caller could not read. The mapper's half,
 * that `findInRegisterSchemaTable()` with RBAC on adds the read filter to the
 * query, is pinned by MagicMapperFindInRegisterSchemaTableAccessControlTest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\CalculationsController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationTrialService;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The trial endpoint's per-object guard, seen from the controller.
 */
class CalculationsControllerTest extends TestCase {

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $objectMapper;

	private CalculationPayloadBuilder&MockObject $payloadBuilder;

	private Register $register;

	private Schema $schema;

	/**
	 * Wire mapper doubles that answer the register and schema lookups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new Register();
		$this->register->setId(7);
		$this->schema = new Schema();
		$this->schema->setId(9);
		$this->schema->setProperties(['aantal' => ['type' => 'integer']]);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->payloadBuilder = $this->createMock(CalculationPayloadBuilder::class);
	}

	/**
	 * Build the controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return CalculationsController The controller under test.
	 */
	private function controller(array $params): CalculationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$trials = new CalculationTrialService(
			new CalculationAnnotationValidator(),
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$this->payloadBuilder,
		);

		return new CalculationsController(
			'openregister',
			$request,
			new OperatorCatalogue(),
			$trials,
			$this->registerMapper,
			$this->schemaMapper,
			$this->objectMapper,
		);
	}

	/**
	 * A trial request naming object 42 in register 7, schema 9.
	 *
	 * @return array<string, mixed> The request parameters.
	 */
	private function objectTrial(): array {
		return [
			'calculation' => ['type' => 'integer', 'expression' => ['+' => [['prop' => 'aantal'], 1]]],
			'register' => '7',
			'schema' => '9',
			'objectId' => '42',
		];
	}

	/**
	 * A caller who may not read the object gets a not-found and no value.
	 *
	 * The mapper is asked with RBAC and tenancy on, and answers as it does when
	 * its read filter leaves no row: DoesNotExistException. The controller must
	 * then refuse without evaluating anything against the object.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotReadTheObjectIsRefused(): void {
		$this->registerMapper->expects($this->once())->method('find')
			->with('7', true, true)->willReturn($this->register);
		$this->schemaMapper->expects($this->once())->method('find')
			->with('9', [], true, true)->willReturn($this->schema);
		$this->objectMapper->expects($this->once())->method('findInRegisterSchemaTable')
			->with('42', $this->register, $this->schema, true, true)
			->willThrowException(new DoesNotExistException('Object not found in magic table'));
		$this->payloadBuilder->expects($this->never())->method('build');

		$response = $this->controller($this->objectTrial())->evaluate();
		$data = $response->getData();

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($data['ok']);
		$this->assertSame('calculation-trial-object-not-found', $data['error']['code']);
		$this->assertArrayNotHasKey('value', $data, 'a refused object must not leak a computed value');
	}

	/**
	 * A register the caller may not read stops the trial before any object lookup.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsRefusedBeforeTheObjectLookup(): void {
		$this->registerMapper->method('find')
			->willThrowException(new DoesNotExistException('Register not found'));
		$this->objectMapper->expects($this->never())->method('findInRegisterSchemaTable');
		$this->payloadBuilder->expects($this->never())->method('build');

		$response = $this->controller($this->objectTrial())->evaluate();

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('value', $response->getData());
	}

	/**
	 * An object the caller may read is evaluated and its value comes back.
	 *
	 * @return void
	 */
	public function testAReadableObjectIsEvaluated(): void {
		$object = new ObjectEntity();
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->objectMapper->expects($this->once())->method('findInRegisterSchemaTable')
			->with('42', $this->register, $this->schema, true, true)
			->willReturn($object);
		$this->payloadBuilder->expects($this->once())->method('build')
			->with($object, $this->schema)
			->willReturn(['aantal' => 3]);

		$response = $this->controller($this->objectTrial())->evaluate();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($data['ok']);
		$this->assertSame(4, $data['value']);
	}

	/**
	 * A sample trial never reaches a mapper.
	 *
	 * @return void
	 */
	public function testASampleTrialTouchesNoMapper(): void {
		$this->registerMapper->expects($this->never())->method($this->anything());
		$this->schemaMapper->expects($this->never())->method($this->anything());
		$this->objectMapper->expects($this->never())->method($this->anything());

		$response = $this->controller(
			[
				'calculation' => ['type' => 'integer', 'expression' => ['+' => [['prop' => 'aantal'], 1]]],
				'object' => ['aantal' => 1],
			]
		)->evaluate();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['value']);
	}
}
