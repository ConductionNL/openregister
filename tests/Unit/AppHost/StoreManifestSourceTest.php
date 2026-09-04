<?php

/**
 * Tests for the store plane's discovery-source discriminator.
 *
 * The `source` key decides WHERE a store looks, so the interesting cases are
 * the ones that must NOT resolve to a working store:
 *
 *   - an unknown source must disable the block rather than fall back to
 *     `openregister`, which would point an app at a registry it never
 *     declared and then report `not_configured` for the wrong reason;
 *   - a `github` source with no topics has nothing to search, which is a
 *     malformed block rather than an empty store — an empty store is one that
 *     searched and found nothing, which is a different thing to tell a reader.
 *
 * The default is asserted directly, because it is the whole reason every store
 * declared before this key existed keeps working.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Store\StoreManifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\AppHost\Store\StoreManifest
 */
class StoreManifestSourceTest extends TestCase {
	/**
	 * A block that declares no source keeps the behaviour it had before the
	 * key existed.
	 *
	 * @return void
	 */
	public function testAbsentSourceDefaultsToOpenRegister(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['schema' => 'template']]);

		$this->assertTrue($manifest->enabled);
		$this->assertSame('openregister', $manifest->source);
		$this->assertSame([], $manifest->topics);
	}

	/**
	 * The default is spelled out, so naming it explicitly changes nothing.
	 *
	 * @return void
	 */
	public function testExplicitOpenRegisterSourceIsAccepted(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['source' => 'openregister', 'schema' => 'template'],
		]);

		$this->assertTrue($manifest->enabled);
		$this->assertSame('openregister', $manifest->source);
	}

	/**
	 * A github store is configured by its topics rather than a registry URL.
	 *
	 * @return void
	 */
	public function testGithubSourceKeepsItsTopics(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['source' => 'github', 'topics' => ['openbuild-app', 'buildiq-app']],
		]);

		$this->assertTrue($manifest->enabled);
		$this->assertSame('github', $manifest->source);
		$this->assertSame(['openbuild-app', 'buildiq-app'], $manifest->topics);
	}

	/**
	 * 🔴 The negative control that matters: an unknown source must DISABLE the
	 * store, never fall back to the default.
	 *
	 * @return void
	 */
	public function testUnknownSourceDisablesTheStore(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['source' => 'npm', 'schema' => 'template'],
		]);

		$this->assertFalse(
			$manifest->enabled,
			'An unknown source must disable the block rather than silently become openregister.'
		);
	}

	/**
	 * A github source with nothing to search is malformed, not empty.
	 *
	 * @return void
	 */
	public function testGithubSourceWithoutTopicsIsDisabled(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['source' => 'github']]);

		$this->assertFalse($manifest->enabled);
	}

	/**
	 * Topics on a non-github source are carried but do not make it github.
	 *
	 * A future source may want them, and dropping a declared key silently is
	 * the failure mode this whole plane is built to avoid.
	 *
	 * @return void
	 */
	public function testTopicsAreCarriedOnAnOpenRegisterSource(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['topics' => ['ignored-here'], 'schema' => 'template'],
		]);

		$this->assertTrue($manifest->enabled);
		$this->assertSame('openregister', $manifest->source);
		$this->assertSame(['ignored-here'], $manifest->topics);
	}
}
