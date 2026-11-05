<?php

/**
 * The publish settings of a manifest `store` block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Store\StoreManifest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
class StoreManifestPublishTest extends TestCase {

	/**
	 * The declared fields and groups are read.
	 *
	 * @return void
	 */
	public function testThePublishFieldsAndGroupsAreRead(): void {
		$store = StoreManifest::fromManifest(
			appId: 'pipelinq',
			manifest: ['store' => [
				'schema'  => 'commercial-template',
				'publish' => ['fields' => ['title', 'version'], 'groups' => ['pipelinq-publishers']],
			]]
		);

		$this->assertSame(['title', 'version'], $store->publishFields);
		$this->assertSame(['pipelinq-publishers'], $store->publishGroups);

	}//end testThePublishFieldsAndGroupsAreRead()

	/**
	 * A block without publish settings cannot publish.
	 *
	 * @return void
	 */
	public function testABlockWithoutPublishSettingsDeclaresNone(): void {
		$store = StoreManifest::fromManifest(appId: 'pipelinq', manifest: ['store' => ['schema' => 'commercial-template']]);

		$this->assertSame([], $store->publishFields);
		$this->assertSame([], $store->publishGroups);

	}//end testABlockWithoutPublishSettingsDeclaresNone()
}//end class
