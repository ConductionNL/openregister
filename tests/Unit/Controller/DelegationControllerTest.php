<?php

/**
 * Unit coverage for DelegationController — the consent surface.
 *
 * The e2e suite already drives this end to end against a live instance. These
 * tests exist for the cases a live run cannot reach cheaply: no session, an
 * unknown grant, an answerer the lifecycle refuses, and — the one that matters —
 * a caller trying to raise a request in somebody else's name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\DelegationController;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Service\Delegation\DelegationConsentService;
use OCA\OpenRegister\Service\Delegation\DelegationNotifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Locks who may ask, who may answer, and what the surface returns.
 */
class DelegationControllerTest extends TestCase {

	/**
	 * The uid the session reports, or null for no session.
	 *
	 * @var string|null
	 */
	private ?string $caller = 'alice';

	/**
	 * Whether the caller is an administrator.
	 *
	 * @var boolean
	 */
	private bool $isAdmin = false;

	/**
	 * Uids the user manager resolves.
	 *
	 * @var array<int, string>
	 */
	private array $accounts = ['alice', 'mayor'];

	/**
	 * The request body.
	 *
	 * @var array
	 */
	private array $body = [];

	/**
	 * The grant the mapper answers by uuid, or null for none.
	 *
	 * @var DelegationGrant|null
	 */
	private ?DelegationGrant $stored = null;

	/**
	 * Requests the notifier was asked to raise.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	protected function setUp(): void {
		parent::setUp();

		$this->caller = 'alice';
		$this->isAdmin = false;
		$this->accounts = ['alice', 'mayor'];
		$this->body = [];
		$this->stored = null;
		$this->notified = [];
	}//end setUp()

	/**
	 * A pending request from alice to act as mayor.
	 *
	 * @return DelegationGrant The request.
	 */
	private function pending(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setUuid('grant-1');
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');
		$grant->setStatus(DelegationGrant::STATUS_PENDING);
		$grant->setScope([]);
		$grant->setRequestedAt(new DateTime('2026-08-26 12:00:00'));

		return $grant;
	}

