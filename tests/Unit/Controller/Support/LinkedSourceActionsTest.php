<?php

/**
 * Unit test for LinkedSourceActions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use JsonSerializable;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The request-reading closures behind the link and unlink endpoints.
 *
 * @covers \OCA\OpenRegister\Controller\Support\LinkedSourceActions
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedSourceResponses
 * @uses   \OCA\OpenRegister\Db\LockTerms
 * @uses   \OCA\OpenRegister\Db\SoftDeletion
 */
class LinkedSourceActionsTest extends TestCase {
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		return $request;
	}

	private function link(array $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}

	public function testOptionalStringIsNullWhenAbsentAndTextOtherwise(): void {
		$request = $this->request(['n' => 12, 'search' => 'case']);

		$this->assertNull(LinkedSourceActions::optionalString($request, 'missing'));
		$this->assertSame('12', LinkedSourceActions::optionalString($request, 'n'));
		$this->assertSame('case', LinkedSourceActions::search($request));
		$this->assertNull(LinkedSourceActions::search($this->request([])));
	}

	public function testLinkByIdPassesTheObjectAndTheIntegerId(): void {
		$object = $this->createMock(ObjectEntity::class);
		$seen   = [];
		$action = (new LinkedSourceActions($this->request(['cardId' => '42'])))->linkById(
			'cardId',
			function (ObjectEntity $o, int $id) use (&$seen): JsonSerializable {
				$seen = [$o, $id];
				return $this->link(['linked' => $id]);
			}
		);

		$response = $action($object);

		$this->assertSame($object, $seen[0]);
		$this->assertSame(42, $seen[1]);
		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['linked' => 42], $response->getData());
	}

	public function testLinkByIdRefusesAMissingOrZeroIdWithoutLinking(): void {
		$called = false;
		$link   = static function () use (&$called): JsonSerializable {
			$called = true;
			throw new \LogicException('must not link');
		};

		foreach ([[], ['cardId' => '0'], ['cardId' => 'abc']] as $params) {
			$response = (new LinkedSourceActions($this->request($params)))->linkById('cardId', $link)($this->createMock(ObjectEntity::class));
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'cardId is required'], $response->getData());
		}

		$this->assertFalse($called);
	}

	public function testLinkByTextsPassesEveryTextInOrder(): void {
		$seen   = [];
		$action = (new LinkedSourceActions($this->request(['url' => 'https://x', 'title' => 'X'])))->linkByTexts(
			['url', 'title'],
			function (ObjectEntity $o, string ...$texts) use (&$seen): JsonSerializable {
				$seen = $texts;
				return $this->link(['ok' => true]);
			}
		);

		$this->assertSame(201, $action($this->createMock(ObjectEntity::class))->getStatus());
		$this->assertSame(['https://x', 'X'], $seen);
	}

	public function testLinkByTextsRefusesTheFirstBlankParameter(): void {
		$action = (new LinkedSourceActions($this->request(['url' => '  ', 'title' => ''])))->linkByTexts(
			['url', 'title'],
			static fn (): JsonSerializable => throw new \LogicException('must not link')
		);

		$response = $action($this->createMock(ObjectEntity::class));

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'url is required'], $response->getData());

		$second = (new LinkedSourceActions($this->request(['url' => 'u'])))->linkByTexts(
			['url', 'title'],
			static fn (): JsonSerializable => throw new \LogicException('must not link')
		);
		$this->assertSame(['error' => 'title is required'], $second($this->createMock(ObjectEntity::class))->getData());
	}

	public function testUnlinkPassesTheObjectUuid(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getUuid')->willReturn('uuid-1');
		$seen = null;

		$response = LinkedSourceActions::unlink(static function (string $uuid) use (&$seen): void {
			$seen = $uuid;
		})($object);

		$this->assertSame('uuid-1', $seen);
		$this->assertSame(['success' => true], $response->getData());
	}
}
