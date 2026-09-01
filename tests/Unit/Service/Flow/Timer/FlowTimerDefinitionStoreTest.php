<?php

/**
 * The definition store reads the seeded objects, falls back to the shipped
 * descriptor when the register is absent, and memoises until reset.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore
 */
class FlowTimerDefinitionStoreTest extends TestCase {

	private ObjectService&MockObject $objects;

	private FlowTimerDefinitionStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(realpath(__DIR__ . '/../../../../..'));
		$this->store = new FlowTimerDefinitionStore(objects: $this->objects, appManager: $appManager, logger: new NullLogger());
	}//end setUp()

	public function testSeededObjectsAreReadBySlugFromTheRegister(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['slug' => 'custom', 'hoursPerWorkingDay' => 6]);
		$nameless = $this->createMock(ObjectEntity::class);
		$nameless->method('getObject')->willReturn(['hoursPerWorkingDay' => 6]);
		$this->objects->expects(self::once())->method('searchObjectsBySlug')
			->with('flow-timers', 'working-calendar', [], false, false)
			->willReturn([$entity, $nameless]);

		$calendars = $this->store->calendars();
		self::assertSame(['custom'], array_keys($calendars), 'an object without a slug is not addressable');
		self::assertSame($calendars, $this->store->calendars(), 'memoised: the register is read once');
	}//end testSeededObjectsAreReadBySlugFromTheRegister()

	public function testTheShippedDescriptorAnswersWhenTheRegisterIsAbsentOrEmpty(): void {
		$this->objects->method('searchObjectsBySlug')->willReturnCallback(static function (string $register, string $schema): array {
			if ($schema === 'working-calendar') {
				throw new DoesNotExistException('not seeded yet');
			}

			return [];
		});

		self::assertSame(['nl-national', 'example-organisation'], array_keys($this->store->calendars()));
		self::assertArrayNotHasKey('@self', $this->store->calendars()['nl-national']);
		self::assertSame(['nl-termijn-default'], array_keys($this->store->ladders()));
		self::assertSame([14, 7, 2, 0], array_column($this->store->ladders()['nl-termijn-default']['rungs'], 'offset'));
	}//end testTheShippedDescriptorAnswersWhenTheRegisterIsAbsentOrEmpty()

	public function testResetForgetsTheMemoisedDefinitions(): void {
		$this->objects->expects(self::exactly(2))->method('searchObjectsBySlug')->willReturn([]);
		$this->store->ladders();
		$this->store->reset();
		$this->store->ladders();
	}//end testResetForgetsTheMemoisedDefinitions()
}//end class
