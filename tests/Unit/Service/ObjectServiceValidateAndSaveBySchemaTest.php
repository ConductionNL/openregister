<?php

/**
 * ObjectServiceValidateAndSaveBySchemaTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * validateAndSaveObjectsBySchema() forwards the ids, the page and the save callback unchanged.
 */
class ObjectServiceValidateAndSaveBySchemaTest extends TestCase {

	/**
	 * An ObjectService with only the validation handler set.
	 *
	 * @param ValidationHandler $handler The validation handler.
	 *
	 * @return ObjectService
	 */
	private function service(ValidationHandler $handler): ObjectService {
		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		(function () use ($handler): void {
			$this->validationHandler = $handler;
		})->call($service);

		return $service;
	}

	public function testWithoutALimitEveryObjectIsProcessedFromTheStart(): void {
		$handler = $this->createMock(ValidationHandler::class);
		$service = $this->service($handler);
		$handler->expects($this->once())->method('validateAndSaveObjectsBySchema')
			->with(3, 9, [$service, 'saveObject'], null, 0)
			->willReturn(['processed' => 0]);

		$this->assertSame(['processed' => 0], $service->validateAndSaveTable(new RegisterSchemaRef(register: 3, schema: 9), ListQuery::unbounded()));
	}

	public function testALimitAndOffsetAreForwarded(): void {
		$handler = $this->createMock(ValidationHandler::class);
		$service = $this->service($handler);
		$handler->expects($this->once())->method('validateAndSaveObjectsBySchema')
			->with(3, 9, [$service, 'saveObject'], 50, 100)
			->willReturn(['processed' => 50]);

		$this->assertSame(['processed' => 50], $service->validateAndSaveTable(new RegisterSchemaRef(register: 3, schema: 9), new ListQuery(limit: 50, offset: 100)));
	}

	public function testAZeroLimitStaysZero(): void {
		$handler = $this->createMock(ValidationHandler::class);
		$service = $this->service($handler);
		$handler->expects($this->once())->method('validateAndSaveObjectsBySchema')
			->with(3, 9, [$service, 'saveObject'], 0, 0)
			->willReturn([]);

		$service->validateAndSaveTable(new RegisterSchemaRef(register: 3, schema: 9), new ListQuery(limit: 0, offset: 0));
	}
}
