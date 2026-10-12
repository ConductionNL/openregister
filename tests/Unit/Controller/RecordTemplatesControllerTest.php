<?php

/**
 * `/api/record-templates` names the caller and maps every refusal to its status.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RecordTemplatesController;
use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\RecordTemplatesController
 */
class RecordTemplatesControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var RecordTemplateService&MockObject
	 */
	private RecordTemplateService&MockObject $templates;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->templates = $this->createMock(RecordTemplateService::class);
	}//end setUp()

	/**
	 * Without a session every route answers 401 and the service is not asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testWithoutASessionIs401(): void {
		$this->templates->expects($this->never())->method('listFor');

		self::assertSame(401, $this->controller(userId: '')->index()->getStatus());
	}//end testWithoutASessionIs401()

	/**
	 * The index passes the register and schema filters and answers `{results, total}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
	 */
	public function testTheIndexFiltersBySchema(): void {
		$template = new RecordTemplate();
		$template->setName('Melding wateroverlast');
		$this->templates->expects($this->once())->method('listFor')
			->with($this->isInstanceOf(ViewerReach::class), null, 'meldingen')
			->willReturn([$template]);

		$response = $this->controller(userId: 'bert', params: ['schema' => 'meldingen'])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $response->getData()['total']);
		self::assertSame('Melding wateroverlast', $response->getData()['results'][0]['name']);
	}//end testTheIndexFiltersBySchema()

	/**
	 * A create answers 201; a refusal answers its own status with its findings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testRefusalsKeepTheirStatus(): void {
		$this->templates->method('create')->willReturnOnConsecutiveCalls(
			new RecordTemplate(),
			$this->throwException(new RecordTemplateRefused(status: 400, message: 'Not valid', findings: [['code' => 'name.required', 'message' => 'name is required.']]))
		);
		$this->templates->method('change')->willThrowException(new RecordTemplateRefused(status: 403, message: 'Not yours'));
		$this->templates->method('get')->willThrowException(new RecordTemplateRefused(status: 404, message: 'No such record template.'));

		$controller = $this->controller(userId: 'anna');
		self::assertSame(201, $controller->create()->getStatus());
		$refused = $controller->create();
		self::assertSame(400, $refused->getStatus());
		self::assertSame('name.required', $refused->getData()['findings'][0]['code']);
		self::assertSame(403, $controller->patch(id: 'tpl-1')->getStatus());
		self::assertSame(403, $controller->update(id: 'tpl-1')->getStatus());
		self::assertSame(404, $controller->show(id: 'tpl-1')->getStatus());
	}//end testRefusalsKeepTheirStatus()

	/**
	 * A delete answers 204, and an unexpected failure 500 without its message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function testDeleteAnd500(): void {
		$this->templates->expects($this->once())->method('delete')->with('tpl-1', $this->isInstanceOf(ViewerReach::class));
		$this->templates->method('listFor')->willThrowException(new RuntimeException('database password in here'));

		$controller = $this->controller(userId: 'anna');
		self::assertSame(204, $controller->destroy(id: 'tpl-1')->getStatus());
		$failed = $controller->index();
		self::assertSame(500, $failed->getStatus());
		self::assertStringNotContainsString('password', json_encode($failed->getData()));
	}//end testDeleteAnd500()

	/**
	 * The controller for a caller.
	 *
	 * @param string               $userId The caller, '' for none.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return RecordTemplatesController The controller.
	 */
	private function controller(string $userId, array $params=[]): RecordTemplatesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		$viewers = $this->createMock(ViewerReachResolver::class);
		$viewers->method('currentUid')->willReturn($userId);
		$viewers->method('reachOf')->willReturnCallback(static fn (string $userId): ViewerReach => new ViewerReach(userId: $userId, groups: [], isAdmin: false));

		return new RecordTemplatesController(
			appName: 'openregister',
			request: $request,
			templates: $this->templates,
			viewers: $viewers,
			logger: new NullLogger()
		);
	}//end controller()
}//end class
