<?php

/**
 * `scope` means two different things, and collapsing one must not move the
 * other (tasks 8.6 and 8.7).
 *
 * The change asks to collapse `scope` as the ACCESS discriminator into
 * `private`, and to KEEP `scope` as the VAULT-OWNER selector untouched. Those
 * are the same word for two unrelated decisions:
 *
 * - **access**: may this caller see this object. That vocabulary lives in
 *   `ObjectScopeResolver` and holds exactly `organisation` and `private`.
 * - **vault owner**: whose encrypted vault a credential's secret is stored in.
 *   That vocabulary lives in the credential stores and holds `personal` and
 *   `organisation`.
 *
 * 🔴 THE FAILURE THIS PINS IS A DATA ONE, NOT A LOGIC ONE. A credential is
 * WRITTEN under one vault owner and READ under another, so if a collapse ever
 * moved `organisation` out of the vault-owner vocabulary — or made `private`
 * mean something to it — every organisation credential minted before that
 * change would be written to the system identity and looked for under the
 * caller's own, and come back `null`. Not an error: `null`, which the broker
 * reads as "no secret stored", so the integration simply stops
 * authenticating and nothing says why.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies that the two meanings of `scope` stay separate.
 */
class CredentialScopeIsNotAnAccessScopeTest extends TestCase {

	/**
	 * What the vault held, keyed by owner and key.
	 *
	 * @var array<string, mixed>
	 */
	private array $vault = [];

