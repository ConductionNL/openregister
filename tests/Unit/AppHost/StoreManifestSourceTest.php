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

	/**
	 * A block that declares no posture keeps the strictest one.
	 *
	 * @return void
	 */
	public function testAbsentInstallAuthDefaultsToAdmin(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['schema' => 'template']]);

		$this->assertSame('admin', $manifest->installAuth);
		$this->assertTrue($manifest->isInstallAuthEnforceable());
		$this->assertFalse($manifest->permitsInstall(isSignedIn: true, isAdmin: false));
		$this->assertTrue($manifest->permitsInstall(isSignedIn: true, isAdmin: true));
	}

	/**
	 * `authenticated` admits any signed-in user and no anonymous one.
	 *
	 * @return void
	 */
	public function testAuthenticatedPostureAdmitsSignedInUsersOnly(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'authenticated'],
		]);

		$this->assertTrue($manifest->permitsInstall(isSignedIn: true, isAdmin: false));
		$this->assertFalse(
			$manifest->permitsInstall(isSignedIn: false, isAdmin: false),
			'The weakest posture must still mean signed in.'
		);
	}

	/**
	 * 🔴 An unknown posture disables the store rather than falling back.
	 *
	 * Falling back to `admin` would silently REMOVE a capability from an app
	 * that asked for a weaker gate: the store still works, for fewer people,
	 * for no stated reason.
	 *
	 * @return void
	 */
	public function testUnknownInstallAuthDisablesTheStore(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'everyone'],
		]);

		$this->assertFalse($manifest->enabled);
	}

	/**
	 * An `action:` posture exposes its action name and is enforceable.
	 *
	 * @return void
	 */
	public function testActionPostureExposesItsActionName(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'action:catalog.instantiate'],
		]);

		$this->assertTrue($manifest->enabled);
		$this->assertTrue($manifest->isInstallAuthEnforceable());
		$this->assertSame('catalog.instantiate', $manifest->installAction());
	}

	/**
	 * 🔴 The manifest REFUSES to answer for an action posture.
	 *
	 * The matrix lives in the leaf app, so this object cannot see the answer.
	 * Returning $isAdmin would quietly turn "the operators who hold this
	 * action" into "instance administrators" — exactly the capability loss
	 * this key exists to prevent. The controller resolves it instead.
	 *
	 * @return void
	 */
	public function testAnActionPostureIsNotDecidedByTheManifest(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'action:catalog.instantiate'],
		]);

		$this->assertFalse(
			$manifest->permitsInstall(isSignedIn: true, isAdmin: true),
			'Even an administrator must be decided by the leaf matrix, not assumed here.'
		);
	}

	/**
	 * A non-action posture reports no action name.
	 *
	 * @return void
	 */
	public function testANonActionPostureHasNoActionName(): void {
		$manifest = StoreManifest::fromManifest('demo', ['store' => ['schema' => 'template']]);

		$this->assertNull($manifest->installAction());
	}

	/**
	 * A bare `action:` with no name is malformed.
	 *
	 * @return void
	 */
	public function testABareActionPrefixIsMalformed(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'action:'],
		]);

		$this->assertFalse($manifest->enabled);
	}
}
