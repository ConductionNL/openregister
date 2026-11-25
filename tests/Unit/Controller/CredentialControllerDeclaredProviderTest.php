<?php

/**
 * CredentialController with app-declared providers: create, update and the provider listing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
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

/**
 * @covers \OCA\OpenRegister\Controller\CredentialController
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderResolver
 */
class CredentialControllerDeclaredProviderTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private ObjectService&MockObject $objects;

	private CredentialBrokerService&MockObject $broker;

	private ProviderCatalogue&MockObject $catalogue;

	private DeclaredProviderResolver&MockObject $declared;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->broker = $this->createMock(CredentialBrokerService::class);
		$this->catalogue = $this->createMock(ProviderCatalogue::class);
		$this->catalogue->method('get')->willReturnCallback(
			static fn (string $id): ?array => ($id === 'github' ? ['identifier' => 'github', 'title' => 'GitHub'] : null)
		);
		$this->declared = $this->createPartialMock(DeclaredProviderResolver::class, ['resolve', 'listing']);
	}

	/**
	 * The controller with alice signed in.
	 *
	 * @param bool $withLayer Whether the declaration layer is wired.
	 *
	 * @return CredentialController
	 */
	private function controller(bool $withLayer = true): CredentialController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);

		return CredentialControllerFactory::create(
			request: $request,
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			objectService: $this->objects,
			credentialStore: $this->createMock(CredentialStore::class),
			catalogue: $this->catalogue,
			broker: $this->broker,
			tokenService: $this->createMock(CredentialAppTokenService::class),
			organisationService: $this->createMock(OrganisationService::class),
			shareDeriver: $this->createMock(SharePrincipalDeriver::class),
			logger: $this->createMock(LoggerInterface::class),
			declared: ($withLayer === true ? $this->declared : null),
		);
	}

	/**
	 * Let the resolver answer one status for alpha:codeberg.
	 *
	 * @param string $status The admission status.
	 *
	 * @return void
	 */
	private function resolvesAs(string $status): void {
		$this->declared->method('resolve')->willReturnCallback(
			static fn (string $id): array => [
				'status' => ($id === 'alpha:codeberg' ? $status : 'unknown'),
				'origin' => ($id === 'alpha:codeberg' ? 'alpha' : ''),
				'lane' => '',
				'entry' => null,
			]
		);
	}

	/**
	 * A stored credential.
	 *
	 * @param array<string, mixed> $data The property bag.
	 *
	 * @return ObjectEntity
	 */
	private function stored(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('cred-1');
		$entity->setOwner('alice');
		$entity->setObject($data);

		return $entity;
	}

	public function testAnAdmittedDeclaredProviderMintsScopedToTheDeclaringAppOnly(): void {
		$this->resolvesAs('admitted');
		$this->params = ['name' => 'forge', 'provider' => 'alpha:codeberg', 'allowedApps' => ['beta', 'alpha', 'gamma']];
		$this->broker->expects($this->once())->method('mintCredential')
			->with(
				new CredentialMint(name: 'forge', provider: 'alpha:codeberg', owner: 'alice', metadata: []),
				new CredentialMintOptions(allowedApps: ['alpha'], secret: null, scope: 'personal', organisation: null)
			)
			->willReturn($this->stored(['name' => 'forge']));

		$this->assertSame(201, $this->controller()->create()->getStatus());
	}

	/**
	 * @dataProvider unadmitted
	 */
	public function testADeclaredProviderThatIsNotAdmittedMintsNothing(string $status): void {
		$this->resolvesAs($status);
		$this->params = ['name' => 'forge', 'provider' => 'alpha:codeberg', 'allowedApps' => ['alpha']];
		$this->broker->expects($this->never())->method('mintCredential');

		$response = $this->controller()->create();

		$this->assertSame([400, ['message' => 'Invalid credential request']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unadmitted(): array {
		return [
			'pending' => ['pending'],
			'rejected' => ['rejected'],
			'revoked' => ['revoked'],
			'invalid' => ['invalid'],
			'declaring app disabled' => ['unknown'],
		];
	}

	public function testWithoutTheDeclarationLayerADeclaredIdentifierIsRefused(): void {
		$this->params = ['name' => 'forge', 'provider' => 'alpha:codeberg'];
		$this->broker->expects($this->never())->method('mintCredential');

		$this->assertSame(400, $this->controller(withLayer: false)->create()->getStatus());
	}

	public function testAReviewedProviderKeepsTheRequestedAppsAndNeverAsksTheDeclarationLayer(): void {
		$this->declared->expects($this->never())->method('resolve');
		$this->params = ['name' => 'gh', 'provider' => 'github', 'allowedApps' => ['beta', 'alpha']];
		$this->broker->expects($this->once())->method('mintCredential')
			->with(
				$this->anything(),
				new CredentialMintOptions(allowedApps: ['beta', 'alpha'], secret: null, scope: 'personal', organisation: null)
			)
			->willReturn($this->stored(['name' => 'gh']));

		$this->assertSame(201, $this->controller()->create()->getStatus());
	}

	public function testAnUpdateCannotAddAnotherAppToADeclaredProvidersCredential(): void {
		$this->objects->method('find')->willReturn(
			$this->stored(['name' => 'forge', 'provider' => 'alpha:codeberg', 'owner' => 'alice', 'allowedApps' => ['alpha']])
		);
		$this->objects->expects($this->never())->method('saveObject');
		$this->params = ['allowedApps' => ['alpha', 'beta']];

		$response = $this->controller()->update('cred-1');

		$this->assertSame([400, ['message' => 'Invalid credential request']], [$response->getStatus(), $response->getData()]);
	}

	public function testAnUpdateThatAddsNoOtherAppIsSaved(): void {
		$this->objects->method('find')->willReturn(
			$this->stored(['name' => 'forge', 'provider' => 'alpha:codeberg', 'owner' => 'alice', 'allowedApps' => ['alpha']])
		);
		$this->objects->expects($this->exactly(2))->method('saveObject')
			->willReturn($this->stored(['name' => 'forge']));

		$this->params = ['allowedApps' => ['alpha']];
		$this->assertSame(200, $this->controller()->update('cred-1')->getStatus());

		$this->params = ['name' => 'renamed'];
		$this->assertSame(200, $this->controller()->update('cred-1')->getStatus());
	}

	public function testAReviewedProvidersCredentialMayStillGainApps(): void {
		$this->objects->method('find')->willReturn(
			$this->stored(['name' => 'gh', 'provider' => 'github', 'owner' => 'alice', 'allowedApps' => ['alpha']])
		);
		$this->objects->expects($this->once())->method('saveObject')->willReturn($this->stored(['name' => 'gh']));
		$this->params = ['allowedApps' => ['alpha', 'beta']];

		$this->assertSame(200, $this->controller()->update('cred-1')->getStatus());
	}

	public function testTheListingCarriesOriginAndStatusAndNeverRulesOrHost(): void {
		$this->catalogue->method('all')->willReturn([['identifier' => 'github', 'title' => 'GitHub', 'baseUrl' => 'https://api.github.com', 'allowRules' => [['method' => 'GET', 'pathPattern' => '/*']]]]);
		$this->declared->method('listing')->willReturn(
			[
				['identifier' => 'alpha:codeberg', 'title' => 'Codeberg', 'origin' => 'alpha', 'status' => 'pending'],
				['identifier' => 'beta:forge', 'title' => 'Forge', 'origin' => 'beta', 'status' => 'admitted'],
			]
		);

		$results = $this->controller()->providers()->getData()['results'];

		$this->assertSame(
			[
				['github', 'catalogue', 'admitted'],
				['alpha:codeberg', 'alpha', 'pending'],
				['beta:forge', 'beta', 'admitted'],
			],
			array_map(static fn (array $row): array => [$row['identifier'], $row['origin'], $row['status']], $results)
		);
		foreach ($results as $row) {
			$this->assertArrayNotHasKey('allowRules', $row);
			$this->assertArrayNotHasKey('baseUrl', $row);
			$this->assertSame(['identifier', 'title', 'kind', 'requiresInstanceBaseUrl', 'preview', 'origin', 'status'], array_keys($row));
		}
	}

	public function testWithoutTheDeclarationLayerTheListingIsTheCatalogueAlone(): void {
		$this->catalogue->method('all')->willReturn([['identifier' => 'github', 'title' => 'GitHub']]);

		$results = $this->controller(withLayer: false)->providers()->getData()['results'];

		$this->assertSame(['github'], array_column($results, 'identifier'));
	}
}
