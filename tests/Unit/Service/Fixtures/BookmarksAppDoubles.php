<?php

/**
 * Test doubles for the optional Nextcloud Bookmarks app (not installed in the
 * unit-test environment). The four OCA\Bookmarks class names only have to
 * exist for BookmarkLinkService::class_exists(); their shapes match the ones
 * BookmarkLinkServiceGoldenTest declares, so either file may load first.
 * The mapper and row doubles the container hands out live in this test
 * namespace and expose only the methods the real Bookmarks classes have.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\Bookmarks\Db;

if (class_exists(BookmarkMapper::class) === false) {
	class BookmarkMapper {
	}
}

if (class_exists(TagMapper::class) === false) {
	class TagMapper {
	}
}

if (class_exists(Bookmark::class) === false) {
	class Bookmark {
		public array $values = [];

		public function __call($name, $args) {
			$this->values[$name] = $args[0] ?? null;
			return null;
		}
	}
}

namespace OCA\Bookmarks;

if (class_exists(QueryParameters::class) === false) {
	class QueryParameters {
		public array $values = [];

		public function __call($name, $args) {
			$this->values[$name] = $args[0] ?? null;
			return $this;
		}
	}
}

namespace Unit\Service\Fixtures;

/**
 * A bookmark row as the Bookmarks mapper returns it.
 */
class BookmarkRowDouble {
	public function __construct(
		public int $id,
		public string $userId,
		public string $url,
		public string $title,
		public string $description,
		public ?int $added,
	) {
	}

	public function getId(): int {
		return $this->id;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function toArray(): array {
		return [
			'id' => $this->id,
			'url' => $this->url,
			'title' => $this->title,
			'description' => $this->description,
			'added' => $this->added,
		];
	}
}

/**
 * Double of \OCA\Bookmarks\Db\BookmarkMapper; results are configured per test.
 */
class BookmarkMapperDouble {
	public mixed $findResult = null;
	public mixed $findAllResult = [];
	public mixed $insertResult = null;
	public array $findAllCalls = [];
	public array $inserted = [];

	public function find(int $id): mixed {
		if ($this->findResult instanceof \Throwable) {
			throw $this->findResult;
		}
		return $this->findResult;
	}

	public function findAll(string $userId, object $params): mixed {
		$this->findAllCalls[] = [$userId, $params];
		if ($this->findAllResult instanceof \Throwable) {
			throw $this->findAllResult;
		}
		return $this->findAllResult;
	}

	public function insertOrUpdate(object $bookmark): mixed {
		$this->inserted[] = $bookmark;
		if ($this->insertResult instanceof \Throwable) {
			throw $this->insertResult;
		}
		return $this->insertResult;
	}
}

/**
 * Double of \OCA\Bookmarks\Db\TagMapper.
 */
class TagMapperDouble {
	public mixed $findByBookmarkResult = [];
	public array $addToCalls = [];

	public function findByBookmark(int $bookmarkId): mixed {
		if ($this->findByBookmarkResult instanceof \Throwable) {
			throw $this->findByBookmarkResult;
		}
		return $this->findByBookmarkResult;
	}

	public function addTo(array $tags, int $bookmarkId): void {
		$this->addToCalls[] = [$tags, $bookmarkId];
	}
}
