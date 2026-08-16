<?php

/**
 * OpenRegister TransitionEngine slug-contract tests
 *
 * ObjectTransitionedEvent documents `register` and `schema` as SLUGS, but the
 * engine has always advertised the numeric ids carried by the entity. Correcting
 * that wakes dormant listeners fleet-wide, so the corrected contract ships behind
 * the `transition_event_slug_contract` app-config flag, DEFAULT OFF.
 *
 * These tests pin all three branches: default-off is byte-identical to the old
 * behaviour, opt-in resolves slugs, and a resolution failure falls back to the id
 * rather than breaking the transition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineSlugContractTest extends TestCase {
	/**
	 * The object under test carries IDS, exactly as MagicMapper writes them.
	 */
	private const REGISTER_ID = '17';

	private const SCHEMA_ID = '116';

	private const REGISTER_SLUG = 'zaken';

	private const SCHEMA_SLUG = 'zaak';

	private const CASE = '00000000-0000-0000-0000-0000000000aa';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private IEventDispatcher&MockObject $dispatcher;

	private IUserSession&MockObject $userSession;

	private PermissionHandler&MockObject $permission;

	private RegisterMapper&MockObject $registerMapper;

	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->permission = $this->createMock(PermissionHandler::class);
		$this->permission->method('hasPermission')->willReturn(true);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

	}//end setUp()

	/**
	 * Build the engine, with the slug-contract flag set to the given value.
	 *
	 * @param string $flag Value the app config reports for the opt-in flag.
	 */
	private function engine(string $flag): TransitionEngine {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($flag) {
					if ($app === 'openregister' && $key === TransitionEngine::SLUG_CONTRACT_FLAG) {
						return $flag;
					}

					return $default;
				}
			);

		return new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->dispatcher,
			$this->userSession,
			$this->permission,
			$this->registerMapper,
			$this->appConfig,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

	}//end engine()

	/**
	 * A case object whose register/schema are numeric IDS, as stored.
	 */
	private function caseObject(): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid(self::CASE);
		$case->setRegister(self::REGISTER_ID);
		$case->setSchema(self::SCHEMA_ID);
		$case->setObject(['status' => 'ontvangen']);
		return $case;
	}//end caseObject()

	/**
	 * Wire a minimal static-transition lifecycle so `transition()` succeeds.
	 *
	 * @param string|null $schemaSlug Slug the resolved schema reports, or null.
	 */
	private function wire(?string $schemaSlug): void {
		$annotation = [
			'field' => 'status',
			'transitions' => [
				'approve' => [
					'from' => ['ontvangen'],
					'to' => 'behandeld',
				],
			],
		];

		// Real entities, not mocks: `getSlug()` is a magic `@method` on Entity
		// and therefore cannot be configured on a PHPUnit double.
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-lifecycle' => $annotation]);
		$schema->setSlug($schemaSlug);

		$this->objectService->method('find')->willReturn($this->caseObject());
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('saveObject')->willReturn($this->caseObject());

	}//end wire()

	/**
	 * Capture the register/schema advertised on the dispatched event.
	 *
	 * @return array{0: string, 1: string} Register then schema, as dispatched.
	 */
	private function dispatchedScope(TransitionEngine $engine): array {
		$seen = [];
		$this->dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				function ($event) use (&$seen): void {
					$this->assertInstanceOf(ObjectTransitionedEvent::class, $event);
					$seen = [$event->getRegister(), $event->getSchema()];
				}
			);

		$engine->transition(self::CASE, 'approve');

		return $seen;
	}//end dispatchedScope()

	/**
	 * DEFAULT OFF: the event must carry the ids, byte-identical to today.
	 *
	 * This is the merge-safety assertion — if this ever changes, 44 dormant
	 * listeners wake at once on instances that never opted in.
	 */
	public function testDefaultOffAdvertisesIdsUnchanged(): void {
		$this->wire(self::SCHEMA_SLUG);
		$this->registerMapper->expects($this->never())->method('find');

		$scope = $this->dispatchedScope($this->engine('no'));

		$this->assertSame([self::REGISTER_ID, self::SCHEMA_ID], $scope);

	}//end testDefaultOffAdvertisesIdsUnchanged()

	/**
	 * Opt-in: the event carries the documented slugs. Positive control for the
	 * feature actually doing something — without it, the "off" test above passes
	 * against a resolver that never resolves anything.
	 */
	public function testOptInAdvertisesSlugs(): void {
		$register = new Register();
		$register->setSlug(self::REGISTER_SLUG);
		$this->registerMapper->method('find')->willReturn($register);

		$this->wire(self::SCHEMA_SLUG);

		$scope = $this->dispatchedScope($this->engine('yes'));

		$this->assertSame([self::REGISTER_SLUG, self::SCHEMA_SLUG], $scope);

	}//end testOptInAdvertisesSlugs()

	/**
	 * Opt-in with a failing register lookup: keep the id, do not throw. A
	 * lifecycle transition must not start failing because a slug lookup missed.
	 */
	public function testOptInFallsBackToIdWhenResolutionFails(): void {
		$this->registerMapper->method('find')
			->willThrowException(new RuntimeException('gone'));

		$this->wire(self::SCHEMA_SLUG);

		$scope = $this->dispatchedScope($this->engine('yes'));

		$this->assertSame([self::REGISTER_ID, self::SCHEMA_SLUG], $scope);

	}//end testOptInFallsBackToIdWhenResolutionFails()

	/**
	 * Opt-in where the entity simply has no slug: keep the id rather than
	 * advertising an empty string.
	 */
	public function testOptInKeepsIdWhenSlugIsEmpty(): void {
		$register = new Register();
		$register->setSlug('');
		$this->registerMapper->method('find')->willReturn($register);

		$this->wire(null);

		$scope = $this->dispatchedScope($this->engine('yes'));

		$this->assertSame([self::REGISTER_ID, self::SCHEMA_ID], $scope);

	}//end testOptInKeepsIdWhenSlugIsEmpty()
}//end class
