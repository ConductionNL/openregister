<?php

/**
 * A slug held by two objects in one register and schema answers 409 (#4037).
 *
 * The mapper refuses a slug that matches more than one row with
 * MultipleObjectsReturnedException. show() caught only DoesNotExist and
 * NotAuthorized, so it escaped as an HTML 500. It now answers a JSON 409 that
 * names the identifier and neither object; the uuids only go to the log.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerAmbiguousSlugTest extends TestCase {

	public function testADuplicatedSlugAnswers409WithoutNamingTheObjects(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(7);
		$objectService->method('getSchema')->willReturn(4);
		$objectService->method('find')->willThrowException(
			new MultipleObjectsReturnedException('Identifier "hello-world" matches more than one object in this register and schema')
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(MagicMapper::class));

		$controller = new ObjectsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);

		$response = $controller->show('hello-world', 'zaken', 'persoon', $objectService);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
		$this->assertStringContainsString('"hello-world"', $response->getData()['message']);
		$this->assertDoesNotMatchRegularExpression(
			'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
			json_encode($response->getData())
		);
	}
}//end class
