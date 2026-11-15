<?php

/**
 * Unit tests for OpenRegisterActionAuthService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\OpenRegisterActionAuthService
 * @uses   \OCA\OpenRegister\AppHost\Service\GenericActionAuthService
 */
final class OpenRegisterActionAuthServiceTest extends TestCase {

	/**
	 * The matrix is read from the openregister app config.
	 *
	 * @return void
	 */
	public function testTheMatrixIsReadFromTheOpenRegisterAppConfig(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('openregister', 'actions', '{}')
			->willReturn('{"objects.write":["editors","editors",""]}');

		$service = new OpenRegisterActionAuthService($appConfig, $this->createMock(IGroupManager::class));

		$this->assertSame(['objects.write' => ['editors']], $service->getMatrix());
	}//end testTheMatrixIsReadFromTheOpenRegisterAppConfig()

	/**
	 * The matrix is written to the openregister app config.
	 *
	 * @return void
	 */
	public function testTheMatrixIsWrittenToTheOpenRegisterAppConfig(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('setValueString')
			->with('openregister', 'actions', '{"objects.read":["staff"]}');

		$service = new OpenRegisterActionAuthService($appConfig, $this->createMock(IGroupManager::class));
		$service->setMatrix(['objects.read' => ['staff']]);
	}//end testTheMatrixIsWrittenToTheOpenRegisterAppConfig()
}//end class
