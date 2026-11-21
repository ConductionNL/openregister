<?php

/**
 * StoreManifest: every key of the `store` block reaches its own property.
 *
 * Pins the parse of a full block and the defaults of a disabled one, so the
 * grouping of the constructor cannot drop or swap a key.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Store\StoreManifest;
use PHPUnit\Framework\TestCase;

final class StoreManifestKeysGoldenTest extends TestCase {

	public function testEveryDeclaredKeyReachesItsProperty(): void {
		$manifest = StoreManifest::fromManifest(
			'dossiq',
			[
				'store' => [
					'schema' => 'case-type',
					'register' => 'catalogue',
					'localRegister' => 'dossiq-local',
					'installable' => ['caseType'],
					'kinds' => ['zaak'],
					'cardFields' => ['slug' => 'id'],
					'builtIn' => [['slug' => 'one']],
					'types' => ['flow'],
					'source' => 'github',
					'topics' => ['dossiq-store'],
					'installAuth' => 'authenticated',
					'configurable' => ['theme'],
					'publish' => ['fields' => ['title'], 'groups' => ['editors']],
				],
			]
		);

		$this->assertSame(
			['dossiq', true, 'case-type', 'catalogue', 'dossiq-local', ['caseType'], ['zaak'], ['slug' => 'id'], [['slug' => 'one']], ['flow'], 'github', ['dossiq-store'], 'authenticated', ['theme'], ['title'], ['editors']],
			[$manifest->appId, $manifest->enabled, $manifest->schema, $manifest->register, $manifest->localRegister, $manifest->installable, $manifest->kinds, $manifest->cardFields, $manifest->builtIn, $manifest->types, $manifest->source, $manifest->topics, $manifest->installAuth, $manifest->configurable, $manifest->publishFields, $manifest->publishGroups]
		);
	}//end testEveryDeclaredKeyReachesItsProperty()

	public function testADisabledManifestCarriesTheDefaults(): void {
		$manifest = new StoreManifest(appId: 'keepiq', enabled: false);

		$this->assertSame(
			['keepiq', false, '', '', '', [], [], StoreManifest::DEFAULT_CARD_FIELDS, [], [], StoreManifest::DEFAULT_SOURCE, [], StoreManifest::DEFAULT_INSTALL_AUTH, [], [], []],
			[$manifest->appId, $manifest->enabled, $manifest->schema, $manifest->register, $manifest->localRegister, $manifest->installable, $manifest->kinds, $manifest->cardFields, $manifest->builtIn, $manifest->types, $manifest->source, $manifest->topics, $manifest->installAuth, $manifest->configurable, $manifest->publishFields, $manifest->publishGroups]
		);
	}//end testADisabledManifestCarriesTheDefaults()
}//end class
