<?php

/**
 * Pins which protected properties an update carries forward, allowed and refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
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

use OCA\OpenRegister\Service\ProtectedCarryForward;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\PropertyRbacCaller;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacRules;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * collectOmittedProtectedProperties(): a protected property is carried forward
 * only when the update omits it, the stored object has it, and the caller may
 * not read or not update it.
 */
class PropertyRbacHandlerCarryForwardGoldenTest extends TestCase {

	/**
	 * The handler for a user in the given groups.
	 *
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return PropertyRbacHandler
	 */
	private function handlerFor(array $groups): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new PropertyRbacHandler(
			caller: new PropertyRbacCaller($session, $groupManager),
			rules: new PropertyRbacRules($this->createMock(ConditionMatcher::class), $this->createMock(StateFieldRuleResolver::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A schema with a read-protected, an update-protected and an open property.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$rules = [
			'secret' => ['read' => ['readers'], 'update' => ['readers']],
			'frozen' => ['update' => ['editors']],
		];
		$schema = $this->createMock(Schema::class);
		$schema->method('hasPropertyAuthorization')->willReturn(true);
		$schema->method('getPropertiesWithAuthorization')->willReturn($rules);
		$schema->method('getPropertyAuthorization')->willReturnCallback(fn (string $p) => $rules[$p] ?? null);

		return $schema;
	}

	public function testAnOmittedPropertyTheCallerMayNotTouchIsCarriedForward(): void {
		$stored = ['secret' => 's', 'frozen' => 'f', 'open' => 'o'];

		$this->assertSame(['secret', 'frozen'], $this->handlerFor([])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), ['open' => 'x'], $stored)));
	}

	public function testAPropertyTheUpdateCarriesIsNotCarriedForward(): void {
		$stored = ['secret' => 's', 'frozen' => 'f'];

		$this->assertSame(['frozen'], $this->handlerFor([])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), ['secret' => 'new'], $stored)));
	}

	public function testAPropertyNeverStoredIsNotCarriedForward(): void {
		$this->assertSame(['frozen'], $this->handlerFor([])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), [], ['frozen' => 'f'])));
	}

	public function testACallerAllowedToReadAndUpdateCarriesNothing(): void {
		$stored = ['secret' => 's', 'frozen' => 'f'];

		$this->assertSame(['frozen'], $this->handlerFor(['readers'])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), [], $stored)));
		$this->assertSame([], $this->handlerFor(['readers', 'editors'])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), [], $stored)));
	}

	public function testAnAdministratorCarriesNothing(): void {
		$this->assertSame([], $this->handlerFor(['admin'])->collectOmittedProtectedProperties(new ProtectedCarryForward($this->schema(), [], ['secret' => 's'])));
	}
}
