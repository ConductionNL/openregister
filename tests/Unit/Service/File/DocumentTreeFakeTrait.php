<?php

/**
 * An in-memory file tree of Files API doubles for the document store move tests.
 *
 * Folders and files keep their children and bytes in shared state, so a copy the
 * move writes can be read back, found by id and checked by SHA-256 exactly as
 * the move checks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test doubles.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock builders use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use ArrayObject;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;

/**
 * Builds Folder and File doubles over shared state.
 */
trait DocumentTreeFakeTrait {

	/**
	 * Every node made, by id.
	 *
	 * @var array<int, Node>
	 */
	private array $nodes = [];

	/**
	 * Every node's state (name, path, bytes, children), by id.
	 *
	 * @var array<int, ArrayObject>
	 */
	private array $state = [];

	/**
	 * The next id a created node gets.
	 */
	private int $nextId = 9000;

	/**
	 * Paths of deleted nodes.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * Paths nodes were moved to, by old path.
	 *
	 * @var array<string, string>
	 */
	private array $movedTo = [];

	/**
	 * Bytes appended to every file created under a folder with this storage id: a corrupted transfer.
	 *
	 * @var array<string, string>
	 */
	private array $corrupt = [];

	private function storage(string $id): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($id);
		return $storage;
	}//end storage()

	private function fakeFile(int $id, string $path, string $bytes, string $storageId = 'local::/data/'): File {
		$state = new ArrayObject(['name' => basename($path), 'path' => $path, 'bytes' => $bytes, 'storage' => $storageId, 'writes' => []]);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturnCallback(fn () => $state['name']);
		$file->method('getPath')->willReturnCallback(fn () => $state['path']);
		$file->method('getStorage')->willReturn($this->storage(id: $storageId));
		$file->method('fopen')->willReturnCallback(fn () => self::stream(bytes: $state['bytes']));
		$file->method('putContent')->willReturnCallback(function ($data) use ($state): void {
			$bytes = is_resource($data) ? stream_get_contents($data) : (string)$data;
			$state['writes'] = array_merge($state['writes'], [$bytes]);
			$state['bytes'] = $bytes . ($this->corrupt[$state['storage']] ?? '');
		});
		$file->method('delete')->willReturnCallback(function () use ($state): void {
			$this->deleted[] = $state['path'];
		});
		$this->nodes[$id] = $file;
		$this->state[$id] = $state;
		return $file;
	}//end fakeFile()

	/**
	 * @param list<Node> $children
	 */
	private function fakeFolder(int $id, string $path, array $children = [], string $storageId = 'local::/data/'): Folder {
		$state = new ArrayObject(['name' => basename($path), 'path' => $path, 'children' => $children, 'storage' => $storageId]);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturnCallback(fn () => $state['name']);
		$folder->method('getPath')->willReturnCallback(fn () => $state['path']);
		$folder->method('getStorage')->willReturn($this->storage(id: $storageId));
		$folder->method('getDirectoryListing')->willReturnCallback(fn () => $state['children']);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name) => $this->childAt(state: $state, path: $name) !== null);
		$folder->method('get')->willReturnCallback(function (string $name) use ($state): Node {
			$child = $this->childAt(state: $state, path: $name);
			if ($child === null) {
				throw new NotFoundException($name);
			}

			return $child;
		});
		$folder->method('newFolder')->willReturnCallback(function (string $name) use ($state): Folder {
			$child = $this->fakeFolder(id: $this->nextId++, path: $state['path'] . '/' . $name, storageId: $state['storage']);
			$state['children'] = array_merge($state['children'], [$child]);
			return $child;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($state): File {
			$bytes = is_resource($content) ? stream_get_contents($content) : (string)$content;
			$child = $this->fakeFile(id: $this->nextId++, path: $state['path'] . '/' . $name, bytes: $bytes . ($this->corrupt[$state['storage']] ?? ''), storageId: $state['storage']);
			$this->state[$child->getId()]['writes'] = [$bytes];
			$state['children'] = array_merge($state['children'], [$child]);
			return $child;
		});
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $nodeId) => ($this->nodes[$nodeId] ?? null));
		$folder->method('move')->willReturnCallback(function (string $target) use ($state, $folder): Node {
			$this->movedTo[$state['path']] = $target;
			return $folder;
		});
		$folder->method('delete')->willReturnCallback(function () use ($state): void {
			$this->deleted[] = $state['path'];
		});
		$this->nodes[$id] = $folder;
		$this->state[$id] = $state;
		return $folder;
	}//end fakeFolder()

	/**
	 * A child of a folder state by a relative path.
	 */
	private function childAt(ArrayObject $state, string $path): ?Node {
		$segments = explode('/', trim($path, '/'));
		$current = $state;
		$node = null;
		foreach ($segments as $segment) {
			$node = null;
			foreach (($current['children'] ?? []) as $child) {
				if ($this->state[$child->getId()]['name'] === $segment) {
					$node = $child;
					break;
				}
			}

			if ($node === null) {
				return null;
			}

			$current = $this->state[$node->getId()];
		}

		return $node;
	}//end childAt()

	/**
	 * The bytes a node holds now.
	 */
	private function bytesOf(Node $node): string {
		return $this->state[$node->getId()]['bytes'];
	}//end bytesOf()

	/**
	 * The writes a file received, in order.
	 *
	 * @return list<string>
	 */
	private function writesOf(Node $node): array {
		return $this->state[$node->getId()]['writes'];
	}//end writesOf()

	/**
	 * @return resource
	 */
	private static function stream(string $bytes) {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $bytes);
		rewind($stream);
		return $stream;
	}//end stream()
}//end trait
