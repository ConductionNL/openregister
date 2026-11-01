<?php

/**
 * Tests for the access links and grants an example set declares: minted and
 * shared through the real services as a cast account, recorded without the
 * anchor, kept on a second install, made again on a refresh, removed exactly,
 * and refused by the guards like any other caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use ArrayObject;
use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetActingAs;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\ShareTimeWriter;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\NullLogger;

/**
 * The `accessLinks` and `grants` parts of an example set.
 */
class ExampleSetAccessLinksAndGrantsTest extends ExampleSetTestCase {

	/** @var array<int, AccessLink> The access link rows, by id. */
	private array $links = [];

	/** @var array<string, ArrayObject> The shares, by full id: each one's fields. */
	private array $shares = [];

	/** @var IUser|null The active user of the fake session. */
	private ?IUser $activeUser = null;

	/** @var array<int, string|null> The active user each mint guard call saw. */
	private array $guardSaw = [];

	/** @var array<int, string|null> The active user each share creation saw. */
	private array $sharedAs = [];

	/** @var array<int, string> The accounts the mint guard lets read the case. */
	private array $readers = ['esra.demir'];

	/** @var array<int, string> The accounts in the admin group. */
	private array $admins = [];

	/** @var IUserSession The fake session. */
	private IUserSession $session;

	/** @var IUserManager The accounts. */
	private IUserManager $users;

	/** @var array<int, string> The accounts the sharing guard looked up, in order. */
	private array $groupsAskedFor = [];

	/** @var int The shares created so far. */
	private int $sharesMade = 0;

	/** @var int The anchors handed out so far. */
	private int $anchors = 0;

	/**
	 * The cast, the dossiq schemas and the services the parts reach through the container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'dossiq', schema: 'case');
		$this->store->offer(register: 'dossiq', schema: 'caseShare');
		$this->app(app: 'openregister', fragment: $this->castFile());

		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->activeUser);
		$this->session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->activeUser = $user;
		});
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->accounts->userExists(uid: $uid) === true ? $this->user(uid: $uid) : null));

		$mapper = $this->linkMapper();
		$this->services = [
			ExampleSetActingAs::class   => new ExampleSetActingAs(userSession: $this->session, userManager: $this->users, cli: true),
			AccessLinkMapper::class     => $mapper,
			AccessLinkService::class    => $this->linkService(mapper: $mapper),
			AccessLinkMintGuard::class  => $this->mintGuard(),
			ObjectSharingService::class => $this->sharingService(session: $this->session),
			IManager::class             => $this->shareManager(),
		];
	}//end setUp()

	/**
	 * Sanne's read-only link: minted by Esra through the link service, once the mint guard admitted Esra, recorded
	 * by id and uuid without its anchor, and the session is empty again afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-sannes-read-only-link
	 */
	public function testALinkIsMintedAsItsAccountAndRecordedWithoutItsAnchor(): void {
		$this->caseWith(links: [$this->sanneLink()]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->links, message: 'the check mints nothing');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertCount(expectedCount: 1, haystack: $this->links);
		$link = array_values($this->links)[0];
		$this->assertSame(expected: 'esra.demir', actual: $link->getCreatedBy());
		$this->assertSame(expected: 'Sanne de Vries', actual: $link->getLabel());
		$this->assertSame(expected: 'read', actual: $link->getCapabilities());
		$this->assertSame(expected: '2036-10-10 23:59', actual: $link->getExpiresAt()?->format('Y-m-d H:i'));
		$this->assertSame(expected: $this->caseId(), actual: $link->getSubjectId());
		$this->assertSame(expected: ['esra.demir'], actual: $this->guardSaw, message: 'the mint guard decided for Esra');
		$this->assertNull(actual: $this->activeUser, message: 'nobody is left signed in');

		$entry = $this->record->read(set: 'demo')['accessLinks']['alpha/link-sanne'];
		$this->assertSame(expected: ['id' => $link->getId(), 'uuid' => $link->getUuid(), 'object' => $this->caseId()], actual: $entry);
		$this->assertStringNotContainsString(needle: (string) $link->getAnchor(), haystack: (string) json_encode($this->config));
		$this->assertStringNotContainsString(needle: (string) $link->getAnchor(), haystack: (string) json_encode($report));
	}//end testALinkIsMintedAsItsAccountAndRecordedWithoutItsAnchor()

