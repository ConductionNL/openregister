<?php

/**
 * A purpose-bound schema cannot be read by routing around the guard.
 *
 * `refuseUnboundPurpose()` had exactly two call sites: the single-schema branch
 * of `index()`, and `show()`. Two ways around it followed from that.
 *
 * `index()` returns into `crossTableSearch()` twenty lines BEFORE its own check
 * whenever the request names more than one schema or register — so naming one
 * extra, even unrelated, schema returned in full the rows the single-schema
 * request refuses with 403. And `objects()`, a `@PublicPage` list endpoint over
 * the same data, called the guard on no branch at all.
 *
 * The guard now sits inside `crossTableSearch()` rather than on its callers,
 * because that method IS the fan-out: a check on the caller has to be repeated,
 * correctly, by every future caller, and `objects()` is the second one already.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerDoelbindingReachTest extends TestCase {

	private PurposeGuard&MockObject $purposeGuard;

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	/**
	 * A register with magic mapping on for every schema, so crossTableSearch()
	 * builds a pair rather than answering "no valid combinations".
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$register->setConfiguration([
			'schemas' => [
				'persoon' => ['magicMapping' => true],
				'besluit' => ['magicMapping' => true],
			],
		]);

		return $register;
	}

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);

		return $schema;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->purposeGuard = $this->createMock(PurposeGuard::class);
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register());

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			fn ($id) => $this->schema(is_numeric($id) ? (int)$id : 4, (string)$id)
		);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container(),
			$registerMapper,
			$schemaMapper,
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			purposeGuard: $this->purposeGuard
		);
	}

	private ObjectsController $controller;

	/**
	 * A container that answers the one mapper the unrefused path reaches.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('searchAcrossMultipleTables')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($magicMapper);

		return $container;
	}

	private function refuse(): void {
		$this->purposeGuard->method('enforce')->willThrowException(
			new PurposeRefusedException(
				rule: 'doelbinding',
				reason: 'No processing purpose was declared for this read.',
				purpose: null
			)
		);
	}

	public function testASecondSchemaNoLongerLaundersAPurposeBoundOne(): void {
		// THE REGRESSION TEST. `?schemas={bound},anythingElse` used to return in
		// full the rows `GET /objects/{register}/{bound}` refuses with 403.
		$this->refuse();
		$this->request->method('getParams')->willReturn([
			'register' => 'zaken',
			'schema' => 'persoon',
			'schemas' => 'persoon,besluit',
		]);

		$response = $this->controller->objects($this->objectService);

		$this->assertSame(403, $response->getStatus());
	}

	public function testThePublicListEndpointIsGuardedOnItsSingleSchemaBranchToo(): void {
		// objects() is @PublicPage and called the guard on no branch at all.
		$this->refuse();
		$this->request->method('getParams')->willReturn([
			'register' => 'zaken',
			'schema' => 'persoon',
		]);
		$this->objectService->method('getRegister')->willReturn(7);
		$this->objectService->method('getSchema')->willReturn(4);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($this->register());
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($this->schema(4, 'persoon'));

		$response = $this->controller->objects($this->objectService);

		$this->assertSame(403, $response->getStatus());
	}

	public function testEveryNamedPairIsPutToTheGuardNotJustTheFirst(): void {
		// Refusal is all-or-nothing across the fan-out: a purpose-bound schema
		// must not become readable by listing it beside an unbound one, so every
		// pair the search will actually run over is checked.
		//
		// The assertion is on what the guard SAW rather than on the response.
		// An unrefused read continues into the cross-table search and its
		// collaborators, which this test deliberately does not stand up — the
		// behaviour under test is the reach of the guard, not the search.
		$seen = [];
		$this->purposeGuard->method('enforce')->willReturnCallback(
			function ($register, $schema) use (&$seen) {
				$seen[] = $schema?->getSlug();

				return null;
			}
		);
		$this->request->method('getParams')->willReturn([
			'register' => 'zaken',
			'schema' => 'persoon',
			'schemas' => 'persoon,besluit',
		]);

		try {
			$this->controller->objects($this->objectService);
		} catch (\Throwable $downstream) {
			unset($downstream);
		}

		$this->assertSame(['persoon', 'besluit'], $seen);
	}
}//end class
