<?php

/**
 * CredentialController characterisation tests.
 *
 * Pins the answers of the listing, provider, create, management-guard and
 * broker endpoints on every branch — allowed and refused — so their bodies
 * can be split into smaller units without an outcome moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CredentialControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation of CredentialController's branching endpoints.
 */
class CredentialControllerCharacterisationTest extends TestCase {

	/**
	 * The request params.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The X-Credential-Token header.
	 *
	 * @var string
	 */
	private string $token = 'tok';

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The broker double.
	 *
	 * @var CredentialBrokerService&MockObject
	 */
	private CredentialBrokerService&MockObject $broker;

	/**
	 * The token service double.
	 *
	 * @var CredentialAppTokenService&MockObject
	 */
	private CredentialAppTokenService&MockObject $tokens;

	/**
	 * The organisation service double.
	 *
	 * @var OrganisationService&MockObject
	 */
	private OrganisationService&MockObject $organisations;

	/**
	 * The catalogue double.
	 *
	 * @var ProviderCatalogue&MockObject
	 */
	private ProviderCatalogue&MockObject $catalogue;

	/**
	 * The share deriver double.
	 *
	 * @var SharePrincipalDeriver&MockObject
	 */
	private SharePrincipalDeriver&MockObject $shares;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->broker = $this->createMock(CredentialBrokerService::class);
		$this->tokens = $this->createMock(CredentialAppTokenService::class);
		$this->organisations = $this->createMock(OrganisationService::class);
		$this->catalogue = $this->createMock(ProviderCatalogue::class);
		$this->shares = $this->createMock(SharePrincipalDeriver::class);
	}

	/**
	 * The controller, with the given caller signed in.
	 *
	 * @param string|null $uid The caller, or null for anonymous.
	 *
	 * @return CredentialController
	 */
	private function controller(?string $uid = 'alice'): CredentialController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['planners']);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$request->method('getHeader')->willReturnCallback(fn (): string => $this->token);

		return CredentialControllerFactory::create(
			'openregister',
			$request,
			$session,
			$groups,
			$this->objects,
			$this->createMock(CredentialStore::class),
			$this->catalogue,
			$this->broker,
			$this->tokens,
			$this->organisations,
			$this->shares,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A credential object.
	 *
	 * @param string $owner The owner.
	 * @param array  $data  The property bag.
	 * @param string $uuid  The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $owner, array $data, string $uuid = 'cred-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($data);

		return $entity;
	}

	// --- index ----------------------------------------------------------

	/**
	 * Anonymous is 401 everywhere a uid is needed.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorised(): void {
		$controller = $this->controller(null);
		foreach (['index', 'providers', 'create', 'sharedWithMe'] as $action) {
			$this->assertSame(401, $controller->$action()->getStatus(), $action);
		}

		foreach (['update', 'destroy', 'brokerRequest', 'sessionBrokerRequest'] as $action) {
			$this->assertSame(401, $controller->$action('cred-1')->getStatus(), $action);
		}
	}

	/**
	 * The personal index lists only the caller's own personal credentials.
	 *
	 * @return void
	 */
	public function testThePersonalIndexListsOnlyOwnPersonalCredentials(): void {
		$this->objects->method('findAll')->willReturn(
			[
				$this->credential('alice', ['name' => 'mine'], 'a'),
				$this->credential('alice', ['name' => 'org', 'scope' => 'organisation'], 'b'),
				$this->credential('bob', ['name' => 'theirs'], 'c'),
				$this->credential('alice', ['name' => 'personal', 'scope' => 'personal'], 'd'),
			]
		);

		$results = $this->controller()->index()->getData()['results'];

		$this->assertSame(['a', 'd'], array_column(array_column($results, '@self'), 'id'));
	}

	/**
	 * A failing listing is 500, for both scopes.
	 *
	 * @return void
	 */
	public function testAFailingListingIs500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db'));
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$this->organisations->method('getActiveOrganisation')->willReturn($organisation);

		$response = $this->controller()->index();
		$this->assertSame([500, ['message' => 'Unable to list credentials']], [$response->getStatus(), $response->getData()]);

		$this->params = ['scope' => 'Organisation '];
		$response = $this->controller()->index();
		$this->assertSame([500, ['message' => 'Unable to list credentials']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * Without an active organisation the organisation index is empty and reads nothing.
	 *
	 * @return void
	 */
	public function testTheOrganisationIndexWithoutActiveOrganisationIsEmpty(): void {
		$this->params = ['scope' => 'organisation'];
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(['results' => []], $this->controller()->index()->getData());
	}

	// --- providers ------------------------------------------------------

	/**
	 * The provider list skips malformed entries and names kind, base-url need and preview.
	 *
	 * @return void
	 */
	public function testTheProviderListShapesEveryEntry(): void {
		$this->catalogue->method('all')->willReturn(
			[
				'junk',
				['identifier' => ''],
				['identifier' => 'gh', 'title' => 'GitHub'],
				['identifier' => 'nc', 'kind' => ' oauth2-token-set ', 'baseUrlFrom' => 'instance', 'preview' => true],
				['identifier' => 'x', 'kind' => 'other', 'baseUrlFrom' => '  ', 'preview' => 'yes'],
			]
		);

		$this->assertSame(
			[
				'results' => [
					['identifier' => 'gh', 'title' => 'GitHub', 'kind' => 'secret', 'requiresInstanceBaseUrl' => false, 'preview' => false],
					['identifier' => 'nc', 'title' => 'nc', 'kind' => 'oauth2-token-set', 'requiresInstanceBaseUrl' => true, 'preview' => true],
					['identifier' => 'x', 'title' => 'x', 'kind' => 'secret', 'requiresInstanceBaseUrl' => false, 'preview' => false],
				],
			],
			$this->controller()->providers()->getData()
		);
	}

	// --- create ---------------------------------------------------------

	/**
	 * A nameless or unknown-provider create is 400 and mints nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidCreateIs400AndMintsNothing(): void {
		$this->catalogue->method('get')->willReturnCallback(static fn (string $id): ?array => ($id === 'gh' ? ['identifier' => 'gh'] : null));
		$this->broker->expects($this->never())->method('mint');

		foreach ([['name' => '  ', 'provider' => 'gh'], ['name' => 'n', 'provider' => 'nope']] as $params) {
			$this->params = $params;
			$response = $this->controller()->create();
			$this->assertSame([400, ['message' => 'Invalid credential request']], [$response->getStatus(), $response->getData()]);
		}
	}

	/**
	 * A personal create mints with the trimmed name, filtered apps, a null non-string secret and the metadata.
	 *
	 * @return void
	 */
	public function testAPersonalCreateMintsWhatTheRequestCarries(): void {
		$this->catalogue->method('get')->willReturn(['identifier' => 'gh']);
		$this->params = [
			'name' => ' My token ',
			'provider' => 'gh',
			'secret' => ['not', 'a', 'string'],
			'allowedApps' => ['hermiq', '', 3, 'pipelinq'],
			'kind' => ' secret ',
			'clientId' => '',
		];
		$this->broker->expects($this->once())->method('mint')
			->with('My token', 'gh', 'alice', ['hermiq', 'pipelinq'], null, 'personal', null, ['kind' => 'secret'])
			->willReturn($this->credential('alice', ['name' => 'My token']));

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('cred-1', $response->getData()['@self']['id']);
	}

	/**
	 * An organisation create is gated, and a mint failure maps to 400 / 500.
	 *
	 * @return void
	 */
	public function testAnOrganisationCreateIsGatedAndMintFailuresMap(): void {
		$this->catalogue->method('get')->willReturn(['identifier' => 'gh']);
		$this->organisations->method('isOrganisationAdmin')->willReturnCallback(
			static fn (string $org, string $uid): bool => $org === 'org-1'
		);

		$this->params = ['name' => 'n', 'provider' => 'gh', 'scope' => 'organisation', 'organisation' => 'org-2'];
		$this->assertSame(403, $this->controller()->create()->getStatus());

		$this->params = ['name' => 'n', 'provider' => 'gh', 'scope' => 'organisation'];
		$this->assertSame(400, $this->controller()->create()->getStatus());

		$this->params = ['name' => 'n', 'provider' => 'gh', 'scope' => 'organisation', 'organisation' => 'org-1', 'secret' => 's3'];
		$this->broker->method('mint')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('host')),
			$this->throwException(new RuntimeException('vault')),
			$this->credential('alice', ['scope' => 'organisation'])
		);
		$response = $this->controller()->create();
		$this->assertSame([400, ['message' => 'Invalid credential request']], [$response->getStatus(), $response->getData()]);
		$response = $this->controller()->create();
		$this->assertSame([500, ['message' => 'Unable to create credential']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(201, $this->controller()->create()->getStatus());
	}

	// --- management guard (destroy) --------------------------------------

	/**
	 * The guard: missing, uuid-less and somebody else's personal credential are one 403.
	 *
	 * @return void
	 */
	public function testThePersonalGuardRefusesUniformly(): void {
		$this->objects->expects($this->never())->method('deleteObject');
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('missing')),
			null,
			$this->credential('alice', [], ''),
			$this->credential('bob', [])
		);

		for ($i = 0; $i < 4; $i++) {
			$response = $this->controller()->destroy('cred-1');
			$this->assertSame([403, ['message' => 'Forbidden']], [$response->getStatus(), $response->getData()], (string)$i);
		}
	}

	/**
	 * The owner may delete a personal credential; an organisation one needs its admin.
	 *
	 * @return void
	 */
	public function testTheGuardAdmitsTheOwnerAndTheOrganisationAdmin(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->credential('alice', []),
			$this->credential('bob', ['scope' => 'organisation', 'organisation' => '']),
			$this->credential('bob', ['scope' => 'organisation', 'organisation' => 'org-2']),
			$this->credential('bob', ['scope' => 'organisation', 'organisation' => 'org-1'])
		);
		$this->organisations->method('isOrganisationAdmin')->willReturnCallback(
			static fn (string $org, string $uid): bool => ($org === 'org-1' && $uid === 'alice')
		);

		$statuses = [];
		for ($i = 0; $i < 4; $i++) {
			$statuses[] = $this->controller()->destroy('cred-1')->getStatus();
		}

		$this->assertSame([200, 403, 403, 200], $statuses);
	}

	// --- sharedWithMe ---------------------------------------------------

	/**
	 * Shares are judged with the caller's groups; own and non-entity rows are skipped.
	 *
	 * @return void
	 */
	public function testSharedWithMeAsksTheDeriverForEveryForeignCredential(): void {
		$this->objects->method('findAll')->with([], false, true)->willReturn(
			[
				'not an entity',
				$this->credential('alice', ['sharedWith' => ['x']], 'own'),
				$this->credential('bob', ['sharedWith' => ['user:alice']], 'yes'),
				$this->credential('carol', [], 'no'),
			]
		);
		$seen = [];
		$this->shares->method('grants')->willReturnCallback(
			function (...$args) use (&$seen): bool {
				$seen[] = $args;
				return $args[0] !== null;
			}
		);

		$data = $this->controller()->sharedWithMe()->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame('yes', $data['results'][0]['@self']['id']);
		$this->assertSame(
			[
				[['user:alice'], 'alice', ['planners'], ['use', null]],
				[null, 'alice', ['planners'], ['use', null]],
			],
			$seen
		);
	}

	/**
	 * A failing shared listing is 500.
	 *
	 * @return void
	 */
	public function testAFailingSharedListingIs500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db'));

		$response = $this->controller()->sharedWithMe();

		$this->assertSame([500, ['message' => 'Unable to list shared credentials']], [$response->getStatus(), $response->getData()]);
	}

	// --- broker endpoints -----------------------------------------------

	/**
	 * The token endpoint verifies against the call and brokers with the token's app.
	 *
	 * @return void
	 */
	public function testTheTokenEndpointBrokersWithTheTokensApp(): void {
		$this->params = ['method' => 'POST', 'path' => '/x', 'headers' => ['A' => 1, 2 => 'b', 'C' => []], 'body' => ['k' => 'v']];
		$this->tokens->expects($this->once())->method('verify')->with('tok', 'POST', '/x')
			->willReturn(['credentialId' => 'cred-1', 'appId' => 'hermiq']);
		$this->broker->expects($this->once())->method('request')
			->with('cred-1', 'hermiq', 'POST', '/x', ['A' => '1'], '{"k":"v"}')
			->willReturn(['status' => 201]);

		$response = $this->controller()->brokerRequest('cred-1');

		$this->assertSame([200, ['status' => 201]], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * Every token-endpoint refusal maps to its static answer.
	 *
	 * @return void
	 */
	public function testTheTokenEndpointMapsEveryRefusal(): void {
		$this->tokens->method('verify')->willReturnOnConsecutiveCalls(
			['credentialId' => 'other', 'appId' => 'a'],
			$this->throwException(new RuntimeException('bad signature')),
			['credentialId' => 'cred-1', 'appId' => 'a'],
			['credentialId' => 'cred-1', 'appId' => 'a'],
			['credentialId' => 'cred-1', 'appId' => 'a'],
			['credentialId' => 'cred-1', 'appId' => 'a']
		);
		$this->broker->method('request')->willReturnOnConsecutiveCalls(
			$this->throwException(new CredentialRelinkRequiredException('relink')),
			$this->throwException(new CredentialAccessDeniedException('denied')),
			$this->throwException(new CredentialUpstreamException('upstream')),
			$this->throwException(new RuntimeException('other'))
		);
		$this->broker->expects($this->exactly(4))->method('request');

		$answers = [];
		for ($i = 0; $i < 6; $i++) {
			$response = $this->controller()->brokerRequest('cred-1');
			$answers[] = [$response->getStatus(), $response->getData()['message']];
		}

		$this->assertSame(
			[
				[403, 'Request not permitted'],
				[403, 'Request not permitted'],
				[409, 'Credential must be reconnected'],
				[403, 'Request not permitted'],
				[502, 'Upstream request failed'],
				[403, 'Request not permitted'],
			],
			$answers
		);
	}

	/**
	 * The session endpoint needs an app id, brokers with defaults, and maps refusals alike.
	 *
	 * @return void
	 */
	public function testTheSessionEndpointNeedsAnAppAndMapsEveryRefusal(): void {
		$this->params = ['appId' => '  '];
		$this->assertSame(403, $this->controller()->sessionBrokerRequest('cred-1')->getStatus());

		$this->params = ['appId' => ' hermiq ', 'body' => 'raw'];
		$this->broker->method('request')
			->with('cred-1', 'hermiq', 'GET', '', [], 'raw')
			->willReturnOnConsecutiveCalls(
				['status' => 200],
				$this->throwException(new CredentialRelinkRequiredException('relink')),
				$this->throwException(new CredentialAccessDeniedException('denied')),
				$this->throwException(new CredentialUpstreamException('upstream')),
				$this->throwException(new RuntimeException('other'))
			);

		$answers = [];
		for ($i = 0; $i < 5; $i++) {
			$response = $this->controller()->sessionBrokerRequest('cred-1');
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[200, ['status' => 200]],
				[409, ['message' => 'Credential must be reconnected']],
				[403, ['message' => 'Request not permitted']],
				[502, ['message' => 'Upstream request failed']],
				[403, ['message' => 'Request not permitted']],
			],
			$answers
		);
	}
}
