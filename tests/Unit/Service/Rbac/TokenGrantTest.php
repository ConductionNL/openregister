<?php

/**
 * A token narrower than the person who issued it: the validator, the
 * intersection and the two bypasses it has to reach past.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a grant can only narrow, and that it narrows everybody.
 */
class TokenGrantTest extends TestCase {

	/**
	 * The moment every test judges against.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-18T12:00:00+00:00';

	/**
	 * A validator over the canonical catalogue.
	 *
	 * @return TokenGrantValidator The validator.
	 */
	private function validator(): TokenGrantValidator {
		return new TokenGrantValidator(catalogue: new PermissionCatalogue());
	}//end validator()

	/**
	 * The moment.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::NOW);
	}//end now()

	/**
	 * A grant that is acceptable, which the refusal tests then break one way.
	 *
	 * @return array<string, mixed> The grant.
	 */
	private function acceptable(): array {
		return [
			'verbs' => ['read', 'list'],
			'schemas' => ['zaak'],
			'expiresAt' => '2026-11-01T00:00:00+00:00',
		];
	}//end acceptable()

	/**
	 * The control: a grant narrower than its issuer is accepted.
	 *
	 * Without this, every refusal below could be passing because the validator
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testAGrantNarrowerThanItsIssuerIsAccepted(): void {
		$this->assertNull(
			$this->validator()->refusalFor(
				grant: $this->acceptable(),
				issuerVerbs: ['read', 'list', 'create', 'update'],
				now: $this->now()
			),
			'the control: a well-formed, narrower grant is issued'
		);
	}//end testAGrantNarrowerThanItsIssuerIsAccepted()

	/**
	 * 🔴 An empty verb list is refused, not read as "every verb".
	 *
	 * @return void
	 */
	public function testAnEmptyVerbListIsRefused(): void {
		$grant = $this->acceptable();
		$grant['verbs'] = [];

		$refusal = $this->validator()->refusalFor(grant: $grant, issuerVerbs: ['read'], now: $this->now());

		$this->assertNotNull($refusal, 'an empty list is a filter that filters nothing, and must not be issued');
		$this->assertStringContainsString('at least one verb', (string)$refusal);
	}//end testAnEmptyVerbListIsRefused()

	/**
	 * 🔴 `manage` cannot be granted to a token at all.
	 *
	 * @return void
	 */
	public function testManageCannotBeGrantedEvenByAnIssuerWhoHoldsIt(): void {
		$grant = $this->acceptable();
		$grant['verbs'] = ['read', 'manage'];

		$refusal = $this->validator()->refusalFor(
			grant: $grant,
			issuerVerbs: ['read', 'list', 'create', 'update', 'delete', 'manage'],
			now: $this->now()
		);

		$this->assertNotNull($refusal, 'a machine principal that can widen its own audience is the failure scoping prevents');
		$this->assertStringContainsString('manage', (string)$refusal);
	}//end testManageCannotBeGrantedEvenByAnIssuerWhoHoldsIt()

	/**
	 * 🔴 An issuer cannot mint a verb they do not hold.
	 *
	 * @return void
	 */
	public function testAnIssuerCannotMintAVerbTheyLack(): void {
		$grant = $this->acceptable();
		$grant['verbs'] = ['read', 'delete'];

		$refusal = $this->validator()->refusalFor(grant: $grant, issuerVerbs: ['read', 'list'], now: $this->now());

		$this->assertNotNull($refusal, 'a grant is a filter over the issuer\'s rights, never an addition to them');
		$this->assertStringContainsString('wider than the issuer', (string)$refusal);
	}//end testAnIssuerCannotMintAVerbTheyLack()

	/**
	 * A verb this instance does not know is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownVerbIsRefused(): void {
		$grant = $this->acceptable();
		$grant['verbs'] = ['reed'];

		$this->assertNotNull(
			$this->validator()->refusalFor(grant: $grant, issuerVerbs: ['reed', 'read'], now: $this->now()),
			'a typo must not become a permission'
		);
	}//end testAnUnknownVerbIsRefused()

	/**
	 * 🔴 A token with no end date is not issued (C40.1).
	 *
	 * @return void
	 */
	public function testATokenWithNoEndDateIsNotIssued(): void {
		$grant = $this->acceptable();
		unset($grant['expiresAt']);

		$refusal = $this->validator()->refusalFor(grant: $grant, issuerVerbs: ['read', 'list'], now: $this->now());

		$this->assertNotNull($refusal, 'an optional expiry is an expiry nobody sets');
		$this->assertStringContainsString('end date', (string)$refusal);
	}//end testATokenWithNoEndDateIsNotIssued()

