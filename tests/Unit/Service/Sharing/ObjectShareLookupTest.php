<?php

/**
 * Tests for ObjectShareLookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use OCA\OpenRegister\Service\Sharing\ObjectShareLookup;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The container lookups behind the share listing degrade to null.
 *
 * @covers \OCA\OpenRegister\Service\Sharing\ObjectShareLookup
 */
class ObjectShareLookupTest extends TestCase {
	/**
	 * A container that resolves the given services and throws for any other id.
	 *
	 * @param array<string,object> $services Services by id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('unknown ' . $id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	public function testLookupReturnsNullWhenTheContainerThrows(): void {
		$lookup = new ObjectShareLookup(container: $this->container(services: []));

		$this->assertNull($lookup->lookup(serviceName: 'OCP\\Share\\IManager'));
		$this->assertNull($lookup->currentUserId());
		$this->assertNull($lookup->objectEntity(objectUuid: 'abc'));
		$this->assertNull($lookup->objectFolder(objectUuid: 'abc'));
	}//end testLookupReturnsNullWhenTheContainerThrows()

	public function testCurrentUserIdReadsTheSessionUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$lookup = new ObjectShareLookup(container: $this->container(services: ['OCP\\IUserSession' => $session]));

		$this->assertSame('alice', $lookup->currentUserId());
	}//end testCurrentUserIdReadsTheSessionUser()

	public function testObjectFolderPassesTheResolvedEntity(): void {
		$entity = new \stdClass();
		$folder = $this->createMock(Folder::class);
		$mapper = new class($entity) {
			public function __construct(private object $entity) {
			}

			public function find(string $uuid): object {
				return $this->entity;
			}
		};
		$files = new class($folder) {
			public mixed $received = null;

			public function __construct(private Folder $folder) {
			}

			public function getObjectFolder(mixed $input): Folder {
				$this->received = $input;
				return $this->folder;
			}
		};

		$lookup = new ObjectShareLookup(container: $this->container(services: [
			'OCA\\OpenRegister\\Db\\MagicMapper' => $mapper,
			'OCA\\OpenRegister\\Service\\FileService' => $files,
		]));

		$this->assertSame($folder, $lookup->objectFolder(objectUuid: 'abc'));
		$this->assertSame($entity, $files->received);
	}//end testObjectFolderPassesTheResolvedEntity()
}//end class
