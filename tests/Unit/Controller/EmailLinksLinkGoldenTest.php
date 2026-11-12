<?php

/**
 * Golden test pinning EmailLinksController::link: the update right is asked
 * first, then the required message fields, then the exact link handed over.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\EmailLinksController;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EmailLinksControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class EmailLinksLinkGoldenTest extends TestCase {

	private bool $mayUpdate = true;

	private array $linked = [];

	private function controller(array $params): EmailLinksController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setRegister(3);
		$object->setSchema(4);
		$objects->method('getObject')->willReturn($object);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action): bool => ($action === 'update' ? $this->mayUpdate : true)
		);

		$service = $this->createMock(EmailLinkService::class);
		$service->method('isMailAvailable')->willReturn(true);
		$service->method('linkEmail')->willReturnCallback(
			function (LinkTarget $target, EmailMessageRef $message): EmailLink {
				$this->linked[] = [$target->objectUuid, $target->registerId, $target->schemaId(), $message->mailAccountId, $message->messageId, $message->uidForMatch()];
				$link = $this->createMock(EmailLink::class);
				$link->method('jsonSerialize')->willReturn(['link' => 'email']);
				return $link;
			}
		);

		return EmailLinksControllerFactory::create('openregister', $request, $service, $objects, $permissions);
	}

	public function testWithoutTheUpdateRightTheParamsAreNotEvenChecked(): void {
		$this->mayUpdate = false;

		$response = $this->controller([])->link('reg', 'sch', 'uuid-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->linked);
	}

	public function testTheAccountAndMessageAreRequired(): void {
		$refusal = [400, ['error' => 'mailAccountId and messageId are required']];
		foreach ([[], ['mailAccountId' => '2'], ['messageId' => '<m@x>'], ['mailAccountId' => '0', 'messageId' => '<m@x>']] as $params) {
			$response = $this->controller($params)->link('reg', 'sch', 'uuid-1');
			$this->assertSame($refusal, [$response->getStatus(), $response->getData()]);
		}

		$this->assertSame([], $this->linked);
	}

	public function testALinkCarriesTheObjectTheMessageAndItsUid(): void {
		$response = $this->controller(['mailAccountId' => '2', 'messageId' => '<m@x>', 'messageUid' => '77'])->link('reg', 'sch', 'uuid-1');
		$this->controller(['mailAccountId' => '2', 'messageId' => '<m@x>'])->link('reg', 'sch', 'uuid-1');

		$this->assertSame([201, ['link' => 'email']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(
			[
				['uuid-1', 3, 4, 2, '<m@x>', (new EmailMessageRef(mailAccountId: 2, messageId: '<m@x>'))->withUid(uid: '77')->uidForMatch()],
				['uuid-1', 3, 4, 2, '<m@x>', (new EmailMessageRef(mailAccountId: 2, messageId: '<m@x>'))->withUid(uid: '')->uidForMatch()],
			],
			$this->linked
		);
	}
}//end class