	/**
	 * An end date already in the past is refused at issue.
	 *
	 * @return void
	 */
	public function testAnEndDateInThePastIsRefused(): void {
		$grant = $this->acceptable();
		$grant['expiresAt'] = '2020-01-01T00:00:00+00:00';

		$this->assertNotNull(
			$this->validator()->refusalFor(grant: $grant, issuerVerbs: ['read', 'list'], now: $this->now()),
			'issuing a token that is already lapsed is a confusing way to issue nothing'
		);
	}//end testAnEndDateInThePastIsRefused()

	/**
	 * 🔴 A present-but-empty scope axis is refused, because it reads both ways.
	 *
	 * @return void
	 */
	public function testAPresentButEmptyScopeAxisIsRefused(): void {
		$grant = $this->acceptable();
		$grant['registers'] = [];

		$refusal = $this->validator()->refusalFor(grant: $grant, issuerVerbs: ['read', 'list'], now: $this->now());

		$this->assertNotNull($refusal, 'an empty list reads as "none" and as "all", and a form produces it by accident');
		$this->assertStringContainsString('registers', (string)$refusal);
	}//end testAPresentButEmptyScopeAxisIsRefused()

	/**
	 * A holder is warned before the token lapses (C40.2).
	 *
	 * @return void
	 */
	public function testAHolderIsWarnedBeforeTheTokenLapses(): void {
		$soon = new TokenGrant(verbs: ['read'], expiresAt: new DateTimeImmutable('2026-09-25T12:00:00+00:00'));
		$later = new TokenGrant(verbs: ['read'], expiresAt: new DateTimeImmutable('2027-09-25T12:00:00+00:00'));

		$this->assertTrue($this->validator()->lapsesSoon(grant: $soon, now: $this->now()));
		$this->assertFalse($this->validator()->lapsesSoon(grant: $later, now: $this->now()));
	}//end testAHolderIsWarnedBeforeTheTokenLapses()

	/**
	 * 🔴 A malformed grant permits nothing; an absent one narrows nothing.
	 *
	 * @return void
	 */
	public function testAMalformedGrantIsNotAnAbsentOne(): void {
		$this->assertNull(TokenGrant::fromStored(stored: null), 'absent means the token carries its holder\'s rights');

		$broken = TokenGrant::fromStored(stored: 'this is not a grant');
		$this->assertNotNull($broken, 'a malformed grant is a grant, not an absence');
		$this->assertTrue($broken->isEmpty(), 'and it permits nothing, so a typo cannot become an escalation');
	}//end testAMalformedGrantIsNotAnAbsentOne()

