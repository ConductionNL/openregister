<?php

/**
 * The files leaf on an object: its fixed metadata, the object found through
 * the first mapper that resolves, only the nodes the restriction guard lets
 * through, each node flattened to its readable accessors, and an empty list
 * when nothing resolves or anything fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProviders;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * FilesProvider.
 */
class FilesProviderBehaviourTest extends TestCase {

	private FileFolderServiceInterface&MockObject $files;

	private FileRestrictionGuardInterface&MockObject $guard;

	/**
	 * Services by id; a Throwable fails the lookup.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	protected function setUp(): void {
		$this->files = $this->createMock(FileFolderServiceInterface::class);
		$this->guard = $this->createMock(FileRestrictionGuardInterface::class);
		$this->services[FileRestrictionGuardInterface::class] = $this->guard;
	}//end setUp()

	private function provider(): FilesProvider {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$service = ($this->services[$id] ?? new RuntimeException('unknown '.$id));
				if ($service instanceof \Throwable) {
					throw $service;
				}
				return $service;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new FilesProvider($this->files, $container, $l10n);
	}//end provider()

	private static function finder(?ObjectEntity $object): object {
		return new class ($object) {
			public array $asked = [];

			public function __construct(private ?ObjectEntity $object) {
			}

			public function find(string $id): ?ObjectEntity {
				$this->asked[] = $id;
				return $this->object;
			}
		};
	}//end finder()

	public function testItIsACoreAlwaysOnMagicColumnProvider(): void {
		$provider = $this->provider();

		$this->assertSame(
			['files', 'Files', 'Paperclip', 'core', null, 'magic-column', true],
			[$provider->getId(), $provider->getLabel(), $provider->getIcon(), $provider->getGroup(), $provider->getRequiredApp(), $provider->getStorageStrategy(), $provider->isEnabled()]
		);
	}//end testItIsACoreAlwaysOnMagicColumnProvider()

	public function testOnlyGuardApprovedNodesAreListedAndFlattened(): void {
		$object = new ObjectEntity();
		$finder = self::finder($object);
		$this->services['\OCA\OpenRegister\Db\ObjectEntityMapper'] = new RuntimeException('legacy mapper gone');
		$this->services[MagicObjectFinderInterface::class] = $finder;
		$all = ['all-nodes'];
		$node = new class {
			public function getId(): int {
				return 7;
			}

			public function getName(): string {
				return 'a.pdf';
			}

			public function getPath(): string {
				throw new RuntimeException('unmounted');
			}

			public function getSize(): int {
				return 12;
			}
		};
		$this->files->expects($this->once())->method('getFilesForEntity')->with($this->identicalTo($object))->willReturn($all);
		$this->guard->expects($this->once())->method('readableNodes')->with($all)
			->willReturn([$node, ['id' => 8, 'name' => 'raw'], 'skip-me']);

		$rows = $this->provider()->list('r', 's', 'obj-1');

		$this->assertSame([['id' => 7, 'name' => 'a.pdf', 'size' => 12], ['id' => 8, 'name' => 'raw']], $rows);
		$this->assertSame(['obj-1'], $finder->asked);
	}//end testOnlyGuardApprovedNodesAreListedAndFlattened()

	public function testAServiceWithoutFindIsSkippedForTheNextCandidate(): void {
		$object = new ObjectEntity();
		$this->services[MagicObjectFinderInterface::class] = new \stdClass();
		$this->services['\OCA\OpenRegister\Db\MagicMapperInterface'] = self::finder($object);
		$this->files->method('getFilesForEntity')->willReturn([]);
		$this->guard->method('readableNodes')->willReturn([]);

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAServiceWithoutFindIsSkippedForTheNextCandidate()

	public function testWhenNoMapperResolvesTheObjectNothingIsListed(): void {
		$this->files->expects($this->never())->method('getFilesForEntity');

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testWhenNoMapperResolvesTheObjectNothingIsListed()

	public function testAMapperFindingNothingListsNothing(): void {
		$this->services['\OCA\OpenRegister\Db\AbstractObjectMapper'] = self::finder(null);
		$this->files->expects($this->never())->method('getFilesForEntity');

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAMapperFindingNothingListsNothing()

	public function testAFailingFileLookupListsNothing(): void {
		$this->services['\OCA\OpenRegister\Db\ObjectEntityMapper'] = self::finder(new ObjectEntity());
		$this->files->method('getFilesForEntity')->willThrowException(new RuntimeException('no folder'));

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAFailingFileLookupListsNothing()

	public function testCreatingAFileHereIsNotImplemented(): void {
		$this->expectException(NotImplementedException::class);
		$this->expectExceptionMessage('FilesProvider write path delegates to FileController');

		$this->provider()->create('r', 's', 'obj-1', []);
	}//end testCreatingAFileHereIsNotImplemented()
}//end class
