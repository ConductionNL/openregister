<?php

/**
 * The share lookup degrades to null, or to the bare uuid, whenever a collaborator is missing or answers oddly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use OCA\OpenRegister\Service\Sharing\ObjectShareLookup;
use OCP\Files\Folder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Sharing\ObjectShareLookup
 */
class ObjectShareLookupFallbackTest extends TestCase {

	/**
	 * A container serving the given services and throwing for any other id.
	 *
	 * @param array<string, mixed> $services Services by id.
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('unknown ' . $id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * A file service recording what it was asked for.
	 *
	 * @param mixed $answer What getObjectFolder returns.
	 */
	private function files(mixed $answer): object {
		return new class($answer) {
			public array $received = [];

			public function __construct(private mixed $answer) {
			}

			public function getObjectFolder(mixed $input): mixed {
				$this->received[] = $input;
				return $this->answer;
			}
		};
	}//end files()

	public function testLookupReturnsTheServiceAndNullForANonObject(): void {
		$service = new \stdClass();
		$lookup  = new ObjectShareLookup(container: $this->container(['svc' => $service, 'scalar' => 'text']));

		$this->assertSame($service, $lookup->lookup(serviceName: 'svc'));
		$this->assertNull($lookup->lookup(serviceName: 'scalar'));
	}//end testLookupReturnsTheServiceAndNullForANonObject()

	public function testWithoutAMapperTheFolderIsAskedForByUuid(): void {
		$folder = $this->createMock(Folder::class);
		$files  = $this->files($folder);
		$lookup = new ObjectShareLookup(container: $this->container(['OCA\\OpenRegister\\Service\\FileService' => $files]));

		$this->assertSame($folder, $lookup->objectFolder(objectUuid: 'uuid-1'));
		$this->assertSame(['uuid-1'], $files->received);
	}//end testWithoutAMapperTheFolderIsAskedForByUuid()

	public function testANonFolderAnswerOrAThrowingFileServiceGivesNull(): void {
		$nonFolder = new ObjectShareLookup(
			container: $this->container(['OCA\\OpenRegister\\Service\\FileService' => $this->files('not a folder')])
		);
		$throwing  = new ObjectShareLookup(
			container: $this->container(
				[
					'OCA\\OpenRegister\\Service\\FileService' => new class {
						public function getObjectFolder(mixed $input): never {
							throw new RuntimeException('no folder');
						}
					},
				]
			)
		);

		$this->assertNull($nonFolder->objectFolder(objectUuid: 'uuid-1'));
		$this->assertNull($throwing->objectFolder(objectUuid: 'uuid-1'));
	}//end testANonFolderAnswerOrAThrowingFileServiceGivesNull()

	public function testObjectEntityIsNullForANonObjectOrAThrowingMapper(): void {
		$scalar   = new ObjectShareLookup(
			container: $this->container(
				[
					'OCA\\OpenRegister\\Db\\MagicMapper' => new class {
						public function find(string $uuid): ?object {
							return null;
						}
					},
				]
			)
		);
		$throwing = new ObjectShareLookup(
			container: $this->container(
				[
					'OCA\\OpenRegister\\Db\\MagicMapper' => new class {
						public function find(string $uuid): never {
							throw new RuntimeException('missing');
						}
					},
				]
			)
		);

		$this->assertNull($scalar->objectEntity(objectUuid: 'u'));
		$this->assertNull($throwing->objectEntity(objectUuid: 'u'));
	}//end testObjectEntityIsNullForANonObjectOrAThrowingMapper()

	public function testCurrentUserIdIsNullWithoutALoggedInUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->once())->method('getUser')->willReturn(null);
		$lookup = new ObjectShareLookup(container: $this->container(['OCP\\IUserSession' => $session]));

		$this->assertNull($lookup->currentUserId());
	}//end testCurrentUserIdIsNullWithoutALoggedInUser()
}//end class
