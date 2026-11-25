<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\LinkedEntityController;
use OCA\OpenRegister\Kernel\Contracts\ObjectReadCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
 * GET /api/linked/{type}/{entityId} for a caller who is not an administrator: mail in their own
 * Mail account only, and only the objects they may read (TODO #1273).
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-reverse-lookup-api-to-find-objects-by-mail-message-id
 */
class LinkedEntityOwnMailReverseLookupTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private array $calls = [];

	/**
	 * The rows the scan answers: a readable object, an unreadable one, and a schema row.
	 */
	private const ROWS = [
		['entityType' => 'object', 'uuid' => 'abc-123', 'register' => 42, 'schemaId' => 72, 'name' => 'Lindelaan'],
		['entityType' => 'object', 'uuid' => 'def-456', 'register' => 42, 'schemaId' => 72, 'name' => 'Secret'],
		['entityType' => 'schema', 'uuid' => 's-1', 'name' => 'Case'],
	];

	private function controller(bool $admin, int $ownAccount = 1): LinkedEntityController {
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
				return self::ROWS;
			}
		);

		$emailLinks = $this->createMock(EmailLinkServiceInterface::class);
		$emailLinks->method('assertAccountOwnership')->willReturnCallback(
			function (int $accountId) use ($ownAccount): void {
				$this->calls[] = ['assertAccountOwnership', $accountId];
				if ($accountId !== $ownAccount) {
					throw new Exception('Mail account not accessible to this user', 403);
				}
			}
		);

		$readAccess = $this->createMock(ObjectReadCheckInterface::class);
		$readAccess->method('isReadable')->willReturnCallback(
			function (ObjectRef $ref): bool {
				$this->calls[] = ['readable', $ref->register, $ref->schema, $ref->id];
				return $ref->id === 'abc-123';
			}
		);

		return LinkedEntityControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$service,
			new NullLogger(),
			$session,
			$groups,
			$emailLinks,
			$readAccess
		);
	}

	public function testOwnAccountGetsOnlyTheObjectsItMayRead(): void {
		$response = $this->controller(false)->reverseLookup('mail', '1/42');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [self::ROWS[0]], 'total' => 1], $response->getData());
		$this->assertSame(['assertAccountOwnership', 1], $this->calls[0], 'Ownership is checked before anything is scanned.');
		$this->assertSame(['reverseLookup', 'mail', '1/42'], $this->calls[1]);
		$this->assertSame(['readable', '42', '72', 'abc-123'], $this->calls[2]);
		$this->assertSame(['readable', '42', '72', 'def-456'], $this->calls[3]);
		$this->assertCount(4, $this->calls, 'The schema row is left out without a read check.');
	}

	public function testTheEmailIdIsAnsweredLikeMail(): void {
		$response = $this->controller(false)->reverseLookup('email', '1/42');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	public function testSomebodyElsesAccountIsRefusedBeforeAnyScan(): void {
		$response = $this->controller(false)->reverseLookup('mail', '2/42');

		$this->assertSame([403, ['error' => 'Mail account not accessible to this user']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['assertAccountOwnership', 2]], $this->calls);
	}

	public function testAReferenceWithoutAnAccountIsRefusedWithoutAnyLookup(): void {
		foreach (['e', '0/42', '1', 'x/42', '1/abc', ''] as $entityId) {
			$response = $this->controller(false)->reverseLookup('mail', $entityId);
			$this->assertSame(403, $response->getStatus(), $entityId);
		}

		$this->assertSame([], $this->calls);
	}

	public function testOtherTypesStayAdminOnly(): void {
		$response = $this->controller(false)->reverseLookup('deck', '1/42');

		$this->assertSame([403, ['error' => 'Admin privileges required']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->calls);
	}

	public function testAnAdministratorGetsTheFullScanUnchanged(): void {
		$response = $this->controller(true)->reverseLookup('mail', '9/42');

		$this->assertSame(['results' => self::ROWS, 'total' => 3], $response->getData());
		$this->assertSame([['reverseLookup', 'mail', '9/42']], $this->calls, 'No ownership or read checks for an admin.');
	}
}//end class
