<?php

/**
 * A machine write names its acting human when the instance or schema requires it (REQ-SAT-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ActingHumanRecordInterface;
use OCA\OpenRegister\Service\Audit\NamedIdentifier;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCA\OpenRegister\Service\Authorization\ActingHumanContext;
use OCA\OpenRegister\Service\Authorization\ActingHumanGuard;
use OCA\OpenRegister\Service\Authorization\MachineCredentialService;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for {@see ActingHumanGuard}, driven through the real {@see DelegationResolver}.
 *
 * @covers \OCA\OpenRegister\Service\Authorization\ActingHumanGuard
 * @covers \OCA\OpenRegister\Service\Authorization\ActingHumanContext
 * @covers \OCA\OpenRegister\Exception\ActingHumanRefusedException
 * @covers \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @covers \OCA\OpenRegister\Service\Audit\TokenContext
 */
class ActingHumanGuardTest extends TestCase {

	/**
	 * The instance setting value.
	 *
	 * @var string
	 */
	private string $setting = 'on';

	/**
	 * The acting-user header value.
	 *
	 * @var string
	 */
	private string $header = '';

	/**
	 * The signed-in uid, or null for no user.
	 *
	 * @var string|null
	 */
	private ?string $uid = 'koppeling';

	/**
	 * The token the request came with, or null for a browser session.
	 *
	 * @var TokenIdentity|null
	 */
	private ?TokenIdentity $token = null;

	/**
	 * Whether the signed-in user is a service account.
	 *
	 * @var bool
	 */
	private bool $serviceAccount = false;

	/**
	 * The grants the store answers with.
	 *
	 * @var array<int, DelegationGrant>
	 */
	private array $grants = [];

	/**
	 * How often the grant store was asked.
	 *
	 * @var int
	 */
	private int $storeAsked = 0;

	private ActingHumanContext $context;

