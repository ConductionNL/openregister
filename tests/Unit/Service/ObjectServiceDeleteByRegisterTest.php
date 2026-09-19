<?php

/**
 * ObjectService::deleteObjectsByRegister() delegation test.
 *
 * The method was a stub that threw "deleteObjectsByRegister needs
 * reimplementation using MagicMapper (blob objects table retired)", and it is
 * routed as `POST /api/bulk/{register}/delete-register`, so that endpoint
 * failed on every request. It now hands the register to SchemaDeletionService,
 * the single implementation of schema-wide object deletion.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The register-wide delete reaches SchemaDeletionService.
 */
class ObjectServiceDeleteByRegisterTest extends TestCase {

	/**
	 * The register is resolved and handed over with the hard/soft choice intact.
	 *
	 * @return void
	 */
	public function testDeleteObjectsByRegisterDelegatesToSchemaDeletionService(): void {
		$register = new Register();
		$register->setId(7);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$result = ['deleted_count' => 2, 'deleted_uuids' => ['u1', 'u2'], 'register_id' => 7];

		$deletion = $this->createMock(SchemaDeletionService::class);
		$deletion->expects($this->once())
			->method('deleteObjectsByRegister')
			->with($register, true)
			->willReturn($result);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->with(SchemaDeletionService::class)->willReturn($deletion);

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		foreach (['registerMapper' => $registerMapper, 'container' => $container] as $name => $value) {
			(new ReflectionClass(ObjectService::class))->getProperty($name)->setValue($service, $value);
		}

		$this->assertSame($result, $service->deleteObjectsByRegister(registerId: 7, hardDelete: true));

	}//end testDeleteObjectsByRegisterDelegatesToSchemaDeletionService()
}//end class