	/**
	 * A second install mints nothing; a revoked link is reported missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function testASecondInstallMintsNothingAndARevokedLinkIsMissing(): void {
		$this->caseWith(links: [$this->sanneLink()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $again['ok'], message: (string) json_encode($again));
		$this->assertSame(expected: ['alpha/link-sanne' => 'kept'], actual: $again['accessLinks']);
		$this->assertCount(expectedCount: 1, haystack: $this->links);

		array_values($this->links)[0]->setRevokedAt(new DateTime());
		$revoked = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertContains(needle: 'access link alpha/link-sanne', haystack: $revoked['missing']);
		$this->assertCount(expectedCount: 1, haystack: $this->links, message: 'without --refresh nothing is minted again');
	}//end testASecondInstallMintsNothingAndARevokedLinkIsMissing()

	/**
	 * A refresh deletes the held link and mints it again, ending a span after the refresh day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-a-link-revoked-during-a-demonstration
	 */
	public function testARefreshMintsTheLinkAgainFromTheRefreshDay(): void {
		$this->caseWith(links: [$this->sanneLink()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$old = array_values($this->links)[0];
		$old->setRevokedAt(new DateTime());

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+5 days'));
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/link-sanne' => 'refreshed'], actual: $report['accessLinks']);
		$this->assertCount(expectedCount: 1, haystack: $this->links, message: 'the old row is deleted, one live link stands');
		$new = array_values($this->links)[0];
		$this->assertNotSame(expected: $old->getId(), actual: $new->getId());
		$this->assertNull(actual: $new->getRevokedAt());
		$this->assertSame(expected: '2036-10-15 23:59', actual: $new->getExpiresAt()?->format('Y-m-d H:i'));
		$this->assertSame(expected: $new->getId(), actual: $this->record->read(set: 'demo')['accessLinks']['alpha/link-sanne']['id']);
	}//end testARefreshMintsTheLinkAgainFromTheRefreshDay()

	/**
	 * Remove deletes the link rows the record holds, and a link minted outside the set stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-removing-the-set
	 */
	public function testRemoveDeletesExactlyTheHeldLinks(): void {
		$this->caseWith(links: [$this->sanneLink()]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$outside = $this->services[AccessLinkService::class]->mint(
			userId: 'esra.demir',
			subjectType: 'object',
			subjectId: $this->caseId(),
			expiresAt: '+10 years'
		);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: 1, actual: $removed['removed']['accessLinks'], message: (string) json_encode($removed));
		$this->assertSame(expected: [(int) $outside['id']], actual: array_keys($this->links));
	}//end testRemoveDeletesExactlyTheHeldLinks()

	/**
	 * A change names the minted link on the share record; the record keeps the address only as a fingerprint, the
	 * proof still reads it, and a refresh points the record at the new link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-dossiqs-link-record-names-its-link
	 */
	public function testAChangeQuotesTheMintedLink(): void {
		$this->caseWith(links: [$this->sanneLink()], share: true);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$link  = array_values($this->links)[0];
		$share = $this->shareRow();
		$this->assertSame(expected: $link->getId(), actual: $share['accessLinkId']);
		$this->assertSame(expected: $link->getUuid(), actual: $share['accessLinkUuid']);
		$this->assertSame(expected: 'https://cloud.example.test/index.php/apps/openregister/api/access-links/'.$link->getAnchor(), actual: $share['accessLinkUrl']);
		$this->assertStringNotContainsString(needle: (string) $link->getAnchor(), haystack: (string) json_encode($this->config));

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $again['ok'], message: 'the proof reads the fingerprinted address: '.json_encode($again));

		$this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());
		$fresh = array_values($this->links)[0];
		$this->assertNotSame(expected: $link->getId(), actual: $fresh->getId());
		$this->assertSame(expected: $fresh->getId(), actual: $this->shareRow()['accessLinkId']);
		$this->assertStringEndsWith(suffix: (string) $fresh->getAnchor(), string: $this->shareRow()['accessLinkUrl']);
	}//end testAChangeQuotesTheMintedLink()

