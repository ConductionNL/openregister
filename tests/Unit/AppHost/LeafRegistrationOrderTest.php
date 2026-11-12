<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\LeafAdoption;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation test: the exact order in which a leaf app's services are
 * registered, and which names share one factory.
 *
 * A later registration of the same name replaces an earlier one and the
 * AppHost bootstrap documents registration order as part of its contract, so
 * the order is pinned, not just the presence.
 */
class LeafRegistrationOrderTest extends TestCase {

	public function testRegistrationOrderAndSharedFactories(): void {
		$context = new RecordingRegistrationContext();
		Bootstrap::register(context: $context, adoption: new LeafAdoption(appId: 'petstore', options: [
			'namespace' => 'OCA\\PetStoreFixture',
			'observability' => true,
			'deepLinks' => true,
		]));

		$this->assertSame(
			[
				'OCA\\PetStoreFixture\\Controller\\DashboardController',
				'OCA\\PetStoreFixture\\Controller\\PreferencesController',
				'OCA\\PetStoreFixture\\Controller\\SettingsController',
				'OCA\\PetStoreFixture\\Controller\\StoreController',
				'OCA\\PetStoreFixture\\Controller\\HealthController',
				'OCA\\PetStoreFixture\\Controller\\MetricsController',
				'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService',
				'OCA\\PetStoreFixture\\Service\\SettingsService',
				'OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService',
				'OCA\\PetStoreFixture\\Service\\ActionAuthService',
				'OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService',
				'OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver',
				'OCA\\PetStoreFixture\\Service\\RegisterConfigResolver',
				'OCA\\PetStoreFixture\\Repair\\InitializeSettings',
				'OCA\\PetStoreFixture\\Repair\\InitializeActions',
				'OCA\\PetStoreFixture\\Settings\\AdminSettings',
				'OCA\\PetStoreFixture\\Sections\\SettingsSection',
				'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener',
				'OCA\\PetStoreFixture\\Listener\\DeepLinkRegistrationListener',
			],
			$context->services
		);

		$shared = [
			'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService' => 'OCA\\PetStoreFixture\\Service\\SettingsService',
			'OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService' => 'OCA\\PetStoreFixture\\Service\\ActionAuthService',
			'OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver' => 'OCA\\PetStoreFixture\\Service\\RegisterConfigResolver',
			'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener' => 'OCA\\PetStoreFixture\\Listener\\DeepLinkRegistrationListener',
		];
		foreach ($shared as $generic => $leaf) {
			$this->assertSame($context->factories[$generic], $context->factories[$leaf], $leaf);
		}

		$this->assertNotSame(
			$context->factories['OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService'],
			$context->factories['OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService']
		);
	}
}