	/**
	 * Set up a Consumer token calling with the setting on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->context = new ActingHumanContext();
		$this->token   = new TokenIdentity(
			mechanism: 'api-key',
			token: new NamedIdentifier(id: 'tok-1', name: 'scan'),
			owner: new NamedIdentifier(id: 'koppeling', name: 'Koppeling'),
			consumer: new NamedIdentifier(id: 'consumer-uuid', name: 'zaaksysteem-koppeling')
		);
	}//end setUp()

	public function testAMachineWriteWithoutAHumanIsRefused(): void {
		try {
			$this->guard()->assertMayWrite(schema: $this->schema());
			self::fail('a machine write naming nobody must be refused');
		} catch (ActingHumanRefusedException $refusal) {
			self::assertSame(403, $refusal->getCode());
			self::assertSame(['error' => 'acting-human-required'], $refusal->toResponseBody());
		}

		self::assertNull($this->context->toArray());
	}//end testAMachineWriteWithoutAHumanIsRefused()

	public function testANamedUserWithoutAGrantIsRefused(): void {
		$this->header = 'p.bakker';

		try {
			$this->guard()->assertMayWrite(schema: $this->schema());
			self::fail('naming someone who did not consent must be refused');
		} catch (ActingHumanRefusedException $refusal) {
			self::assertSame(['error' => 'acting-human-not-delegated', 'actingUser' => 'p.bakker'], $refusal->toResponseBody());
		}

		self::assertSame(1, $this->storeAsked);
		self::assertNull($this->context->toArray());
	}//end testANamedUserWithoutAGrantIsRefused()

	public function testARevokedGrantIsRefused(): void {
		$this->header = 'm.jansen';
		$grant        = $this->grant(actingAs: 'm.jansen');
		$grant->setStatus(DelegationGrant::STATUS_REVOKED);
		$this->grants = [$grant];

		$this->expectException(ActingHumanRefusedException::class);
		$this->guard()->assertMayWrite(schema: $this->schema());
	}//end testARevokedGrantIsRefused()

	public function testADelegatedWriteIsAcceptedAndRecorded(): void {
		$this->header = ' m.jansen ';
		$this->grants = [$this->grant(actingAs: 'm.jansen')];

		$this->guard()->assertMayWrite(schema: $this->schema());

		self::assertSame(['actingUser' => 'm.jansen', 'actorVia' => 'consumer-uuid'], $this->context->toArray());

		// The audit row the write produces carries the pair inside its sealed summary.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === ActingHumanRecordInterface::class) ? $this->context : throw new RuntimeException('no '.$id)
		);
		$row = new AuditTrail();
		$row->setResultSummary(['purpose' => 'kept']);
		(new TokenAttribution(container: $container))->apply(auditTrail: $row);

		self::assertSame(
			['purpose' => 'kept', 'actingUser' => 'm.jansen', 'actorVia' => 'consumer-uuid'],
			$row->getResultSummary()
		);
	}//end testADelegatedWriteIsAcceptedAndRecorded()

	public function testAPersonalTokenActingForItsOwnerIsTheHuman(): void {
		$this->token  = new TokenIdentity(mechanism: 'app-password', token: new NamedIdentifier(id: 'pw-7', name: 'laptop'));
		$this->header = 'koppeling';

		$this->guard()->assertMayWrite(schema: $this->schema());

		self::assertSame(0, $this->storeAsked);
		self::assertSame(['actingUser' => 'koppeling', 'actorVia' => 'pw-7'], $this->context->toArray());
	}//end testAPersonalTokenActingForItsOwnerIsTheHuman()

	public function testAnUnresolvableTokenIsNotReadAsAMachine(): void {
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->method('resolve')->willThrowException(new RuntimeException('token store down'));

		self::assertNull((new TokenContext(resolver: $resolver))->callingToken());
	}//end testAnUnresolvableTokenIsNotReadAsAMachine()

	public function testASessionUserIsNeverRefused(): void {
		$this->token = new TokenIdentity(mechanism: 'session');
		$this->uid   = 'm.jansen';

		$this->guard()->assertMayWrite(schema: $this->schema());

		self::assertSame(0, $this->storeAsked);
		self::assertNull($this->context->toArray());
	}//end testASessionUserIsNeverRefused()

	public function testAServiceAccountIsAMachineCaller(): void {
		$this->token          = null;
		$this->serviceAccount = true;
		$this->uid            = 'svc-scan';

		$this->expectException(ActingHumanRefusedException::class);
		$this->expectExceptionMessage('acting-human-required');
		$this->guard()->assertMayWrite(schema: $this->schema());
	}//end testAServiceAccountIsAMachineCaller()

	public function testACallWithoutAUserIsAMachineCaller(): void {
		$this->token  = null;
		$this->uid    = null;
		$this->header = 'm.jansen';

		$this->expectException(ActingHumanRefusedException::class);
		$this->expectExceptionMessage('acting-human-not-delegated');
		$this->guard()->assertMayWrite(schema: $this->schema());
	}//end testACallWithoutAUserIsAMachineCaller()

	public function testASystemOperationIsNotJudged(): void {
		$this->token = null;
		$this->uid   = null;
		$guard       = $this->guard();

		SystemOperationContext::run(fn () => $guard->assertMayWrite(schema: $this->schema()));

		self::assertSame(0, $this->storeAsked);
	}//end testASystemOperationIsNotJudged()

	public function testReadsAreNotAffected(): void {
		// Off at the instance and undeclared on the schema: nothing is required,
		// which is also every read, since no read path calls the guard.
		$this->setting = 'off';

		$this->guard()->assertMayWrite(schema: $this->schema());
		$this->guard()->assertMayWrite(schema: null);

		self::assertSame(0, $this->storeAsked);
		self::assertFalse($this->guard()->isRequired(schema: $this->schema()));
	}//end testReadsAreNotAffected()

	public function testTheSchemaAnnotationRequiresItWithTheSettingOff(): void {
		$this->setting = 'off';
		$schema        = $this->schema(annotation: 'required');

		self::assertTrue($this->guard()->isRequired(schema: $schema));
		self::assertTrue($this->guard()->isRequired(schema: $this->schema(annotation: true)));
		self::assertFalse($this->guard()->isRequired(schema: $this->schema(annotation: 'optional')));

		$this->expectException(ActingHumanRefusedException::class);
		$this->guard()->assertMayWrite(schema: $schema);
	}//end testTheSchemaAnnotationRequiresItWithTheSettingOff()

	/**
	 * The guard over the doubles this test configures.
	 *
	 * @return ActingHumanGuard
	 */
	private function guard(): ActingHumanGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'openregister' && $key === ActingHumanGuard::SETTING) ? $this->setting : $default
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($name === ActingHumanGuard::HEADER) ? $this->header : ''
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
		}

		$session->method('getUser')->willReturn($user);

		// The real request-scoped token context, claimed the way the authorisation layer claims it.
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->method('resolve')->willReturn(null);
		$tokens = new TokenContext(resolver: $resolver);
		$tokens->claim(identity: $this->token);

		$accounts = $this->createMock(MachineCredentialService::class);
		$accounts->method('isServiceAccount')->willReturnCallback(fn (): bool => $this->serviceAccount);

		$store = $this->createMock(DelegationGrantMapperInterface::class);
		$store->method('findFor')->willReturnCallback(
			function (): array {
				$this->storeAsked++;
				return $this->grants;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-25 12:00:00'));

		return new ActingHumanGuard(
			appConfig: $appConfig,
			request: $request,
			session: $session,
			tokens: $tokens,
			accounts: $accounts,
			resolver: new DelegationResolver(grants: $store, logger: $this->createMock(LoggerInterface::class)),
			context: $this->context,
			time: $time
		);
	}//end guard()

	/**
	 * A live grant from a user to the calling principal.
	 *
	 * @param string $actingAs The user who delegated.
	 *
	 * @return DelegationGrant
	 */
	private function grant(string $actingAs): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setPrincipal('koppeling');
		$grant->setActingAs($actingAs);
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setGrantedBy($actingAs);
		$grant->setScope([]);

		return $grant;
	}//end grant()

	/**
	 * The schema written to.
	 *
	 * @param mixed $annotation The acting-human annotation, or null for none.
	 *
	 * @return Schema
	 */
	private function schema(mixed $annotation = null): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('publicatie');
		if ($annotation !== null) {
			$schema->setConfiguration([ActingHumanGuard::ANNOTATION => $annotation]);
		}

		return $schema;
	}//end schema()
}//end class
