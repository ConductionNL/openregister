<?php

/**
 * CredentialBrokerSessionlessOrganisationTest — sessionless org-scope resolution (openregister#450).
 *
 * Pins the ADR-064 Rule 4 unblock: an `organisation`-scoped INJECT-ONLY infrastructure
 * credential (an openconnector source/consumer secret) must resolve for a TRUSTED IN-PROCESS
 * caller that has NO user session (a migration repair step, a background sync job), via a
 * `resolveInjectable($id, $app, $actingUserId, $actingOrganisationId)` assertion.
 *
 * The contract:
 *   - sessionless + actingOrganisationId === org → ADMITS (secret read from the org vault);
 *   - sessionless + actingOrganisationId !== org → DENIES;
 *   - sessionless + actingOrganisationId === null → DENIES (a caller that asserts nothing);
 *   - session present → the session path is AUTHORITATIVE and the asserted value is IGNORED:
 *       a session member admits despite a WRONG assertion, and a session non-member is denied
 *       even when the assertion matches the org (the assertion can never rescue a non-member).
 *
 * The admit/deny tests assert on the BRANCH actually taken (store read with the org scope, or
 * membership consulted / not consulted), not merely on the return value, so reverting the new
 * sessionless admit — or dropping the `=== $org` match — makes a test fail (mutation guard).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialBrokerSessionlessOrganisationTest extends TestCase {
	private const UUID = 'cred-org-inject-1';

	private const ORG_UUID = 'org-uuid-tender-office';

	private const OTHER_ORG_UUID = 'org-uuid-some-other-org';

	private const SECRET = 'ORG-VAULT-KEY-XYZ';

	/**
	 * An inject-only generic catalogue entry (no baseUrl, no allowRules).
	 *
	 * @return array<string, mixed>
	 */
	private function genericProvider(): array {
		return [
			'identifier' => 'generic-apikey',
			'inject_only' => true,
			'authScheme' => ['header' => 'Authorization', 'template' => '{secret}'],
		];
	}

	/**
	 * Sessionless + a MATCHING actingOrganisationId → admits and reads the org-scoped secret.
	 *
	 * The store read pinned to the `organisation` scope proves the org branch (not the
	 * personal one) admitted, so reverting the sessionless admit makes this fail.
	 */
	public function testSessionlessMatchingActingOrganisationResolvesSecret(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->once())->method('get')
			->with(self::UUID, 'organisation')
			->willReturn(self::SECRET);

		// Org scope must NEVER be recoupled to a user's membership on the sessionless path.
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->expects($this->never())->method('hasAccessToOrganisation');

		$broker = $this->makeBroker(sessionUid: null, store: $store, orgService: $orgService);

		$secret = $broker->resolveInjectable(
			self::UUID,
			'openconnector',
			null,
			self::ORG_UUID
		);

		$this->assertSame(self::SECRET, $secret);
	}

	/**
	 * Sessionless + a NON-MATCHING actingOrganisationId → denies before any secret read.
	 *
	 * This is the mutation guard for the `=== $org` match: an admit-on-any-non-null
	 * implementation would resolve the secret here and fail the assertion.
	 */
	public function testSessionlessMismatchedActingOrganisationIsDenied(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('get');

		$broker = $this->makeBroker(sessionUid: null, store: $store);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->resolveInjectable(
			self::UUID,
			'openconnector',
			null,
			self::OTHER_ORG_UUID
		);
	}

	/**
	 * Sessionless + NO actingOrganisationId → denies (unchanged from before #450 for a
	 * caller that asserts nothing).
	 */
	public function testSessionlessWithoutActingOrganisationIsDenied(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('get');

		$broker = $this->makeBroker(sessionUid: null, store: $store);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->resolveInjectable(self::UUID, 'openconnector', null, null);
	}

	/**
	 * Sessionless + an EMPTY-STRING actingOrganisationId → denies (an empty assertion is
	 * not an identity, and it can never equal a real org UUID).
	 */
	public function testSessionlessEmptyActingOrganisationIsDenied(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('get');

		$broker = $this->makeBroker(sessionUid: null, store: $store);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->resolveInjectable(self::UUID, 'openconnector', null, '');
	}

	/**
	 * Session present: the session path is AUTHORITATIVE and the asserted value is IGNORED.
	 * A session MEMBER resolves the secret despite a WRONG actingOrganisationId — proving the
	 * assertion is not even consulted when a session exists (so it can never be abused).
	 */
	public function testSessionMemberIgnoresWrongActingOrganisation(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->once())->method('get')
			->with(self::UUID, 'organisation')
			->willReturn(self::SECRET);

		$orgService = $this->createMock(OrganisationService::class);
		// Membership is resolved against the credential's org, not the (wrong) assertion.
		$orgService->expects($this->once())->method('hasAccessToOrganisation')
			->with(self::ORG_UUID)
			->willReturn(true);

		$broker = $this->makeBroker(sessionUid: 'member-bob', store: $store, orgService: $orgService);

		$secret = $broker->resolveInjectable(
			self::UUID,
			'openconnector',
			null,
			self::OTHER_ORG_UUID
		);

		$this->assertSame(self::SECRET, $secret);
	}

	/**
	 * Session present: a NON-MEMBER is denied even when actingOrganisationId matches the org —
	 * the assertion can never rescue a non-member because the session branch never reads it.
	 */
	public function testSessionNonMemberDeniedEvenWithMatchingActingOrganisation(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('get');

		$orgService = $this->createMock(OrganisationService::class);
		$orgService->expects($this->once())->method('hasAccessToOrganisation')
			->with(self::ORG_UUID)
			->willReturn(false);

		$broker = $this->makeBroker(sessionUid: 'stranger', store: $store, orgService: $orgService);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->resolveInjectable(
			self::UUID,
			'openconnector',
			null,
			self::ORG_UUID
		);
	}

	/**
	 * Build a broker over an inject-only ORGANISATION credential.
	 *
	 * @param string|null $sessionUid Session user id, or null for sessionless.
	 * @param CredentialStore|null $store Optional store override.
	 * @param OrganisationService|null $orgService Optional organisation service override.
	 */
	private function makeBroker(
		?string $sessionUid,
		?CredentialStore $store = null,
		?OrganisationService $orgService = null,
	): CredentialBrokerService {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setOwner('provisioning-admin');
		$entity->setObject(
			[
				'name' => 'Tender-office source API key',
				'provider' => 'generic-apikey',
				'owner' => 'provisioning-admin',
				'scope' => 'organisation',
				'organisation' => self::ORG_UUID,
				'allowedApps' => ['openconnector'],
			]
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		if ($store === null) {
			$store = $this->createMock(CredentialStore::class);
			$store->method('get')->willReturn(self::SECRET);
		}

		if ($orgService === null) {
			$orgService = $this->createMock(OrganisationService::class);
			$orgService->method('hasAccessToOrganisation')->willReturn(true);
		}

		$user = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($this->genericProvider());

		return new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
			$orgService
		);
	}
}//end class
