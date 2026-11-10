<?php

/**
 * ObjectSearchHit — one object row found by a search, as the result
 * formatter sees it: the rendered object plus the display name of the app
 * that owns its register/schema pair.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * Immutable search hit: the rendered object and its owning app's label.
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 *
 * The uuid, register and schema are read from `@self` first and the object's
 * top level second, the same order the formatter always used.
 */
final class ObjectSearchHit {

	/**
	 * Constructor.
	 *
	 * @param array<string|int,mixed> $object   The rendered (RBAC-filtered) object.
	 * @param string|null             $appLabel The owning app's display name, or null.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $object,
		private readonly ?string $appLabel=null,
	) {
	}//end __construct()

	/**
	 * The same hit, owned by the app with the given display name.
	 *
	 * @param string|null $appLabel The owning app's display name, or null.
	 *
	 * @return self The hit carrying that label.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function withAppLabel(?string $appLabel): self {
		return new self($this->object, $appLabel);
	}//end withAppLabel()

	/**
	 * The rendered object.
	 *
	 * @return array<string|int,mixed> The object data.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function object(): array {
		return $this->object;
	}//end object()

	/**
	 * The owning app's display name, or null for an unclaimed pair.
	 *
	 * @return string|null The label.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function appLabel(): ?string {
		return $this->appLabel;
	}//end appLabel()

	/**
	 * The object's `@self` metadata, or an empty array when it has none.
	 *
	 * @return array<string,mixed> The metadata.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function selfData(): array {
		$selfData = $this->object['@self'] ?? [];
		if (is_array($selfData) === true) {
			return $selfData;
		}

		return [];
	}//end selfData()

	/**
	 * The register database id, 0 when unknown.
	 *
	 * @return int The register id.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function registerId(): int {
		return (int) ($this->selfData()['register'] ?? $this->object['register'] ?? 0);
	}//end registerId()

	/**
	 * The schema database id, 0 when unknown.
	 *
	 * @return int The schema id.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function schemaId(): int {
		return (int) ($this->selfData()['schema'] ?? $this->object['schema'] ?? 0);
	}//end schemaId()

	/**
	 * The object's uuid, empty when unknown.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function uuid(): string {
		return (string) ($this->selfData()['id'] ?? $this->object['id'] ?? '');
	}//end uuid()

	/**
	 * The resolved uuid, register and schema, in deep-link data order.
	 *
	 * @return array{uuid:string,register:int,schema:int} The ids.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function ids(): array {
		return ['uuid' => $this->uuid(), 'register' => $this->registerId(), 'schema' => $this->schemaId()];
	}//end ids()
}//end class
