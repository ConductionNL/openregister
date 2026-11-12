<?php

/**
 * The OCM listener announces the openregister resource type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\OcmResourceTypeListener;
use OCP\EventDispatcher\Event;
use OCP\OCM\Events\ResourceTypeRegisterEvent;
use PHPUnit\Framework\TestCase;

/**
 * Registers one resource type for user and group shares on the federation endpoint.
 */
class OcmResourceTypeListenerTest extends TestCase {
	public function testRegistersTheOpenRegisterResourceTypeOnTheFederationEndpoint(): void {
		$event = $this->createMock(ResourceTypeRegisterEvent::class);
		$event->expects($this->once())->method('registerResourceType')->with(
			'openregister',
			['user', 'group'],
			['openregister' => '/apps/openregister/api/federation']
		);

		(new OcmResourceTypeListener())->handle($event);
	}

	public function testAnyOtherEventRegistersNothing(): void {
		// A look-alike carrying the same method, so a skipped type guard would be seen.
		$foreign = new class extends Event {
			/** @var list<array<int, mixed>> */
			public array $calls = [];

			public function registerResourceType(string $name, array $shareTypes, array $protocols): void {
				$this->calls[] = [$name, $shareTypes, $protocols];
			}
		};

		(new OcmResourceTypeListener())->handle($foreign);

		$this->assertSame([], $foreign->calls);
	}
}