	/**
	 * The controller, wired to doubles driven by this class's properties.
	 *
	 * @param DelegationConsentService|null $consent Override the lifecycle double.
	 *
	 * @return DelegationController The controller under test.
	 */
	private function controller(?DelegationConsentService $consent = null): DelegationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->body);

		$grants = $this->createMock(DelegationGrantMapper::class);
		$grants->method('findAwaitingAnswerBy')->willReturn([]);
		$grants->method('findGrantsOver')->willReturn([]);
		$grants->method('findHeldBy')->willReturnCallback(
			fn (): array => ($this->stored === null ? [] : [$this->stored])
		);
		$grants->method('findByUuid')->willReturnCallback(
			function (string $uuid): DelegationGrant {
				if ($this->stored === null || $this->stored->getUuid() !== $uuid) {
					throw new DoesNotExistException('no such grant');
				}

				return $this->stored;
			}
		);

		if ($consent === null) {
			$consent = $this->createMock(DelegationConsentService::class);
			$consent->method('request')->willReturnCallback(fn (): DelegationGrant => $this->pending());
			$consent->method('answer')->willReturnArgument(0);
			$consent->method('revoke')->willReturnArgument(0);
			$consent->method('describe')->willReturnCallback(
				static fn (DelegationGrant $g): array => ['uuid' => $g->getUuid(), 'status' => $g->getStatus()]
			);
		}

		$notifier = $this->createMock(DelegationNotifier::class);
		$notifier->method('requested')->willReturnCallback(
			function (DelegationGrant $grant): void {
				$this->notified[] = (string)$grant->getUuid();
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->caller === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->caller);

				return $user;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, $this->accounts, true) === false) {
					return null;
				}

				return $this->createMock(IUser::class);
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);

		return new DelegationController(
			'openregister',
			$request,
			$grants,
			$consent,
			$notifier,
			$session,
			$userManager,
			$groupManager
		);
	}

	/**
	 * POSITIVE CONTROL: a request is created, described and notified.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testARequestIsCreatedAndNotified(): void {
		$this->body = ['actingAs' => 'mayor', 'reason' => 'covering leave'];

		$response = $this->controller()->request();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('grant-1', $response->getData()['uuid']);
		$this->assertSame(
			['grant-1'],
			$this->notified,
			'a request nobody is told about is a request nobody can answer'
		);
	}

	/**
	 * 🔴 The principal is the SESSION USER — a body cannot name it.
	 *
	 * An endpoint that accepted `principal` would let anyone raise a request in
	 * somebody else's name, and the person prompted would reasonably read it as
	 * that party asking. The assertion is on the value handed to the lifecycle,
	 * because that is where the substitution would take effect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testTheBodyCannotNameThePrincipal(): void {
		$seen = [];

		$consent = $this->createMock(DelegationConsentService::class);
		$consent->method('request')->willReturnCallback(
			function (string $principal) use (&$seen): DelegationGrant {
				$seen[] = $principal;

				return $this->pending();
			}
		);
		$consent->method('describe')->willReturn(['uuid' => 'grant-1', 'status' => 'pending']);

		$this->body = ['actingAs' => 'mayor', 'principal' => 'admin', 'reason' => 'why'];

		$this->controller($consent)->request();

		$this->assertSame(['alice'], $seen, 'the principal must come from the session, never the payload');
	}

	/**
	 * A request naming no account is refused BEFORE it is stored.
	 *
	 * A pending request naming nobody can never be answered, so it would sit in
	 * the store until it expired while its requester waited for a prompt no
	 * account could receive.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testARequestNamingNoAccountIsRefusedBeforeStorage(): void {
		$this->body = ['actingAs' => 'ghost', 'reason' => 'why'];

		$response = $this->controller()->request();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->notified, 'nothing may be raised for an account that does not exist');
	}

	/**
	 * A lifecycle refusal on `request()` is a 400, not a 500.
	 *
	 * @return void
	 */
	public function testAMeaninglessRequestIsABadRequest(): void {
		$consent = $this->createMock(DelegationConsentService::class);
		$consent->method('request')->willThrowException(
			new InvalidArgumentException('Acting as yourself is not delegation; no consent is needed.')
		);

		$this->body = ['actingAs' => 'alice'];

		$response = $this->controller($consent)->request();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * 🔴 "You may not answer this" is a 403, not a 400.
	 *
	 * Being refused is an authorization outcome. Reporting it as a malformed
	 * request sends the reader to their payload, which is correct about nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnauthorisedAnswerIsForbiddenNotBadRequest(): void {
		$this->stored = $this->pending();

		$consent = $this->createMock(DelegationConsentService::class);
		$consent->method('answer')->willThrowException(
			new InvalidArgumentException('Only "mayor" or an administrator may answer.')
		);

		$this->body = ['allow' => true];

		$response = $this->controller($consent)->answer('grant-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * POSITIVE CONTROL: a permitted answer returns the described grant.
	 *
	 * @return void
	 */
	public function testAPermittedAnswerReturnsTheGrant(): void {
		$this->stored = $this->pending();
		$this->body = ['allow' => true];

		$response = $this->controller()->answer('grant-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('grant-1', $response->getData()['uuid']);
	}

	/**
	 * An unknown grant is a 404 on every uuid-addressed route.
	 *
	 * @return void
	 */
	public function testAnUnknownGrantIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->answer('nope')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->revoke('nope')->getStatus());
	}

	/**
	 * Revoking returns the revoked grant.
	 *
	 * @return void
	 */
	public function testRevokingReturnsTheGrant(): void {
		$this->stored = $this->pending();

		$response = $this->controller()->revoke('grant-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * A revoke the lifecycle refuses is a 403.
	 *
	 * @return void
	 */
	public function testAnUnauthorisedRevokeIsForbidden(): void {
		$this->stored = $this->pending();

		$consent = $this->createMock(DelegationConsentService::class);
		$consent->method('revoke')->willThrowException(new InvalidArgumentException('not yours'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($consent)->revoke('grant-1')->getStatus());
	}

	/**
	 * The listing returns BOTH sides of the question.
	 *
	 * "Who may act as me" and "who may I act as" are two halves of one question;
	 * splitting them across endpoints means whichever a UI forgets to call is the
	 * half nobody ever looks at.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testTheListingReturnsBothSides(): void {
		$this->stored = $this->pending();

		$data = $this->controller()->index()->getData();

		$this->assertArrayHasKey('awaitingMyAnswer', $data);
		$this->assertArrayHasKey('overMe', $data);
		$this->assertArrayHasKey('heldByMe', $data);
		$this->assertSame('grant-1', $data['heldByMe'][0]['uuid']);
	}

	/**
	 * Every route refuses without a session.
	 *
	 * Asserted across all four rather than one, because "signed in" is checked
	 * per method — a route that forgot the check would be invisible to a test
	 * that only exercised its neighbour.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testEveryRouteRefusesWithoutASession(): void {
		$this->caller = null;
		$controller = $this->controller();

		foreach (
			[
				$controller->index(),
				$controller->request(),
				$controller->answer('grant-1'),
				$controller->revoke('grant-1'),
			] as $response
		) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}

	/**
	 * An administrator's answer is passed through as such.
	 *
	 * The lifecycle decides what admin means; this asserts only that the fact
	 * reaches it. Dropping it here would make every administrator refusal look
	 * like a lifecycle rule rather than a lost flag.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAdministratorStatusReachesTheLifecycle(): void {
		$this->stored = $this->pending();
		$this->isAdmin = true;
		$this->body = ['allow' => true];

		$seen = [];
		$consent = $this->createMock(DelegationConsentService::class);
		$consent->method('answer')->willReturnCallback(
			function (DelegationGrant $grant, string $by, bool $allow, DateTime $now, bool $isAdmin = false) use (&$seen): DelegationGrant {
				$seen[] = $isAdmin;

				return $grant;
			}
		);
		$consent->method('describe')->willReturn(['uuid' => 'grant-1', 'status' => 'granted']);

		$this->controller($consent)->answer('grant-1');

		$this->assertSame([true], $seen);
	}
}
