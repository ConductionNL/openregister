<?php

/**
 * Characterisation of LinkedEntityController::removeObjectLink().
 *
 * Pins the three answers of the route: the remaining ids under `_<type>`, a
 * 403 for a write-permission denial, and a 400 carrying the message for any
 * other failure, logged with the object, type and entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\LinkedEntityController;
use OCA\OpenRegister\Service\EntityLink;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\LinkedEntityControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LinkedEntityRemoveLinkGoldenTest extends TestCase {

	/**
	 * Remove a link through a controller whose service answers with the given callback.
	 *
	 * @param callable             $answer The service's removeLink() behaviour.
	 * @param LoggerInterface|null $logger The logger, a stub when null.
	 *
	 * @return array{int, mixed} The status and the data.
	 */
	private function remove(callable $answer, ?LoggerInterface $logger = null): array {
		$service = $this->createMock(LinkedEntityService::class);
		$service->method('removeLink')->willReturnCallback($answer);

		$controller = LinkedEntityControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$service,
			($logger ?? $this->createMock(LoggerInterface::class)),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);
		$this->assertInstanceOf(LinkedEntityController::class, $controller);

		$response = $controller->removeObjectLink('uuid-1', 'deck', 'card-7');
		return [$response->getStatus(), $response->getData()];
	}//end remove()

	public function testTheRemainingIdsAreAnsweredUnderTheType(): void {
		$seen   = [];
		$answer = function (string $uuid, EntityLink $link) use (&$seen): array {
			$seen = [$uuid, $link->type, $link->entityId];
			return ['card-3'];
		};

		$this->assertSame([200, ['_deck' => ['card-3']]], $this->remove($answer));
		$this->assertSame(['uuid-1', 'deck', 'card-7'], $seen);
	}//end testTheRemainingIdsAreAnsweredUnderTheType()

	public function testAWriteDenialIsAForbidden(): void {
		$answer = static fn (): array => throw new NotAuthorizedException('No write permission on this object');

		$this->assertSame([403, ['error' => 'No write permission on this object']], $this->remove($answer));
	}//end testAWriteDenialIsAForbidden()

	public function testAnyOtherFailureIsABadRequestAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[LinkedEntityController] removeObjectLink failed',
			['uuid' => 'uuid-1', 'type' => 'deck', 'entityId' => 'card-7', 'error' => 'no such link']
		);
		$answer = static fn (): array => throw new RuntimeException('no such link');

		$this->assertSame([400, ['error' => 'no such link']], $this->remove($answer, $logger));
	}//end testAnyOtherFailureIsABadRequestAndIsLogged()
}//end class
