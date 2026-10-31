<?php

declare(strict_types=1);

/**
 * ObjectService enforces readOnly on a client update and not inside a SystemOperationContext.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The readOnly rule of the update path, for a client and for a system operation.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-system-operation-may-stamp-a-readonly-property
 */
class ObjectServiceReadOnlySystemOperationTest extends TestCase {

	/**
	 * A changed readOnly value, as ValidateObject reports it.
	 */
	private const READ_ONLY_VIOLATION = [
		'property' => 'paymentState',
		'attempted' => 'paid',
		'stored' => null,
		'message' => 'paymentState is readOnly',
	];

	/**
	 * An ObjectService whose update rules see a stored case and the given violations.
	 *
	 * @param array<int, array<string, mixed>> $readOnly  What validateReadOnlyConstraints answers.
	 * @param array<int, array<string, mixed>> $immutable What validateImmutableConstraints answers.
	 *
	 * @return ObjectService
	 */
	private function service(array $readOnly, array $immutable = []): ObjectService {
		$stored = new ObjectEntity();
		$stored->setObject(['title' => 'Case 1', 'paymentState' => null]);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturn($stored);

		$validate = $this->createMock(ValidateObject::class);
		$validate->method('validateReadOnlyConstraints')->willReturn($readOnly);
		$validate->method('validateImmutableConstraints')->willReturn($immutable);

		$reflection = new ReflectionClass(ObjectService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		foreach ([
			'objectMapper' => $mapper,
			'validateHandler' => $validate,
			'logger' => $this->createMock(LoggerInterface::class),
			'currentSchema' => new Schema(),
		] as $name => $value) {
			$reflection->getProperty(name: $name)->setValue(objectOrValue: $service, value: $value);
		}

		return $service;
	}//end service()

	/**
	 * Run the update path's readOnly / immutable rules.
	 *
	 * @param ObjectService $service The service.
	 *
	 * @return void
	 */
	private function enforce(ObjectService $service): void {
		$method = (new ReflectionClass(ObjectService::class))->getMethod(name: 'enforceReadOnlyOnUpdate');
		$method->invoke($service, ['title' => 'Case 1', 'paymentState' => 'paid'], 'case-1');
	}//end enforce()

	/**
	 * A client update that changes a readOnly value is refused.
	 *
	 * @return void
	 */
	public function testAClientUpdateToAReadOnlyPropertyIsRefused(): void {
		$this->expectException(exception: ValidationException::class);
		$this->expectExceptionMessage(message: 'paymentState');

		$this->enforce(service: $this->service(readOnly: [self::READ_ONLY_VIOLATION]));
	}//end testAClientUpdateToAReadOnlyPropertyIsRefused()

	/**
	 * The same write inside a system operation stamps the value.
	 *
	 * @return void
	 */
	public function testASystemOperationMayStampAReadOnlyProperty(): void {
		$service = $this->service(readOnly: [self::READ_ONLY_VIOLATION]);

		SystemOperationContext::run(operation: fn () => $this->enforce(service: $service));

		$this->assertFalse(condition: SystemOperationContext::isActive());
	}//end testASystemOperationMayStampAReadOnlyProperty()

	/**
	 * `immutable` still holds inside a system operation.
	 *
	 * @return void
	 */
	public function testImmutableStillHoldsInsideASystemOperation(): void {
		$service = $this->service(
			readOnly: [],
			immutable: [['property' => 'caseNumber', 'attempted' => 'B', 'stored' => 'A', 'message' => 'caseNumber is immutable']]
		);

		$this->expectException(exception: ValidationException::class);
		$this->expectExceptionMessage(message: 'caseNumber');

		SystemOperationContext::run(operation: fn () => $this->enforce(service: $service));
	}//end testImmutableStillHoldsInsideASystemOperation()
}//end class
