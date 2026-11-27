<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\LinkedEntityController;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\LinkedEntityControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GET /api/linked/mail/{Message-ID}: the mail-sidebar reverse lookup also matches by the RFC
 * `Message-ID` when the Mail ids are unknown (reply-threading-by-headers 2.2). It answers the
 * objects the caller may read, for an administrator and anybody else alike, and never scans
 * the magic tables or asks for a Mail account.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class LinkedEntityMessageIdReverseLookupTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private array $calls = [];

	private function controller(bool $admin): LinkedEntityController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$service = $this->createMock(LinkedEntityService::class);
		$service->method('reverseLookup')->willReturnCallback(
			function (string $type, string $entityId): array {
				$this->calls[] = ['reverseLookup', $type, $entityId];
				return [];
			}
		);

		$emailLinks = $this->createMock(EmailLinkServiceInterface::class);
		$emailLinks->method('assertAccountOwnership')->willReturnCallback(
			function (int $accountId): void {
				$this->calls[] = ['assertAccountOwnership', $accountId];
			}
		);

		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->method('objectUuidsByRfcMessageId')->willReturnCallback(
			function (string $rfcMessageId): array {
				$this->calls[] = ['byMessageId', $rfcMessageId];
				return ['abc-123', 'def-456'];
			}
		);

		$readable = new ObjectEntity();
		$readable->setUuid('abc-123');
		$readable->setName('Lindelaan');
		$readable->setRegister('42');
		$readable->setSchema('72');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => $id === 'abc-123' ? $readable : null
		);

		return LinkedEntityControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$service,
			new NullLogger(),
			$session,
			$groups,
			$emailLinks,
			null,
			$links,
			$objects
		);
	}

	public function testANonAdminGetsTheReadableObjectsOfAMessageIdWithoutAnyScan(): void {
		$response = $this->controller(admin: false)->reverseLookup('mail', '<a1@mail.example>');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('abc-123', $response->getData()['results'][0]['uuid']);
		$this->assertSame('Message-ID', $response->getData()['results'][0]['matchedOn']);
		$this->assertSame([['byMessageId', '<a1@mail.example>']], $this->calls, 'No scan and no Mail account check.');
	}

	public function testAnAdministratorIsAnsweredTheSameWay(): void {
		$response = $this->controller(admin: true)->reverseLookup('email', 'a1@mail.example');

		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame([['byMessageId', '<a1@mail.example>']], $this->calls);
	}

	public function testAMailReferenceStillTakesTheMailAccountPath(): void {
		$this->controller(admin: false)->reverseLookup('mail', '1/42');

		$this->assertSame(['assertAccountOwnership', 1], $this->calls[0]);
		$this->assertSame(['reverseLookup', 'mail', '1/42'], $this->calls[1]);
	}

	public function testAnotherTypeWithAnAtSignStaysAdminOnly(): void {
		$response = $this->controller(admin: false)->reverseLookup('contacts', 'someone@mail.example');

		$this->assertSame([403, ['error' => 'Admin privileges required']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->calls);
	}
}//end class
