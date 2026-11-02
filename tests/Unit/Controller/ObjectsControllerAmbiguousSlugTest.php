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
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollector;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PresenceService;
use OCA\OpenRegister\Service\Hinge\ReferencedByService;
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

	/**
	 * A controller over an ObjectService whose identifier lookups raise the ambiguity.
	 *
	 * @param ObjectService $objectService The service the controller uses.
	 * @param bool          $admin         Whether the session user is an admin.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controller(ObjectService $objectService, bool $admin = true): ObjectsController {
		$user = $this->createMock(IUser::class);
		// IUser::getUID() has no return type on NC 32, so an unstubbed mock answers null there.
		$user->method('getUID')->willReturn('anna');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(($admin === true) ? ['admin'] : []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x']);
		$container = $this->createMock(ContainerInterface::class);
		$presence = $this->createMock(PresenceService::class);
		$presence->method('depart')->willReturn(true);
		$presence->method('present')->willReturn([]);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === PresenceService::class) ? $presence : $this->createMock(MagicMapper::class)
		);

		return new ObjectsController(
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
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);
	}//end controller()

	/**
	 * An ObjectService whose every identifier lookup finds the slug twice.
	 *
	 * @return ObjectService&\PHPUnit\Framework\MockObject\MockObject The service.
	 */
	private function ambiguousService(): ObjectService {
		$ambiguous = new MultipleObjectsReturnedException('Identifier "hello-world" matches more than one object in this register and schema');
		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(7);
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(4);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(7);
		$objectService->method('getSchema')->willReturn(4);
		$objectService->method('find')->willThrowException($ambiguous);
		$objectService->method('findSilent')->willThrowException($ambiguous);
		$objectService->method('deleteObject')->willThrowException($ambiguous);
		$objectService->method('lockObject')->willThrowException($ambiguous);
		$objectService->method('unlockObject')->willThrowException($ambiguous);
		$objectService->method('getObjectContracts')->willThrowException($ambiguous);
		$objectService->method('getObjectUses')->willThrowException($ambiguous);
		$objectService->method('getObjectUsedBy')->willThrowException($ambiguous);
		return $objectService;
	}//end ambiguousService()

	/**
	 * Assert the 409 body: names the identifier, none of the objects.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return void
	 */
	private function assertAmbiguous($response): void {
		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
		$this->assertStringContainsString('"hello-world"', $response->getData()['message']);
		$this->assertDoesNotMatchRegularExpression(
			'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
			json_encode($response->getData())
		);
	}//end assertAmbiguous()

	public function testDepartingFromAnAmbiguousIdentifierStillAnswers200WithoutAPush(): void {
		// Review finding pp-f3: the departure is done before the object is looked up for the push.
		$objectService = $this->ambiguousService();

		$response = $this->controller($objectService)->presenceDepart('zaken', 'persoon', 'hello-world');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['present' => []], $response->getData());
	}

	public function testADuplicatedSlugAnswers409WithoutNamingTheObjects(): void {
		$objectService = $this->ambiguousService();

		$response = $this->controller($objectService)->show('hello-world', 'zaken', 'persoon', $objectService);

		$this->assertAmbiguous($response);
	}

	/**
	 * Every route that resolves an identifier through ObjectService::find() answers the same 409
	 * (review findings bulk-f2 and r2routes-f5).
	 *
	 * @return array<string, array{0: string}> The route names.
	 */
	public static function identifierRoutes(): array {
		return [
			'update' => ['update'],
			'patch' => ['patch'],
			'postPatch' => ['postPatch'],
			'destroy' => ['destroy'],
			'canDelete' => ['canDelete'],
			'lock' => ['lock'],
			'unlock' => ['unlock'],
			'logs' => ['logs'],
			'contracts' => ['contracts'],
			'uses' => ['uses'],
			'used' => ['used'],
			'downloadFiles' => ['downloadFiles'],
			'referencedBy' => ['referencedBy'],
			'geoFeatures' => ['geoFeatures'],
			'move' => ['move'],
			'presenceBeat' => ['presenceBeat'],
			'presenceList' => ['presenceList'],
		];
	}//end identifierRoutes()

	/**
	 * @dataProvider identifierRoutes
	 */
	public function testEveryIdentifierRouteAnswers409ForADuplicatedSlug(string $route): void {
		$objectService = $this->ambiguousService();
		$controller = $this->controller($objectService);

		$response = match ($route) {
			'update' => $controller->update('zaken', 'persoon', 'hello-world', $objectService),
			'patch' => $controller->patch('zaken', 'persoon', 'hello-world', $objectService),
			'postPatch' => $controller->postPatch('zaken', 'persoon', 'hello-world', $objectService),
			'destroy' => $controller->destroy('hello-world', 'zaken', 'persoon', $objectService),
			'canDelete' => $controller->canDelete('hello-world', 'zaken', 'persoon', $objectService),
			'lock' => $controller->lock('zaken', 'persoon', 'hello-world'),
			'unlock' => $controller->unlock('zaken', 'persoon', 'hello-world'),
			'logs' => $controller->logs('hello-world', 'zaken', 'persoon', $objectService),
			'contracts' => $controller->contracts('hello-world', 'zaken', 'persoon', $objectService),
			'uses' => $controller->uses('hello-world', 'zaken', 'persoon', $objectService),
			'used' => $controller->used('hello-world', 'zaken', 'persoon', $objectService),
			// Review finding r2routes-f5: the routes that used to answer 500 or 404.
			'downloadFiles' => $controller->downloadFiles('hello-world', 'zaken', 'persoon', $objectService),
			'referencedBy' => $controller->referencedBy('hello-world', 'zaken', 'persoon', $objectService, $this->createMock(ReferencedByService::class)),
			'geoFeatures' => $controller->geoFeatures('hello-world', 'zaken', 'persoon', $objectService, $this->createMock(InheritedGeoCollector::class)),
			'move' => $controller->move('zaken', 'persoon', 'hello-world'),
			'presenceBeat' => $controller->presenceBeat('zaken', 'persoon', 'hello-world'),
			'presenceList' => $controller->presenceList('zaken', 'persoon', 'hello-world'),
		};

		$this->assertAmbiguous($response);
	}
	public function patchRoutes(): array {
		return [
			'patch' => ['patch'],
			'postPatch' => ['postPatch'],
		];
	}//end patchRoutes()

	/**
	 * Review finding (Robert, 30-10): the unfiltered PATCH lookup must not answer
	 * 409 when the caller can read none of the holders; it decides on the
	 * caller's own filtered set, as PUT does.
	 *
	 * @dataProvider patchRoutes
	 */
	public function testPatchDecidesAnAmbiguousSlugOnWhatTheCallerMayRead(string $route): void {
		$ambiguous = new MultipleObjectsReturnedException('Identifier "hello-world" matches more than one object in this register and schema');
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(7);
		$objectService->method('getSchema')->willReturn(4);
		$calls = [];
		$objectService->method('findSilent')->willReturnCallback(
			function (string $id, ?array $extend, bool $files, $register, $schema, bool $rbac, bool $multi) use ($ambiguous, &$calls) {
				$calls[] = [$rbac, $multi];
				if ($rbac === false) {
					throw $ambiguous;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('none readable');
			}
		);
		$controller = $this->controller($objectService, admin: false);

		$response = match ($route) {
			'patch' => $controller->patch('zaken', 'persoon', 'hello-world', $objectService),
			'postPatch' => $controller->postPatch('zaken', 'persoon', 'hello-world', $objectService),
		};

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([[false, false], [true, true]], $calls);
		$this->assertStringNotContainsString('ambiguous', json_encode($response->getData()));
	}
}//end class
