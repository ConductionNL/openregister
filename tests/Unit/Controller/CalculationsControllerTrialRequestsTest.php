<?php

/**
 * CalculationsController::evaluate(): the request shapes a trial accepts or refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\CalculationsControllerFactory;
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
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The trial endpoint's per-object guard, seen from the controller.
 */
class CalculationsControllerTrialRequestsTest extends TestCase {

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

		return CalculationsControllerFactory::create(
			$request,
			new OperatorCatalogue(),
			$trials,
			$this->registerMapper,
			$this->schemaMapper,
			$this->objectMapper,
		);
	}

	public function testATrialWithoutADeclarationOrExpressionIsRefusedExactly(): void {
		$refusal = [
			'ok' => false,
			'error' => [
				'code' => 'calculation-trial-no-declaration',
				'message' => 'A "calculation" object carrying an "expression" is required.',
			],
		];

		foreach ([[], ['calculation' => 'x'], ['calculation' => ['type' => 'integer']]] as $params) {
			$response = $this->controller($params)->evaluate();
			$this->assertSame(422, $response->getStatus());
			$this->assertSame($refusal, $response->getData());
		}
	}

	public function testASampleThatIsNotAnObjectIsTriedAsAnEmptySample(): void {
		$calculation = ['type' => 'integer', 'expression' => ['+' => [1, 1]]];

		$asText = $this->controller(['calculation' => $calculation, 'object' => 'nope'])->evaluate();
		$asEmpty = $this->controller(['calculation' => $calculation, 'object' => []])->evaluate();

		$this->assertSame($asEmpty->getStatus(), $asText->getStatus());
		$this->assertSame($asEmpty->getData(), $asText->getData());
		$this->assertSame(2, $asText->getData()['value']);
	}

	public function testAPartlyNamedObjectIsASampleTrialAndTouchesNoMapper(): void {
		$this->registerMapper->expects($this->never())->method($this->anything());
		$this->schemaMapper->expects($this->never())->method($this->anything());
		$this->objectMapper->expects($this->never())->method($this->anything());

		$response = $this->controller(
			[
				'calculation' => ['type' => 'integer', 'expression' => ['+' => [['prop' => 'aantal'], 1]]],
				'register' => '7',
				'schema' => '',
				'object' => ['aantal' => 4],
			]
		)->evaluate();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(5, $response->getData()['value']);
	}
}
