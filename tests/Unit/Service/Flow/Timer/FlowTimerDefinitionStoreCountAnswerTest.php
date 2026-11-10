<?php

/**
 * FlowTimerDefinitionStore: a register answer that is a count, not a list, falls back to the shipped descriptor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore
 */
class FlowTimerDefinitionStoreCountAnswerTest extends TestCase {

	public function testACountAnswerFallsBackToTheShippedDescriptor(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('searchObjectsBySlug')
			->with($this->equalTo(new RegisterSchemaRef(register: 'flow-timers', schema: 'working-calendar')), [], false, false)
			->willReturn(3);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(realpath(__DIR__ . '/../../../../..'));
		$store = new FlowTimerDefinitionStore(objects: $objects, appManager: $appManager, logger: new NullLogger());

		self::assertSame(['nl-national', 'example-organisation'], array_keys($store->calendars()));
	}//end testACountAnswerFallsBackToTheShippedDescriptor()
}//end class
