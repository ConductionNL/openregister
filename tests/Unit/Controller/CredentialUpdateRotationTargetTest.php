<?php

/**
 * A credential update rotates the secret into the vault of the row the guard checked,
 * in that row's own scope, and saves the metadata under the same uuid.
 *
 * Exercises CredentialUpdateTarget, the value the update hands from the guard to the
 * vault write and the metadata save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CredentialControllerFactory;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Behaviour of update() for where a rotated secret and the metadata go.
 */
class CredentialUpdateRotationTargetTest extends TestCase {

	/**
	 * Vault writes: [uuid, secret, scope].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $puts = [];

	/**
	 * Uuids the metadata was saved under.
	 *
	 * @var list<string|null>
	 */
	private array $savedUnder = [];

	/**
	 * @param array<string, mixed> $credData The stored credential data.
	 * @param array<string, mixed> $params   The request parameters.
	 *
	 * @return CredentialController
	 */
	private function controller(array $credData, array $params): CredentialController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner('alice');
		$entity->setObject($credData);
		$entity->setUuid('row-uuid');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$rest = [$options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
				$this->savedUnder[] = ($rest['uuid'] ?? $rest[3] ?? null);
				$saved = new ObjectEntity();
				$saved->setObject($object);
				return $saved;
			}
		);

		$store = $this->createMock(CredentialStore::class);
		$store->method('put')->willReturnCallback(
			function (string $uuid, string $secret, string $scope): void {
				$this->puts[] = [$uuid, $secret, $scope];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) === true ? $params[$key] : $default
		);

		return CredentialControllerFactory::create(
			'openregister',
			$request,
			$session,
			$this->createMock(IGroupManager::class),
			$objectService,
			$store,
			$this->createMock(ProviderCatalogue::class),
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(CredentialAppTokenService::class),
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver(),
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testARotatedSecretGoesToTheCheckedRowInItsPersonalScope(): void {
		$controller = $this->controller(
			['name' => 'My GitHub', 'provider' => 'github', 'allowedApps' => ['hermiq']],
			['name' => 'Renamed', 'secret' => 'gho_new']
		);

		$response = $controller->update('my-github-slug');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['row-uuid', 'gho_new', 'personal']], $this->puts);
		$this->assertSame(['row-uuid'], $this->savedUnder);
	}

	public function testAnUpdateWithoutASecretWritesNoVaultEntry(): void {
		$controller = $this->controller(
			['name' => 'My GitHub', 'provider' => 'github', 'allowedApps' => ['hermiq']],
			['name' => 'Renamed']
		);

		$response = $controller->update('my-github-slug');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->puts);
		$this->assertSame(['row-uuid'], $this->savedUnder);
	}
}