	/**
	 * A store over an in-memory vault, acting as one user.
	 *
	 * @param string $uid The signed-in user, or '' for none.
	 *
	 * @return NextcloudVaultCredentialStore The store.
	 */
	private function store(string $uid = 'anja'): NextcloudVaultCredentialStore {
		$manager = $this->createMock(ICredentialsManager::class);
		$manager->method('store')->willReturnCallback(
			function (string $owner, string $key, $value): void {
				$this->vault[$owner . '|' . $key] = $value;
			}
		);
		$manager->method('retrieve')->willReturnCallback(
			function (string $owner, string $key) {
				return ($this->vault[$owner . '|' . $key] ?? null);
			}
		);
		$manager->method('delete')->willReturnCallback(
			function (string $owner, string $key): int {
				$existed = (int)array_key_exists($owner . '|' . $key, $this->vault);
				unset($this->vault[$owner . '|' . $key]);
				return $existed;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === '') {
			$session->method('getUser')->willReturn(null);
		}

		if ($uid !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new NextcloudVaultCredentialStore(credentialsManager: $manager, userSession: $session);
	}//end store()

	/**
	 * 🔴 An organisation credential minted before the collapse is still
	 * readable after it — by a DIFFERENT user from the one who minted it.
	 *
	 * That second clause is the point. Reading it back as the same user would
	 * pass even if `organisation` had quietly become a per-user scope, because
	 * the same user's vault is where it would land either way.
	 *
	 * @return void
	 */
	public function testAnOrganisationCredentialSurvivesAndIsReadableByAnotherUser(): void {
		$this->store(uid: 'anja')->put(uuid: 'cred-1', secret: 's3cret', scope: 'organisation');

		$this->assertSame(
			's3cret',
			$this->store(uid: 'bram')->get(uuid: 'cred-1', scope: 'organisation'),
			'an organisation credential is shared, so a colleague must read the one Anja minted'
		);
	}//end testAnOrganisationCredentialSurvivesAndIsReadableByAnotherUser()

	/**
	 * The control: a PERSONAL credential is NOT readable by another user.
	 *
	 * Without it, the test above would pass on a store that ignored the scope
	 * and put everything under one owner.
	 *
	 * @return void
	 */
	public function testAPersonalCredentialIsNotReadableByAnotherUser(): void {
		$this->store(uid: 'anja')->put(uuid: 'cred-2', secret: 'mine', scope: 'personal');

		$this->assertNull(
			$this->store(uid: 'bram')->get(uuid: 'cred-2', scope: 'personal'),
			'the control: a personal credential lives in its own user\'s vault'
		);
		$this->assertSame('mine', $this->store(uid: 'anja')->get(uuid: 'cred-2', scope: 'personal'));
	}//end testAPersonalCredentialIsNotReadableByAnotherUser()

	/**
	 * 🔴 The ACCESS vocabulary and the VAULT-OWNER vocabulary are disjoint
	 * where it matters: `private` is not a vault owner.
	 *
	 * A collapse that taught the credential store about `private` would send an
	 * organisation credential to the caller's own vault the moment somebody
	 * spelled the access scope into a credential call.
	 *
	 * @return void
	 */
	public function testPrivateIsNotAVaultOwnerSelector(): void {
		$this->store(uid: 'anja')->put(uuid: 'cred-3', secret: 'x', scope: ObjectScopeResolver::SCOPE_PRIVATE);

		$this->assertNull(
			$this->store(uid: 'bram')->get(uuid: 'cred-3', scope: ObjectScopeResolver::SCOPE_PRIVATE),
			'"private" must fall through to the per-user vault, never to the shared one'
		);
		$this->assertSame(
			'x',
			$this->store(uid: 'anja')->get(uuid: 'cred-3', scope: ObjectScopeResolver::SCOPE_PRIVATE),
			'and an unknown selector behaving as "personal" is the safe fall-through, not the shared identity'
		);
	}//end testPrivateIsNotAVaultOwnerSelector()

	/**
	 * 🔴 The access vocabulary holds no `personal`, so 8.6 has nothing left to
	 * collapse — measured, rather than assumed from the task text.
	 *
	 * @return void
	 */
	public function testTheAccessVocabularyHasNoPersonalScope(): void {
		$constants = (new ReflectionClass(ObjectScopeResolver::class))->getConstants();

		$scopes = [];
		foreach ($constants as $name => $value) {
			if (str_starts_with((string)$name, 'SCOPE_') === true) {
				$scopes[] = (string)$value;
			}
		}

		$this->assertContains(ObjectScopeResolver::SCOPE_ORGANISATION, $scopes);
		$this->assertContains(ObjectScopeResolver::SCOPE_PRIVATE, $scopes);
		$this->assertNotContains(
			'personal',
			$scopes,
			'"personal" is a vault owner, never an access scope; if it appears here the two words have merged again'
		);
	}//end testTheAccessVocabularyHasNoPersonalScope()

	/**
	 * Only `organisation` reaches the shared system identity, and it reaches it
	 * by that exact spelling.
	 *
	 * @return void
	 */
	public function testOnlyOrganisationReachesTheSharedIdentity(): void {
		$store = $this->store(uid: 'anja');
		$store->put(uuid: 'shared', secret: 'a', scope: 'organisation');
		$store->put(uuid: 'own', secret: 'b', scope: 'personal');

		$this->assertArrayHasKey(
			'|openregister/credential/shared',
			$this->vault,
			'an organisation credential lands under the reserved empty-string identity'
		);
		$this->assertArrayHasKey('anja|openregister/credential/own', $this->vault);
	}//end testOnlyOrganisationReachesTheSharedIdentity()

	/**
	 * A delete follows the same selector as the write, so a credential cannot
	 * be orphaned in a vault nobody deletes from.
	 *
	 * @return void
	 */
	public function testDeleteFollowsTheSameSelectorAsTheWrite(): void {
		$this->store(uid: 'anja')->put(uuid: 'cred-4', secret: 'y', scope: 'organisation');
		$this->store(uid: 'bram')->delete(uuid: 'cred-4', scope: 'organisation');

		$this->assertNull(
			$this->store(uid: 'anja')->get(uuid: 'cred-4', scope: 'organisation'),
			'a write and a delete that disagree leave a secret nobody can reach and nobody removes'
		);
	}//end testDeleteFollowsTheSameSelectorAsTheWrite()
}//end class
