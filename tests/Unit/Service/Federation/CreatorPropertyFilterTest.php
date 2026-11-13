<?php

/**
 * An object-scope share serves only the properties its creator may read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Federation\CreatorPropertyFilter;
use OCA\OpenRegister\Service\Federation\ShareCreatorCheck;
use OCA\OpenRegister\Service\Federation\ShareGrantorInterface;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Federation\CreatorPropertyFilter
 * @covers \OCA\OpenRegister\Service\Federation\ShareCreatorCheck
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks
 */
class CreatorPropertyFilterTest extends TestCase {

	/**
	 * The active user as the session double holds it.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	/**
	 * Uid that was active while the property rules ran.
	 *
	 * @var string|null
	 */
	private ?string $filteredAs = null;

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * An object-scope share created by the given user.
	 *
	 * @param string|null $owner The creator.
	 *
	 * @return FederatedShare The share.
	 */
	private function share(?string $owner): FederatedShare {
		$share = new FederatedShare();
		$share->setScope('object');
		$share->setPermissions('read');
		$share->setOwner($owner);
		return $share;
	}//end share()

	/**
	 * A stored object with a public and a restricted property.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setObject(['title' => 'Open', 'bsn' => '123456782']);
		return $object;
	}//end object()

	/**
	 * The filter over doubles. Only `alice` may read `bsn`.
	 *
	 * @param \Throwable|null $schemaFailure Thrown by the schema lookup, when set.
	 *
	 * @return CreatorPropertyFilter The filter.
	 */
	private function filter(?\Throwable $schemaFailure = null): CreatorPropertyFilter {
		$users = ['alice' => $this->user('alice'), 'bob' => $this->user('bob')];
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($users[$uid] ?? null));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->current = $user;
		});
		$session->expects($this->never())->method('setUser');

		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schemaFailure !== null) {
			$schemas->method('find')->willThrowException($schemaFailure);
		} else {
			$schemas->method('find')->willReturn(new Schema());
		}

		$properties = $this->createMock(PropertyRbacHandlerInterface::class);
		$properties->method('filterReadableProperties')->willReturnCallback(function (Schema $schema, array $object): array {
			$this->filteredAs = $this->current?->getUID();
			if ($this->filteredAs !== 'alice') {
				unset($object['bsn']);
			}

			return $object;
		});

		$check = new ShareCreatorCheck(
			grantor: $this->createMock(ShareGrantorInterface::class),
			userManager: $userManager,
			userSession: $session
		);

		return new CreatorPropertyFilter(creator: $check, schemas: $schemas, properties: $properties, logger: new NullLogger());
	}//end filter()

	/**
	 * A property the creator may not read is left out, judged as the creator, and the caller is restored.
	 *
	 * @return void
	 */
	public function testPropertyTheCreatorCannotReadIsLeftOut(): void {
		$object = $this->object();
		$served = $this->filter()->readable(share: $this->share('bob'), object: $object, rendered: $object->jsonSerialize());

		$this->assertSame('bob', $this->filteredAs);
		$this->assertSame('Open', $served['title']);
		$this->assertArrayNotHasKey('bsn', $served);
		$this->assertSame('obj-1', $served['@self']['id'] ?? $served['id']);
		$this->assertNull($this->current);
	}//end testPropertyTheCreatorCannotReadIsLeftOut()

	/**
	 * A property the creator may read is served.
	 *
	 * @return void
	 */
	public function testPropertyTheCreatorReadsIsServed(): void {
		$object = $this->object();
		$served = $this->filter()->readable(share: $this->share('alice'), object: $object, rendered: $object->jsonSerialize());

		$this->assertSame('123456782', $served['bsn']);
	}//end testPropertyTheCreatorReadsIsServed()

	/**
	 * Without a creator the object is served without properties.
	 *
	 * @return void
	 */
	public function testNoCreatorServesNoProperties(): void {
		$object = $this->object();
		$served = $this->filter()->readable(share: $this->share(null), object: $object, rendered: $object->jsonSerialize());

		$this->assertArrayNotHasKey('title', $served);
		$this->assertArrayNotHasKey('bsn', $served);
		$this->assertNull($this->filteredAs);
	}//end testNoCreatorServesNoProperties()

	/**
	 * A schema that does not resolve serves no properties.
	 *
	 * @return void
	 */
	public function testUnresolvedSchemaServesNoProperties(): void {
		$object = $this->object();
		$served = $this->filter(schemaFailure: new RuntimeException('gone'))->readable(
			share: $this->share('alice'),
			object: $object,
			rendered: $object->jsonSerialize()
		);

		$this->assertArrayNotHasKey('title', $served);
		$this->assertArrayNotHasKey('bsn', $served);
	}//end testUnresolvedSchemaServesNoProperties()
}//end class