	/**
	 * An absent scope axis does not narrow; a present one is a closed list.
	 *
	 * @return void
	 */
	public function testAnAbsentAxisDoesNotNarrowAndAPresentOneIsClosed(): void {
		$unscoped = new TokenGrant(verbs: ['read'], expiresAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00'));
		$this->assertTrue($unscoped->covers(schemaSlug: 'anything', registerSlug: 'anywhere'));

		$scoped = new TokenGrant(
			verbs: ['read'],
			schemas: ['zaak'],
			expiresAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00')
		);
		$this->assertTrue($scoped->covers(schemaSlug: 'zaak', registerSlug: 'anywhere'));
		$this->assertFalse($scoped->covers(schemaSlug: 'persoon', registerSlug: 'anywhere'));
	}//end testAnAbsentAxisDoesNotNarrowAndAPresentOneIsClosed()

	/**
	 * A grant with no end date reads as expired, rather than as never expiring.
	 *
	 * @return void
	 */
	public function testAGrantWithNoEndDateReadsAsExpired(): void {
		$grant = new TokenGrant(verbs: ['read']);

		$this->assertTrue(
			$grant->isExpired(now: $this->now()),
			'"no end date" and "never expires" are the same string and opposite facts'
		);
	}//end testAGrantWithNoEndDateReadsAsExpired()

	/**
	 * 🔴 The narrowed block is never EMPTY, because an empty block is open.
	 *
	 * @return void
	 */
	public function testAFullyRefusedGrantProducesAClosedBlockNotAnEmptyOne(): void {
		$narrower = new TokenGrantNarrower();
		$expired = new TokenGrant(
			verbs: ['read'],
			expiresAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
			tokenId: 'leverancier'
		);

		$block = $narrower->narrow(
			authorization: null,
			grant: $expired,
			schemaSlug: 'zaak',
			registerSlug: 'zaken',
			now: $this->now()
		);

		$this->assertNotEmpty($block, 'an empty block is read as "no rules configured" and grants everything');
		$this->assertFalse(
			$narrower->markerPermits(authorization: $block, action: 'read'),
			'an expired token reads nothing'
		);
	}//end testAFullyRefusedGrantProducesAClosedBlockNotAnEmptyOne()

	/**
	 * 🔴 The least privileged principal that should be refused: a supplier's
	 * read-only token asking to write.
	 *
	 * @return void
	 */
	public function testAReadOnlyTokenIsRefusedAWrite(): void {
		$narrower = new TokenGrantNarrower();
		$grant = new TokenGrant(
			verbs: ['read', 'list'],
			schemas: ['zaak'],
			expiresAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
			tokenId: 'leverancier'
		);

		$block = $narrower->narrow(
			authorization: ['read' => ['medewerkers'], 'update' => ['medewerkers']],
			grant: $grant,
			schemaSlug: 'zaak',
			registerSlug: 'zaken',
			now: $this->now()
		);

		$this->assertTrue($narrower->markerPermits(authorization: $block, action: 'read'));
		$this->assertFalse(
			$narrower->markerPermits(authorization: $block, action: 'update'),
			'a token whose grant does not name update must be refused it, whatever its holder may do'
		);
		$this->assertSame(
			[TokenGrantNarrower::IMPOSSIBLE],
			$block['update'],
			'and the refusal is written as a rule that cannot match, never as a deleted key'
		);
	}//end testAReadOnlyTokenIsRefusedAWrite()

	/**
	 * A schema outside the grant's scope is refused entirely.
	 *
	 * @return void
	 */
	public function testASchemaOutsideTheScopeIsRefusedEntirely(): void {
		$narrower = new TokenGrantNarrower();
		$grant = new TokenGrant(
			verbs: ['read'],
			schemas: ['zaak'],
			expiresAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00')
		);

		$block = $narrower->narrow(
			authorization: ['read' => ['medewerkers']],
			grant: $grant,
			schemaSlug: 'persoon',
			registerSlug: 'zaken',
			now: $this->now()
		);

		$this->assertFalse(
			$narrower->markerPermits(authorization: $block, action: 'read'),
			'a schema the grant does not name is out of scope whatever the holder may do'
		);
	}//end testASchemaOutsideTheScopeIsRefusedEntirely()

	/**
	 * A session request, with no token, is not narrowed at all.
	 *
	 * The mirror of the refusals above: a narrower that refused everything
	 * would pass every one of them and fail this.
	 *
	 * @return void
	 */
	public function testASessionRequestIsNotNarrowed(): void {
		$narrower = new TokenGrantNarrower();
		$block = ['read' => ['medewerkers'], 'update' => ['medewerkers']];

		$result = $narrower->narrow(
			authorization: $block,
			grant: null,
			schemaSlug: 'zaak',
			registerSlug: 'zaken',
			now: $this->now()
		);

		$this->assertSame($block, $result, 'a person calling with no token keeps exactly the rules they had');
		$this->assertTrue($narrower->markerPermits(authorization: $result, action: 'update'));
	}//end testASessionRequestIsNotNarrowed()

	/**
	 * 🔴 A marker declared by a schema cannot stand in for a real grant.
	 *
	 * @return void
	 */
	public function testASchemaDeclaredMarkerIsIgnored(): void {
		$narrower = new TokenGrantNarrower();
		$forged = [
			'read' => ['medewerkers'],
			TokenGrantNarrower::MARKER => ['verbs' => ['read', 'update', 'delete']],
		];

		$withoutToken = $narrower->narrow(
			authorization: $forged,
			grant: null,
			schemaSlug: 'zaak',
			registerSlug: 'zaken',
			now: $this->now()
		);
		$this->assertArrayNotHasKey(
			TokenGrantNarrower::MARKER,
			$withoutToken,
			'a marker a schema wrote must not be read as a grant somebody holds'
		);

		$withToken = $narrower->narrow(
			authorization: $forged,
			grant: new TokenGrant(
				verbs: ['read'],
				expiresAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00')
			),
			schemaSlug: 'zaak',
			registerSlug: 'zaken',
			now: $this->now()
		);
		$this->assertFalse(
			$narrower->markerPermits(authorization: $withToken, action: 'delete'),
			'and the real grant overwrites it rather than merging with it'
		);
	}//end testASchemaDeclaredMarkerIsIgnored()

	/**
	 * 🔴 An unreadable marker refuses, rather than falling open.
	 *
	 * @return void
	 */
	public function testAnUnreadableMarkerRefuses(): void {
		$narrower = new TokenGrantNarrower();

		$this->assertFalse(
			$narrower->markerPermits(
				authorization: [TokenGrantNarrower::MARKER => ['verbs' => 'not a list']],
				action: 'read'
			),
			'a narrowing that cannot be applied is a refusal, never an opening'
		);
	}//end testAnUnreadableMarkerRefuses()

	/**
	 * 🔴 The marker is a control key, so a narrowed block still saves.
	 *
	 * This is the check the `matrix` defect taught: a key in an authorization
	 * block that is not a control key is read as a verb, and the block is then
	 * refused at save with "unknown verb".
	 *
	 * @return void
	 */
	public function testTheMarkerIsAControlKeyAndNotAVerb(): void {
		$this->assertContains(
			TokenGrantNarrower::MARKER,
			PermissionCatalogue::CONTROL_KEYS,
			'a marker missing from CONTROL_KEYS makes every narrowed block unsaveable'
		);

		$catalogue = new PermissionCatalogue();
		$this->assertSame(
			[],
			$catalogue->unknownVerbsIn([TokenGrantNarrower::MARKER => ['verbs' => ['read']], 'read' => ['x']]),
			'and the catalogue must agree that it is not a verb'
		);
	}//end testTheMarkerIsAControlKeyAndNotAVerb()

	/**
	 * The source is bound explicitly, so "a person" and "a token with no
	 * grant" stay distinguishable.
	 *
	 * @return void
	 */
	public function testTheSourceDistinguishesUnboundFromUngranted(): void {
		$source = new TokenGrantSource();
		$this->assertFalse($source->isBound(), 'a session request binds nothing');
		$this->assertNull($source->current());

		$source->bindFromConsumer(storedAuthorization: ['publicKey' => 'x'], tokenId: 'leverancier');
		$this->assertTrue($source->isBound(), 'a machine principal binds even when it carries no grant');
		$this->assertNull($source->current(), 'and an unscoped Consumer keeps its holder\'s rights, as today');
	}//end testTheSourceDistinguishesUnboundFromUngranted()

	/**
	 * A Consumer carrying a grant binds it.
	 *
	 * @return void
	 */
	public function testAConsumerCarryingAGrantBindsIt(): void {
		$source = new TokenGrantSource();
		$source->bindFromConsumer(
			storedAuthorization: [
				'publicKey' => 'x',
				TokenGrant::KEY => [
					'verbs' => ['read'],
					'schemas' => ['zaak'],
					'expiresAt' => '2026-11-01T00:00:00+00:00',
				],
			],
			tokenId: 'leverancier'
		);

		$grant = $source->current();
		$this->assertNotNull($grant);
		$this->assertSame(['read'], $grant->verbs);
		$this->assertSame('leverancier', $grant->tokenId, 'so a write can record which token made it');
	}//end testAConsumerCarryingAGrantBindsIt()
}//end class
