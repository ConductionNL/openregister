<?php

/**
 * One identity the objects search provider presents itself under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use JsonSerializable;

/**
 * An app's own face on the one objects search: its id, name, order and the pairs it claimed.
 *
 * The OpenRegister identity claims nothing itself: it stands for every
 * (register, schema) pair no app claimed, so each pair has exactly one
 * identity.
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
 */
final class SearchProviderIdentity implements JsonSerializable {

	/**
	 * The id of the identity that stands for every unclaimed pair.
	 *
	 * @var string
	 */
	public const OPENREGISTER = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param string             $id    The identity id: the claiming app's id in lower case, or `openregister`.
	 * @param string             $name  What the search bar calls it.
	 * @param int                $order Where it sorts among the providers; lower first.
	 * @param array<int, string> $pairs The claimed pairs as lower-case `register::schema` keys; empty for OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly int $order,
		public readonly array $pairs = [],
	) {
	}//end __construct()

	/**
	 * Whether this is the identity of the unclaimed pairs.
	 *
	 * @return bool True for the OpenRegister identity.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function isOpenRegister(): bool {
		return $this->id === self::OPENREGISTER;
	}//end isOpenRegister()

	/**
	 * Whether this identity claimed a register and schema.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug   The schema slug.
	 *
	 * @return bool True when the pair is one of this identity's claims.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function claims(string $registerSlug, string $schemaSlug): bool {
		return in_array(self::pairKey(registerSlug: $registerSlug, schemaSlug: $schemaSlug), $this->pairs, true);
	}//end claims()

	/**
	 * The key a pair is compared by: both slugs in lower case.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug   The schema slug.
	 *
	 * @return string The `register::schema` key.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public static function pairKey(string $registerSlug, string $schemaSlug): string {
		return strtolower(trim($registerSlug)).'::'.strtolower(trim($schemaSlug));
	}//end pairKey()

	/**
	 * The identity as a client reads it.
	 *
	 * @return array{id: string, name: string, order: int} The identity.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
	 */
	public function jsonSerialize(): array {
		return ['id' => $this->id, 'name' => $this->name, 'order' => $this->order];
	}//end jsonSerialize()
}//end class
