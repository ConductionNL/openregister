<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\MailMessageIdReverseLookup;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The mail-sidebar reverse lookup also matches a mail by its RFC `Message-ID`, exactly, and only
 * names objects the caller may read (reply-threading-by-headers 2.2).
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
#[CoversClass(MailMessageIdReverseLookup::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
final class MailMessageIdReverseLookupTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private array $calls = [];

	/**
	 * @param array<int, string>            $uuids    What the links answer for the id.
	 * @param array<string, ObjectEntity>   $readable The objects the caller may read, by uuid.
	 */
	private function lookup(array $uuids, array $readable = []): MailMessageIdReverseLookup {
		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->method('objectUuidsByRfcMessageId')->willReturnCallback(
			function (string $rfcMessageId) use ($uuids): array {
				$this->calls[] = ['links', $rfcMessageId];
				return $uuids;
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($readable): ?ObjectEntity {
				$this->calls[] = ['find', $id, $_rbac, $_multitenancy];
				if ($id === 'boom') {
					throw new RuntimeException('read failed');
				}

				return $readable[$id] ?? null;
			}
		);

		return new MailMessageIdReverseLookup(links: $links, objects: $objects, logger: new NullLogger());
	}

	private static function object(string $uuid, string $name): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setRegister('42');
		$object->setSchema('72');

		return $object;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function messageIds(): array {
		return [
			'with brackets'       => ['<a1@mail.example>', '<a1@mail.example>'],
			'without brackets'    => ['a1@mail.example', '<a1@mail.example>'],
			'url-decoded spacing' => [' <a1@mail.example> ', '<a1@mail.example>'],
			'slash in local part' => ['<x/y@mail.example>', '<x/y@mail.example>'],
		];
	}

	#[DataProvider('messageIds')]
	public function testAMessageIdIsReadWithItsAngleBrackets(string $entityId, string $expected): void {
		$this->assertSame($expected, $this->lookup(uuids: [])->messageIdOf(type: 'mail', entityId: $entityId));
		$this->assertSame($expected, $this->lookup(uuids: [])->messageIdOf(type: 'email', entityId: $entityId));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function notMessageIds(): array {
		return [
			'a Mail reference'    => ['mail', '1/42'],
			'no at sign'          => ['mail', '<a1.mail.example>'],
			'two at signs'        => ['mail', '<a@b@c>'],
			'empty local part'    => ['mail', '<@mail.example>'],
			'a space inside'      => ['mail', '<a 1@mail.example>'],
			'another linked type' => ['contacts', '<a1@mail.example>'],
		];
	}

	#[DataProvider('notMessageIds')]
	public function testAnythingElseIsNotAMessageId(string $type, string $entityId): void {
		$this->assertNull($this->lookup(uuids: [])->messageIdOf(type: $type, entityId: $entityId));
	}

	public function testOnlyTheReadableObjectsAreNamedAndEachIsReadUnderRbacAndTenancy(): void {
		$lookup = $this->lookup(
			uuids: ['abc-123', 'def-456'],
			readable: ['abc-123' => self::object(uuid: 'abc-123', name: 'Lindelaan')]
		);

		$response = $lookup->answer(messageId: '<a1@mail.example>');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					[
						'entityType' => 'object',
						'uuid'       => 'abc-123',
						'name'       => 'Lindelaan',
						'register'   => 42,
						'schemaId'   => 72,
						'matchedOn'  => 'Message-ID',
						'messageId'  => '<a1@mail.example>',
					],
				],
				'total' => 1,
			],
			$response->getData()
		);
		$this->assertSame(
			[
				['links', '<a1@mail.example>'],
				['find', 'abc-123', true, true],
				['find', 'def-456', true, true],
			],
			$this->calls
		);
	}

	public function testAFailedObjectReadIsNoRead(): void {
		$lookup = $this->lookup(
			uuids: ['boom', 'abc-123'],
			readable: ['abc-123' => self::object(uuid: 'abc-123', name: 'Lindelaan')]
		);

		$data = $lookup->answer(messageId: '<a1@mail.example>')->getData();

		$this->assertSame(1, $data['total']);
		$this->assertSame('abc-123', $data['results'][0]['uuid']);
	}

	public function testNoLinkIsAnEmptyAnswerNotAnError(): void {
		$response = $this->lookup(uuids: [])->answer(messageId: '<none@mail.example>');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
		$this->assertSame([['links', '<none@mail.example>']], $this->calls);
	}

	public function testALinkTableThatCannotBeReadIsA500WithoutItsMessage(): void {
		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->method('objectUuidsByRfcMessageId')->willThrowException(new RuntimeException('SQLSTATE secret'));
		$lookup = new MailMessageIdReverseLookup(
			links: $links,
			objects: $this->createMock(ObjectOperationsInterface::class),
			logger: new NullLogger()
		);

		$response = $lookup->answer(messageId: '<a1@mail.example>');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The mail links could not be read'], $response->getData());
	}
}
