<?php

/**
 * Test doubles for the optional Nextcloud Collectives app (not installed in
 * the unit-test environment). The two OCA\Collectives class names only have
 * to exist for CollectiveLinkService's class_exists() probe; they are declared
 * empty, exactly as CollectiveLinkServiceTest declares them, so either file
 * may load first. The service doubles the container hands out live in this
 * test namespace and expose only methods the real Collectives services have.
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

namespace Unit\Service\Fixtures;

foreach (['CollectiveService', 'PageService'] as $collectivesStubClass) {
	if (class_exists('OCA\\Collectives\\Service\\' . $collectivesStubClass) === false) {
		eval('namespace OCA\\Collectives\\Service; class ' . $collectivesStubClass . ' {}');
	}
}

/**
 * Double of \OCA\Collectives\Service\CollectiveService.
 */
class CollectiveServiceDouble {
	public mixed $collectives = [];
	public array $calls = [];

	public function getCollectives(string $userId): mixed {
		$this->calls[] = $userId;
		if ($this->collectives instanceof \Throwable) {
			throw $this->collectives;
		}
		return $this->collectives;
	}
}

/**
 * Double of \OCA\Collectives\Service\PageService.
 */
class PageServiceDouble {
	/** @var array<int, mixed> collective id => page list or Throwable */
	public array $pagesByCollective = [];
	/** @var array<string, mixed> "collectiveId/pageId" => page or Throwable */
	public array $pageByKey = [];

	public function findAll(int $collectiveId, string $userId): mixed {
		$pages = $this->pagesByCollective[$collectiveId] ?? [];
		if ($pages instanceof \Throwable) {
			throw $pages;
		}
		return $pages;
	}

	public function find(int $collectiveId, int $id, string $userId): mixed {
		$page = $this->pageByKey[$collectiveId . '/' . $id] ?? new \RuntimeException('not in collective');
		if ($page instanceof \Throwable) {
			throw $page;
		}
		return $page;
	}
}

/**
 * A Collectives entity (collective or page info) whose getters return
 * configured values, or throw when unset or configured as a Throwable.
 */
class CollectivesEntityDouble {
	public function __construct(private array $fields) {
	}

	public function __call(string $name, array $args): mixed {
		$field = lcfirst(substr($name, 3));
		if (array_key_exists($field, $this->fields) === false) {
			throw new \BadMethodCallException($name);
		}
		$value = $this->fields[$field];
		if ($value instanceof \Throwable) {
			throw $value;
		}
		return $value;
	}
}
