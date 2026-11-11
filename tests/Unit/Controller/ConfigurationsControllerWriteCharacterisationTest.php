<?php

/**
 * Characterisation tests for ConfigurationsController's write endpoints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationsController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConfigurationsControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins every branch of create(), update() and import(): the admin gate (allowed
 * and refused), the parameter scrubbing, the sourceType/isLocal mapping, and the
 * owner/app/force fallbacks of an import.
 */
class ConfigurationsControllerWriteCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private ConfigurationMapper&MockObject $mapper;

	private ConfigurationService&MockObject $service;

	/**
	 * Build the controller with the current user admin or not.
	 *
	 * @param bool $admin Whether the signed-in user is an administrator.
	 *
	 * @return ConfigurationsController The controller under test.
	 */
	private function controller(bool $admin): ConfigurationsController {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(ConfigurationMapper::class);
		$this->service = $this->createMock(ConfigurationService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);

		return ConfigurationsControllerFactory::create(
			$this->request,
			$this->mapper,
			$this->service,
			$this->createMock(UploadService::class),
			$userSession,
			$groupManager
		);
	}//end controller()

	/**
	 * Run create() with the given request parameters and return what reached the mapper.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The data handed to createFromArray().
	 */
	private function createdWith(array $params): array {
		$controller = $this->controller(true);
		$this->request->method('getParams')->willReturn($params);
		$seen = null;
		$this->mapper->expects($this->once())->method('createFromArray')
			->willReturnCallback(function (array $data) use (&$seen): Configuration {
				$seen = $data;
				return new Configuration();
			});

		$this->assertSame(201, $controller->create()->getStatus());

		return $seen;
	}//end createdWith()

	/**
	 * Run update() with the given request parameters and return what reached the mapper.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The data handed to updateFromArray().
	 */
	private function updatedWith(array $params): array {
		$controller = $this->controller(true);
		$this->request->method('getParams')->willReturn($params);
		$seen = null;
		$this->mapper->expects($this->once())->method('updateFromArray')
			->willReturnCallback(function (int $id, array $data) use (&$seen): Configuration {
				$this->assertSame(7, $id);
				$seen = $data;
				return new Configuration();
			});

		$this->assertSame(200, $controller->update(7)->getStatus());

		return $seen;
	}//end updatedWith()

	public function testCreateRefusesANonAdminWithoutTouchingTheMapper(): void {
		$controller = $this->controller(false);
		$this->mapper->expects($this->never())->method('createFromArray');

		$response = $controller->create();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	public function testCreateDropsUnderscoreKeysAndDataAndKeepsTheRest(): void {
		$seen = $this->createdWith(['_route' => 'x', '_a' => 1, 'data' => 'y', 'title' => 'T', 'uuid' => 'u-1']);

		$this->assertSame(['title' => 'T', 'uuid' => 'u-1', 'sourceType' => 'local', 'isLocal' => true], $seen);
	}

	public function testCreateGeneratesAUuidOnlyWhenAbsent(): void {
		$seen = $this->createdWith(['title' => 'T']);

		$this->assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $seen['uuid']);
	}

	public function testCreateDefaultsANullSourceTypeToLocal(): void {
		$seen = $this->createdWith(['uuid' => 'u', 'sourceType' => null]);

		$this->assertSame('local', $seen['sourceType']);
		$this->assertTrue($seen['isLocal']);
	}

	public function testCreateDefaultsAnEmptySourceTypeToLocal(): void {
		$seen = $this->createdWith(['uuid' => 'u', 'sourceType' => '', 'isLocal' => false]);

		$this->assertSame('local', $seen['sourceType']);
		$this->assertTrue($seen['isLocal']);
	}

	public function testCreateForcesIsLocalForLocalAndManual(): void {
		$this->assertTrue($this->createdWith(['uuid' => 'u', 'sourceType' => 'local', 'isLocal' => false])['isLocal']);
		$this->assertTrue($this->createdWith(['uuid' => 'u', 'sourceType' => 'manual', 'isLocal' => false])['isLocal']);
	}

	public function testCreateForcesIsLocalOffForRemoteSources(): void {
		foreach (['github', 'gitlab', 'url'] as $type) {
			$this->assertFalse($this->createdWith(['uuid' => 'u', 'sourceType' => $type, 'isLocal' => true])['isLocal']);
		}
	}

	public function testCreateKeepsAGivenIsLocalForAnUnknownSourceType(): void {
		$this->assertFalse($this->createdWith(['uuid' => 'u', 'sourceType' => 'upload', 'isLocal' => false])['isLocal']);
		$this->assertTrue($this->createdWith(['uuid' => 'u', 'sourceType' => 'upload'])['isLocal']);
	}

	public function testCreateAnswersAMapperFailureWith400(): void {
		$controller = $this->controller(true);
		$this->request->method('getParams')->willReturn(['uuid' => 'u']);
		$this->mapper->method('createFromArray')->willThrowException(new \Exception('boom'));

		$response = $controller->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create configuration: boom'], $response->getData());
	}

	public function testUpdateRefusesANonAdminWithoutTouchingTheMapper(): void {
		$controller = $this->controller(false);
		$this->mapper->expects($this->never())->method('updateFromArray');

		$response = $controller->update(7);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	public function testUpdateScrubsInternalAndImmutableKeys(): void {
		$seen = $this->updatedWith(
			['_r' => 1, 'data' => 2, 'id' => 3, 'organisation' => 'o', 'owner' => 'w', 'created' => 'c', 'title' => 'T', 'isLocal' => 'kept']
		);

		$this->assertSame(['title' => 'T', 'isLocal' => 'kept'], $seen);
	}

	public function testUpdateMapsKnownSourceTypesAndLeavesOthersAlone(): void {
		$this->assertTrue($this->updatedWith(['sourceType' => 'manual', 'isLocal' => false])['isLocal']);
		$this->assertFalse($this->updatedWith(['sourceType' => 'gitlab', 'isLocal' => true])['isLocal']);
		$this->assertSame(['sourceType' => 'upload'], $this->updatedWith(['sourceType' => 'upload']));
		$this->assertSame(['sourceType' => null, 'isLocal' => 'x'], $this->updatedWith(['sourceType' => null, 'isLocal' => 'x']));
	}

	public function testUpdateAnswersAMapperFailureWith400(): void {
		$controller = $this->controller(true);
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('updateFromArray')->willThrowException(new \Exception('nope'));

		$response = $controller->update(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update configuration: nope'], $response->getData());
	}

	public function testImportRefusesANonAdmin(): void {
		$controller = $this->controller(false);
		$this->service->expects($this->never())->method('getUploadedJson');

		$response = $controller->import();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	/**
	 * Run an import with the given getParam() answers and return the createFromArray()
	 * data and the importFromJson() force flag.
	 *
	 * @param array<string, mixed> $params The getParam() answers by name.
	 *
	 * @return array{0: array<string, mixed>, 1: bool, 2: array<int, mixed>} Created data, force, uploaded files.
	 */
	private function importWith(array $params): array {
		$controller = $this->controller(true);
		$this->request->method('getUploadedFile')->willReturn($params['__file'] ?? null);
		$this->request->method('getParams')->willReturn(['p' => 1]);
		$this->request->method('getParam')->willReturnCallback(fn (string $key) => ($params[$key] ?? null));
		$files = null;
		$this->service->method('getUploadedJson')->willReturnCallback(
			function (array $data, array $uploadedFiles) use (&$files): array {
				$this->assertSame(['p' => 1], $data);
				$files = $uploadedFiles;
				return ($data['__json'] ?? ['x-openregister' => []]);
			}
		);
		$created = null;
		$this->mapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$created): Configuration {
				$created = $data;
				return new Configuration();
			}
		);
		$force = null;
		$this->service->method('importFromJson')->willReturnCallback(
			function (...$args) use (&$force): array {
				$force = $args['force'] ?? $args[5];
				return ['registers' => [3, 3], 'schemas' => 'none', 'objects' => ['a']];
			}
		);
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(
			function (Configuration $configuration): Configuration {
				$this->assertSame([3], $configuration->getRegisters());
				$this->assertSame([], $configuration->getSchemas());
				$this->assertSame(['a'], $configuration->getObjects());
				return $configuration;
			}
		);

		$response = $controller->import();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Import successful', $response->getData()['message']);

		return [$created, $force, $files];
	}//end importWith()

	public function testImportDefaultsEveryDescriptorFieldAndTheOwnerToTheSignedInUser(): void {
		[$created, $force, $files] = $this->importWith([]);

		$this->assertSame('Imported Configuration', $created['title']);
		$this->assertSame('', $created['description']);
		$this->assertSame('1.0.0', $created['version']);
		$this->assertSame('unknown', $created['app']);
		$this->assertSame('alice', $created['owner']);
		$this->assertFalse($force);
		$this->assertSame([], $files);
	}

	public function testImportTakesAppAndOwnerFromTheRequestAndAcceptsBothForceSpellings(): void {
		[$created, $force, $files] = $this->importWith(['appId' => 'app1', 'owner' => 'bob', 'force' => 'true', '__file' => ['tmp_name' => 'f']]);
		$this->assertSame('app1', $created['app']);
		$this->assertSame('bob', $created['owner']);
		$this->assertTrue($force);
		$this->assertSame([['tmp_name' => 'f']], $files);

		$this->assertTrue($this->importWith(['force' => true])[1]);
		$this->assertFalse($this->importWith(['force' => 'yes'])[1]);
	}

	public function testImportAnswersAnErrorWith400(): void {
		$controller = $this->controller(true);
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('getUploadedJson')->willThrowException(new \TypeError('bad'));

		$response = $controller->import();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to import configuration: bad'], $response->getData());
	}
}//end class
