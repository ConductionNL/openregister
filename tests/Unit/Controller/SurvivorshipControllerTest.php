<?php

/**
 * SurvivorshipControllerTest
 *
 * Covers auth annotation presence, route-method reachability, set/clear
 * override behaviour, and RBAC/IDOR posture (an unauthorised caller gets
 * forbidden/not-found and no override is written) for
 * `SurvivorshipController::override()`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-survivorship-override/tasks.md#3.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SurvivorshipController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\SurvivorshipController
 */
class SurvivorshipControllerTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	private IUserSession&MockObject $userSession;

	private SurvivorshipController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new SurvivorshipController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->schemaMapper,
			new SourceRecordResolver($this->objectService, $this->schemaMapper, $this->createMock(LoggerInterface::class)),
			$this->userSession
		);
	}//end setUp()

	/**
	 * Build a readable object with the given payload.
	 *
	 * @param array<string, mixed> $data Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function objectWithData(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('organisations');
		$object->setSchema('organisation');
		$object->setObject($data);

		return $object;
	}//end objectWithData()

	/**
	 * ADR-029 / ADR-005: the endpoint must declare @NoAdminRequired +
	 * @NoCSRFRequired via docblock and must NOT be @PublicPage.
	 *
	 * @return void
	 */
	public function testOverrideCarriesAuthAnnotations(): void {
		$reflection = new ReflectionClass(SurvivorshipController::class);
		$doc = $reflection->getMethod('override')->getDocComment();

		$this->assertNotFalse($doc, 'override() must have a docblock.');
		$this->assertStringContainsString('@NoAdminRequired', $doc);
		$this->assertStringContainsString('@NoCSRFRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
	}//end testOverrideCarriesAuthAnnotations()

	/**
	 * Reachability (ADR-029): the route target method must exist.
	 *
	 * @return void
	 */
	public function testRouteTargetMethodExists(): void {
		$reflection = new ReflectionClass(SurvivorshipController::class);
		$this->assertTrue($reflection->hasMethod('override'));
	}//end testRouteTargetMethodExists()

	public function testSettingAnOverrideWritesItAndReturnsRecomputedObject(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['attribute', '', 'legalName'],
				['clear', false, false],
				['value', null, 'Steward Co'],
				['rationale', null, 'Confirmed with client'],
			]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$existing = $this->objectWithData(['legalName' => 'Gold Co']);
		$this->objectService->method('find')->willReturn($existing);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-survivorship' => ['overridesField' => 'attributeOverrides']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$saved = $this->objectWithData(
			[
				'legalName' => 'Gold Co',
				'attributeOverrides' => [
					'legalName' => ['value' => 'Steward Co', 'overriddenBy' => 'alice', 'rationale' => 'Confirmed with client'],
				],
				'goldenRecord' => ['legalName' => 'Steward Co'],
			]
		);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					function (ObjectEntity $object): bool {
						$data = $object->getObject();
						return $data['attributeOverrides']['legalName']['value'] === 'Steward Co'
							&& $data['attributeOverrides']['legalName']['overriddenBy'] === 'alice';
					}
				)
			)
			->willReturn($saved);

		$response = $this->controller->override('obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Steward Co', $data['goldenRecord']['legalName']);
		$this->assertSame('obj-1', $data['id']);
	}//end testSettingAnOverrideWritesItAndReturnsRecomputedObject()

	public function testClearingAnOverrideRemovesItAndFallsBackToTierResolution(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['attribute', '', 'legalName'],
				['clear', false, true],
				['value', null, null],
				['rationale', null, null],
			]
		);

		$this->userSession->method('getUser')->willReturn(null);

		$existing = $this->objectWithData(
			[
				'attributeOverrides' => [
					'legalName' => ['value' => 'Steward Co', 'overriddenBy' => 'alice'],
				],
				'goldenRecord' => ['legalName' => 'Steward Co'],
			]
		);
		$this->objectService->method('find')->willReturn($existing);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-survivorship' => ['overridesField' => 'attributeOverrides']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$saved = $this->objectWithData(['attributeOverrides' => [], 'goldenRecord' => ['legalName' => 'Gold Co']]);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					function (ObjectEntity $object): bool {
						$data = $object->getObject();
						return array_key_exists('legalName', $data['attributeOverrides']) === false;
					}
				)
			)
			->willReturn($saved);

		$response = $this->controller->override('obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Gold Co', $data['goldenRecord']['legalName']);
	}//end testClearingAnOverrideRemovesItAndFallsBackToTierResolution()

	public function testMissingAttributeParamIsRejected(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->objectService->expects($this->never())->method('find');

		$response = $this->controller->override('obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMissingAttributeParamIsRejected()

	public function testUnreadableObjectReturnsNotFound(): void {
		$this->request->method('getParam')->willReturnMap([['attribute', '', 'legalName']]);
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->override('missing-uuid');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnreadableObjectReturnsNotFound()

	public function testUnauthorisedWriteIsRejectedAndNoOverrideIsWritten(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['attribute', '', 'legalName'],
				['clear', false, false],
				['value', null, 'Steward Co'],
			]
		);
		$this->userSession->method('getUser')->willReturn(null);

		$existing = $this->objectWithData(['legalName' => 'Gold Co']);
		$this->objectService->method('find')->willReturn($existing);

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-survivorship' => []]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->objectService->method('saveObject')->willThrowException(
			new NotAuthorizedException(message: 'denied')
		);

		$response = $this->controller->override('obj-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUnauthorisedWriteIsRejectedAndNoOverrideIsWritten()

	public function testSourcesReturnsResolvedSources(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-survivorship' => ['sourceLinkField' => 'sources']]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$object = $this->objectWithData(
			['sources' => [['sourceSystem' => 'crm', 'mappedAttributes' => ['name' => 'Acme']]]]
		);
		$this->objectService->method('find')->willReturn($object);

		$response = $this->controller->sources('obj-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertCount(1, $data['sources']);
		$this->assertSame('crm', $data['sources'][0]['sourceSystem']);
	}//end testSourcesReturnsResolvedSources()

	public function testSourcesReturns404WhenObjectMissing(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->sources('missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSourcesReturns404WhenObjectMissing()

	public function testSourcesCarriesAuthAnnotations(): void {
		$reflection = new ReflectionClass(SurvivorshipController::class);
		$doc = $reflection->getMethod('sources')->getDocComment();

		$this->assertNotFalse($doc, 'sources() must have a docblock.');
		$this->assertStringContainsString('@NoAdminRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
	}//end testSourcesCarriesAuthAnnotations()
}//end class