	/**
	 * A change that quotes a link the fragment does not declare is refused by the check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function testACheckRefusesAnUndeclaredLink(): void {
		$this->caseWith(links: [], share: true);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertNotSame(expected: [], actual: $refusals);
		$this->assertStringContainsString(needle: 'accessLink:link-sanne.id', haystack: implode("\n", $refusals));
	}//end testACheckRefusesAnUndeclaredLink()

	/**
	 * The check refuses every row the design names, each with its file and key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function testTheCheckRefusesEveryBadRow(): void {
		$link  = $this->sanneLink()['data'];
		$grant = $this->staffGrant()['data'];
		$this->caseWith(
			links: [
				['key' => 'extra-field', 'data' => $link + ['password' => 'x']],
				['key' => 'no-expiry', 'data' => array_diff_key($link, ['expiresAt' => true])],
				['key' => 'expired', 'data' => ['expiresAt' => '{{date:-1}}'] + $link],
				['key' => 'bad-capability', 'data' => ['capabilities' => ['delete']] + $link],
				['key' => 'stranger', 'data' => ['createdBy' => 'admin'] + $link],
				['key' => 'not-ours', 'data' => ['object' => '0a0b0c0d-0000-4000-8000-000000000000'] + $link],
			],
			grants: [
				['key' => 'remote', 'data' => ['type' => 'remote'] + $grant],
				['key' => 'foreign-group', 'data' => ['shareWith' => 'admin'] + $grant],
				['key' => 'too-wide', 'data' => ['permissions' => 40] + $grant],
				['key' => 'stranger-shares', 'data' => ['sharedBy' => 'admin'] + $grant],
				['key' => 'grant-not-ours', 'data' => ['object' => '0a0b0c0d-0000-4000-8000-000000000000'] + $grant],
			]
		);

		$refusals = implode("\n", $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals']);
		foreach ([
			'access link "extra-field": field "password"',
			'access link "no-expiry": an access link needs an expiry',
			'access link "expired": expiresAt',
			'access link "bad-capability": capability "delete"',
			'access link "stranger": createdBy',
			'access link "not-ours": object',
			'grant "remote": type',
			'grant "foreign-group": shareWith',
			'grant "too-wide": permissions',
			'grant "stranger-shares": sharedBy',
			'grant "grant-not-ours": object',
		] as $expected) {
			$this->assertStringContainsString(needle: 'demo.json: '.$expected, haystack: $refusals);
		}
	}//end testTheCheckRefusesEveryBadRow()

	/**
	 * The Juridische zaken grant: a group share made by its declared account through the sharing service, with its
	 * expiry and its share time, recorded, kept on a second install, missing once deleted, made again on a refresh
	 * and removed exactly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-juridische-zaken-shared-by-pieter
	 */
	public function testAGrantIsMadeAsItsAccountAndKeptRefreshedAndRemoved(): void {
		$this->caseWith(grants: [$this->staffGrant()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertCount(expectedCount: 1, haystack: $this->shares);
		$share = array_values($this->shares)[0];
		$this->assertSame(expected: 'esra.demir', actual: $share['sharedBy']);
		$this->assertSame(expected: IShare::TYPE_GROUP, actual: $share['shareType']);
		$this->assertSame(expected: 'demo-staff', actual: $share['sharedWith']);
		$this->assertSame(expected: '2026-10-27', actual: $share['expiration']->format('Y-m-d'));
		$this->assertSame(expected: '2026-09-17 10:00', actual: $share['shareTime']->format('Y-m-d H:i'));
		$this->assertSame(expected: ['esra.demir'], actual: $this->sharedAs);
		$this->assertNull(actual: $this->activeUser);
		$first = array_keys($this->shares)[0];
		$this->assertSame(expected: ['id' => $first, 'object' => $this->caseId()], actual: $this->record->read(set: 'demo')['grants']['alpha/grant-staff']);

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['alpha/grant-staff' => 'kept'], actual: $again['grants']);
		$this->assertCount(expectedCount: 1, haystack: $this->shares);

		unset($this->shares[$first]);
		$this->assertContains(needle: 'grant alpha/grant-staff', haystack: $this->installer->install(plan: $plan, today: $this->today())['missing']);

		$refreshed = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());
		$this->assertSame(expected: ['alpha/grant-staff' => 'refreshed'], actual: $refreshed['grants']);
		$this->assertCount(expectedCount: 1, haystack: $this->shares);
		$this->assertNotSame(expected: $first, actual: array_keys($this->shares)[0]);

		$this->shares['ocinternal:999'] = new ArrayObject(['id' => 999, 'sharedWith' => 'someone']);
		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: 1, actual: $removed['removed']['grants']);
		$this->assertSame(expected: ['ocinternal:999'], actual: array_keys($this->shares), message: 'only the held share goes');
	}//end testAGrantIsMadeAsItsAccountAndKeptRefreshedAndRemoved()

	/**
	 * A grant by an account that may not share the case is reported with the guard's reason; the rest of the set
	 * is installed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#scenario-an-account-that-may-not-share
	 */
	public function testAGrantTheGuardRefusesIsReportedAndTheSetGoesOn(): void {
		$this->caseWith(links: [$this->sanneLink()], grants: [['key' => 'grant-staff', 'data' => ['sharedBy' => '{{cast:sanne.uid}}'] + $this->staffGrant()['data']]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertSame(expected: 'failed', actual: $report['grants']['alpha/grant-staff']);
		$this->assertStringContainsString(
			needle: 'grant "grant-staff": Only the owner or an administrator may change sharing on this object',
			haystack: implode("\n", $report['dropped'])
		);
		$this->assertSame(expected: [], actual: $this->shares);
		$this->assertCount(expectedCount: 1, haystack: $this->links, message: 'the link of the same fragment is minted');
		$this->assertSame(expected: [], actual: $this->sharedAs, message: 'nothing reached the share manager');
		$this->assertSame(expected: ['sanne.devries'], actual: $this->groupsAskedFor, message: 'the guard decided for Sanne');
		$this->assertNull(actual: $this->activeUser);
	}//end testAGrantTheGuardRefusesIsReportedAndTheSetGoesOn()

	/**
	 * A link the mint guard refuses (the account may not read the case) is reported, never minted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	public function testALinkTheMintGuardRefusesIsReported(): void {
		$this->readers = [];
		$this->caseWith(links: [$this->sanneLink()]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertSame(expected: 'failed', actual: $report['accessLinks']['alpha/link-sanne']);
		$this->assertStringContainsString(needle: 'may not publish it at a link', haystack: implode("\n", $report['dropped']));
		$this->assertSame(expected: [], actual: $this->links);
	}//end testALinkTheMintGuardRefusesIsReported()

	/**
	 * Two grants by different accounts in one daily refresh outside the command line: each is made as its own
	 * account, and no session is left behind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
	 */
	public function testTwoGrantsByTwoAccountsInOneBackgroundRunLeaveNoSession(): void {
		$this->services[ExampleSetActingAs::class] = new ExampleSetActingAs(userSession: $this->session, userManager: $this->users, cli: false);
		$this->admins = ['sanne.devries'];
		$this->caseWith(grants: [
			$this->staffGrant(),
			['key' => 'grant-sanne', 'data' => ['type' => 'user', 'shareWith' => '{{cast:esra.uid}}', 'sharedBy' => '{{cast:sanne.uid}}'] + $this->staffGrant()['data']],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$refused = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertStringContainsString(needle: 'only from occ or the background job', haystack: implode("\n", $refused['dropped']));
		$this->assertSame(expected: [], actual: $this->shares, message: 'outside occ and the job nothing is shared');

		$report = ExampleSetActingAs::inBackgroundJob(run: fn (): array => $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()));
		$this->assertSame(expected: ['alpha/grant-staff' => 'created', 'alpha/grant-sanne' => 'created'], actual: $report['grants'], message: (string) json_encode($report));
		$this->assertSame(expected: ['esra.demir', 'sanne.devries'], actual: $this->sharedAs);
		$this->assertNull(actual: $this->activeUser, message: 'no session is left behind');
	}//end testTwoGrantsByTwoAccountsInOneBackgroundRunLeaveNoSession()

	/**
	 * The acting scope refuses while somebody is signed in, refuses an unknown account, and puts the previous user
	 * back when the call throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function testTheActingScopeIsGuardedAndAlwaysRestores(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->activeUser);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->activeUser = $user;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'esra.demir' ? $this->user(uid: $uid) : null));
		$scope = new ExampleSetActingAs(userSession: $session, userManager: $users, cli: true);

		$this->activeUser = $this->user(uid: 'clicker');
		try {
			$scope->run(uid: 'esra.demir', operation: static fn (): bool => true);
			$this->fail('a signed-in request must not act as another account');
		} catch (\OCA\OpenRegister\Service\ExampleSet\ExampleSetException $e) {
			$this->assertStringContainsString(needle: '"clicker" is signed in', haystack: $e->getMessage());
		}

		$this->assertSame(expected: 'clicker', actual: $this->activeUser->getUID());

		$this->activeUser = null;
		try {
			$scope->run(uid: 'nobody', operation: static fn (): bool => true);
			$this->fail('an unknown account is refused');
		} catch (\OCA\OpenRegister\Service\ExampleSet\ExampleSetException $e) {
			$this->assertStringContainsString(needle: 'no account "nobody"', haystack: $e->getMessage());
		}

		try {
			$scope->run(uid: 'esra.demir', operation: function (): void {
				$this->assertSame(expected: 'esra.demir', actual: $this->activeUser?->getUID());
				throw new \RuntimeException('boom');
			});
		} catch (\RuntimeException) {
			$this->assertNull(actual: $this->activeUser, message: 'restored after a throw');
		}

		$this->assertNull(actual: $this->activeUser);
		$web = new ExampleSetActingAs(userSession: $session, userManager: $users, cli: false);
		$this->expectExceptionMessage('only from occ or the background job');
		$web->run(uid: 'esra.demir', operation: static fn (): bool => true);
	}//end testTheActingScopeIsGuardedAndAlwaysRestores()

	/**
	 * Install a fragment with a case owned by Esra, optionally a share record of type link and a change naming
	 * its link, and the given links and grants.
	 *
	 * @param array<int, array<string, mixed>> $links  The access links.
	 * @param array<int, array<string, mixed>> $grants The grants.
	 * @param bool                             $share  Whether to add the share record and the change.
	 * @param string                           $owner  The case's owner.
	 *
	 * @return void
	 */
	private function caseWith(array $links = [], array $grants = [], bool $share = false, string $owner = '{{cast:esra.uid}}'): void {
		$objects = [['key' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'owner' => $owner, 'data' => ['title' => 'Verlichting fietspad Lindelaan']]];
		$changes = [];
		if ($share === true) {
			$objects[] = ['key' => 'share-sanne', 'register' => 'dossiq', 'schema' => 'caseShare', 'data' => [
				'caseId'    => '{{object:case.id}}',
				'shareType' => 'link',
				'label'     => '{{cast:sanne.displayName}}',
			]];
			$changes[] = ['key' => 'share-sanne-names-its-link', 'register' => 'dossiq', 'schema' => 'caseShare', 'object' => 'share-sanne', 'set' => [
				'accessLinkId'   => '{{accessLink:link-sanne.id}}',
				'accessLinkUuid' => '{{accessLink:link-sanne.uuid}}',
				'accessLinkUrl'  => '{{accessLink:link-sanne.url}}',
			]];
		}

		$this->app(app: 'alpha', fragment: array_filter([
			'objects'     => $objects,
			'changes'     => $changes,
			'accessLinks' => $links,
			'grants'      => $grants,
		]));
	}//end caseWith()

	/**
	 * Sanne's link, read only, minted by Esra, ending ten years after the install day at 23:59.
	 *
	 * @return array{key: string, data: array<string, mixed>}
	 */
	private function sanneLink(): array {
		return ['key' => 'link-sanne', 'data' => [
			'object'       => '{{object:case.id}}',
			'capabilities' => ['read'],
			'label'        => '{{cast:sanne.displayName}}',
			'createdBy'    => '{{cast:esra.uid}}',
			'expiresAt'    => '{{datetime:3650 23:59}}',
		]];
	}//end sanneLink()

	/**
	 * The staff group's grant, shared by Esra 26 days before the install day, ending 14 days after it.
	 *
	 * @return array{key: string, data: array<string, mixed>}
	 */
	private function staffGrant(): array {
		return ['key' => 'grant-staff', 'data' => [
			'object'      => '{{object:case.id}}',
			'type'        => 'group',
			'shareWith'   => '{{cast:staff.gid}}',
			'permissions' => 1,
			'sharedBy'    => '{{cast:esra.uid}}',
			'sharedAt'    => '{{datetime:-26 10:00}}',
			'expiresAt'   => '{{date:14}}',
		]];
	}//end staffGrant()

	/**
	 * The id of the case the set wrote.
	 *
	 * @return string
	 */
	private function caseId(): string {
		return (string) $this->record->read(set: 'demo')['objects']['alpha/case']['id'];
	}//end caseId()

	/**
	 * The share record as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function shareRow(): array {
		$id = (string) $this->record->read(set: 'demo')['objects']['alpha/share-sanne']['id'];
		return (array) $this->store->get(register: 'dossiq', schema: 'caseShare', id: $id);
	}//end shareRow()

	/**
	 * An account.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A link mapper over $links.
	 *
	 * @return AccessLinkMapper
	 */
	private function linkMapper(): AccessLinkMapper {
		$mapper = $this->createMock(AccessLinkMapper::class);
		$next   = 0;
		$mapper->method('insert')->willReturnCallback(function (AccessLink $link) use (&$next): AccessLink {
			$link->setId(++$next + 100);
			$this->links[$link->getId()] = $link;
			return $link;
		});
		$mapper->method('findById')->willReturnCallback(fn (int $id): ?AccessLink => ($this->links[$id] ?? null));
		$mapper->method('delete')->willReturnCallback(function (AccessLink $link): AccessLink {
			unset($this->links[$link->getId()]);
			return $link;
		});

		return $mapper;
	}//end linkMapper()

	/**
	 * The real link service over the fake mapper.
	 *
	 * @param AccessLinkMapper $mapper The mapper.
	 *
	 * @return AccessLinkService
	 */
	private function linkService(AccessLinkMapper $mapper): AccessLinkService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (): string => 'anchor'.str_pad((string) ++$this->anchors, 37, 'x'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/index.php/apps/openregister/'.($route === 'openregister.accessLink.open' ? 'api/access-links/' : 'link/').$params['anchor']
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.test'.$path);

		return new AccessLinkService(
			mapper: $mapper,
			auditTrail: $this->createMock(AuditTrailMapper::class),
			secureRandom: $random,
			hasher: $this->createMock(IHasher::class),
			urlGenerator: $urls,
			logger: new NullLogger()
		);
	}//end linkService()

	/**
	 * A mint guard that admits the accounts in $readers, and notes who asked.
	 *
	 * @return AccessLinkMintGuard
	 */
	private function mintGuard(): AccessLinkMintGuard {
		$guard = $this->createMock(AccessLinkMintGuard::class);
		$guard->method('mayMint')->willReturnCallback(function (): bool {
			$uid = $this->activeUser?->getUID();
			$this->guardSaw[] = $uid;
			return in_array($uid, $this->readers, true);
		});

		return $guard;
	}//end mintGuard()

	/**
	 * The real sharing service with the real grant guard: the owner (or an administrator) may share.
	 *
	 * @param IUserSession $session The session.
	 *
	 * @return ObjectSharingService
	 */
	private function sharingService(IUserSession $session): ObjectSharingService {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(55);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($folder);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(function (IUser $user): array {
			$this->groupsAskedFor[] = $user->getUID();
			return (in_array($user->getUID(), $this->admins, true) === true ? ['admin'] : []);
		});
		$times = $this->createMock(ShareTimeWriter::class);
		$times->method('stamp')->willReturnCallback(function (int $shareId, \DateTimeInterface $moment): int {
			$this->shares['ocinternal:'.$shareId]['shareTime'] = DateTime::createFromInterface($moment);
			return 1;
		});

		return new ObjectSharingService(
			authorizationWriter: $this->createMock(ObjectAuthorizationWriter::class),
			userSession: $session,
			groupManager: $groups,
			folders: $folders,
			scopeResolver: new ObjectScopeResolver(),
			grantResolver: $this->createMock(ObjectGrantResolver::class),
			shareManager: $this->shareManager(),
			logger: new NullLogger(),
			inheritedGrants: $this->createMock(InheritedGrantLister::class),
			grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class)),
			shareTimes: $times
		);
	}//end sharingService()

	/**
	 * A share manager over $shares that stamps a new share with the current time, as core does.
	 *
	 * @return IManager
	 */
	private function shareManager(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturnCallback(fn (): IShare => $this->share(fields: new ArrayObject()));
		$manager->method('createShare')->willReturnCallback(function (IShare $share): IShare {
			$fields = $this->fieldsOf[spl_object_id($share)];
			$fields['id']        = ++$this->sharesMade;
			$fields['shareTime'] = new DateTime();
			$this->sharedAs[]    = $this->activeUser?->getUID();
			$this->shares['ocinternal:'.$fields['id']] = $fields;
			return $share;
		});
		$manager->method('getShareById')->willReturnCallback(function (string $id): IShare {
			if (isset($this->shares[$id]) === false) {
				throw new ShareNotFound();
			}

			return $this->share(fields: $this->shares[$id]);
		});
		$manager->method('deleteShare')->willReturnCallback(function (IShare $share): void {
			unset($this->shares['ocinternal:'.$this->fieldsOf[spl_object_id($share)]['id']]);
		});

		return $manager;
	}//end shareManager()

	/** @var array<int, ArrayObject> The fields behind each fake share, by object id. */
	private array $fieldsOf = [];

	/**
	 * A share whose setters and getters work on $fields.
	 *
	 * @param ArrayObject $fields The share's fields.
	 *
	 * @return IShare
	 */
	private function share(ArrayObject $fields): IShare {
		$share = $this->createMock(IShare::class);
		$this->fieldsOf[spl_object_id($share)] = $fields;
		foreach (['Node' => 'node', 'ShareType' => 'shareType', 'SharedWith' => 'sharedWith', 'SharedBy' => 'sharedBy',
			'Permissions' => 'permissions', 'ExpirationDate' => 'expiration', 'ShareTime' => 'shareTime'] as $method => $field) {
			$share->method('set'.$method)->willReturnCallback(function (mixed $value) use ($fields, $field, $share): IShare {
				$fields[$field] = $value;
				return $share;
			});
			$share->method('get'.$method)->willReturnCallback(fn (): mixed => ($fields[$field] ?? null));
		}

		$share->method('getId')->willReturnCallback(fn (): string => (string) ($fields['id'] ?? ''));
		$share->method('getFullId')->willReturnCallback(fn (): string => 'ocinternal:'.($fields['id'] ?? ''));

		return $share;
	}//end share()
}//end class
