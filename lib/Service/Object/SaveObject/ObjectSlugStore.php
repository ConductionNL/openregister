<?php

/**
 * OpenRegister ObjectSlugStore
 *
 * The reads the slug rules need: who holds a slug, and which register an
 * object lives in (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use Throwable;

/**
 * Slug holders on the magic tables, and registers resolved once per request.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
class ObjectSlugStore {

	/**
	 * Registers resolved during this request, by id.
	 *
	 * @var array<string, Register|null>
	 */
	private array $registers = [];

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface    $magicMapper    Slug queries on the magic tables.
	 * @param RegisterMapperInterface $registerMapper Resolves the register of an entity.
	 * @param ObjectSlugLog  $log            Receives a register that does not resolve.
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly ObjectSlugLog $log,
	) {
	}//end __construct()

	/**
	 * The live object, other than the one asking, that holds a slug in a scope.
	 *
	 * @param ObjectSlugScope $scope       Register and schema (register resolved).
	 * @param string          $slug        The slug.
	 * @param string          $excludeUuid The object asking.
	 *
	 * @return string|null The holder's uuid, or null when the slug is free.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function holderOf(ObjectSlugScope $scope, string $slug, string $excludeUuid): ?string {
		if ($scope->register === null) {
			return null;
		}

		return ($this->magicMapper->findSlugHolders(register: $scope->register, schema: $scope->schema, slug: $slug, excludeUuid: $excludeUuid)[0] ?? null);
	}//end holderOf()

	/**
	 * Holders of some slugs and the stored slugs of some objects, in one round trip.
	 *
	 * @param ObjectSlugScope $scope Register and schema (register resolved).
	 * @param list<string>    $slugs Slugs to look up.
	 * @param list<string>    $uuids Objects whose stored slug is wanted.
	 *
	 * @return array{holders: array<string, list<string>>, stored: array<string, string|null>}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function state(ObjectSlugScope $scope, array $slugs, array $uuids): array {
		if ($scope->register === null) {
			return ['holders' => [], 'stored' => []];
		}

		return $this->magicMapper->findSlugState(register: $scope->register, schema: $scope->schema, slugs: $slugs, uuids: $uuids);
	}//end state()

	/**
	 * Resolve the register of an entity, once per request.
	 *
	 * @param int|string|null $registerId The entity's register id.
	 *
	 * @return Register|null Null when it does not resolve (the slug is then left empty).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function register(int|string|null $registerId): Register|null {
		if ($registerId === null || $registerId === '') {
			return null;
		}

		$key = (string)$registerId;
		if (array_key_exists($key, $this->registers) === false) {
			$this->registers[$key] = $this->lookup(key: $key);
		}

		return $this->registers[$key];
	}//end register()

	/**
	 * Find a register, logging the failure when it does not resolve.
	 *
	 * @param string $key The register id.
	 *
	 * @return Register|null The register, or null.
	 */
	private function lookup(string $key): Register|null {
		try {
			return $this->registerMapper->find(id: (int)$key, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->log->registerLookupFailed(registerId: $key, reason: $e->getMessage());
			return null;
		}
	}//end lookup()
}//end class
