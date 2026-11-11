<?php

/**
 * Golden test pinning LinkedEntityController's add-link and reverse-lookup
 * answers: the admin gate, the required id, and the logged failures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\LinkedEntityController;
use OCA\OpenRegister\Service\EntityLink;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\LinkedEntityControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LinkedEntityAddLinkGoldenTest extends TestCase {

	private array $calls = [];

	private array $errors = [];

	private function controller(array $body, bool $admin, bool $fails = false): LinkedEntityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);

		$service = $this->createMock(LinkedEntityService::class);
		$record = function (string $method, string $uuid, EntityLink $link) use ($fails): array {
			$this->calls[] = [$method, $uuid, $link->type, $link->entityId];
			if ($fails === true) {
				throw new Exception('store down');
			}

			return ['e-1'];
		};
		$service->method('addLink')->willReturnCallback(fn (string $objectUuid, EntityLink $link) => $record('addLink', $objectUuid, $link));
		$service->method('addLinkToRegister')->willReturnCallback(fn (string $registerUuid, EntityLink $link) => $record('addLinkToRegister', $registerUuid, $link));
		$service->method('addLinkToSchema')->willReturnCallback(fn (string $schemaUuid, EntityLink $link) => $record('addLinkToSchema', $schemaUuid, $link));
		$service->method('reverseLookup')->willReturnCallback(
			function (string $type, string $entityId) use ($fails): array {
				$this->calls[] = ['reverseLookup', $type, $entityId];
				if ($fails === true) {
					throw new Exception('scan down');
				}

				return [['uuid' => 'o-1']];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return LinkedEntityControllerFactory::create('openregister', $request, $service, $logger, $session, $groups);
	}

	private static function answer($response): array {
		return [$response->getStatus(), $response->getData()];
	}

	public function testTheRegisterAndSchemaLinksAreAdminOnlyAndNeedAnId(): void {
		$adminOnly = [403, ['error' => 'Admin privileges required']];
		$this->assertSame($adminOnly, self::answer($this->controller(['id' => 'x'], false)->addRegisterLink('r-1', 'deck')));
		$this->assertSame($adminOnly, self::answer($this->controller(['id' => 'x'], false)->addSchemaLink('s-1', 'deck')));
		$this->assertSame($adminOnly, self::answer($this->controller([], false)->reverseLookup('deck', 'x')));

		$missing = [400, ['error' => 'Missing required field: id']];
		$this->assertSame($missing, self::answer($this->controller(['id' => ''], true)->addRegisterLink('r-1', 'deck')));
		$this->assertSame($missing, self::answer($this->controller([], true)->addSchemaLink('s-1', 'deck')));
		$this->assertSame($missing, self::answer($this->controller(['id' => null], false)->addObjectLink('o-1', 'deck')));

		$this->assertSame([], $this->calls);
	}

	public function testTheLinkIsStoredAndAnsweredUnderTheType(): void {
		$this->assertSame([200, ['_deck' => ['e-1']]], self::answer($this->controller(['id' => 7], false)->addObjectLink('o-1', 'deck')));
		$this->assertSame([200, ['_deck' => ['e-1']]], self::answer($this->controller(['id' => 7], true)->addRegisterLink('r-1', 'deck')));
		$this->assertSame([200, ['_deck' => ['e-1']]], self::answer($this->controller(['id' => 7], true)->addSchemaLink('s-1', 'deck')));
		$this->assertSame([200, ['results' => [['uuid' => 'o-1']], 'total' => 1]], self::answer($this->controller([], true)->reverseLookup('deck', '7')));

		$this->assertSame(
			[
				['addLink', 'o-1', 'deck', '7'],
				['addLinkToRegister', 'r-1', 'deck', '7'],
				['addLinkToSchema', 's-1', 'deck', '7'],
				['reverseLookup', 'deck', '7'],
			],
			$this->calls
		);
	}

	public function testFailuresAreBadRequestsAndTheObjectAndLookupOnesAreLogged(): void {
		$failed = [400, ['error' => 'store down']];
		$this->assertSame($failed, self::answer($this->controller(['id' => 7], false, true)->addObjectLink('o-1', 'deck')));
		$this->assertSame($failed, self::answer($this->controller(['id' => 7], true, true)->addRegisterLink('r-1', 'deck')));
		$this->assertSame($failed, self::answer($this->controller(['id' => 7], true, true)->addSchemaLink('s-1', 'deck')));
		$this->assertSame([400, ['error' => 'scan down']], self::answer($this->controller([], true, true)->reverseLookup('deck', '7')));

		$this->assertSame(
			[
				['[LinkedEntityController] addObjectLink failed', ['uuid' => 'o-1', 'type' => 'deck', 'error' => 'store down']],
				['[LinkedEntityController] reverseLookup failed', ['type' => 'deck', 'entityId' => '7', 'error' => 'scan down']],
			],
			$this->errors
		);
	}
}//end class
