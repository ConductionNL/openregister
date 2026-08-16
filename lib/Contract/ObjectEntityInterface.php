<?php

/**
 * The object surface OpenRegister publishes to consuming apps.
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

use JsonSerializable;

/**
 * A stored OpenRegister object, as a consuming app sees it.
 *
 * Scoped to what the fleet actually calls. Measured 2026-08-14 across the
 * fifteen consuming apps:
 *
 *     jsonSerialize   282     getRegister      29
 *     getObject       280     getId            18
 *     getUuid         117     getOrganisation  11
 *     getSchema        61     getOwner          5
 *
 * `OCA\OpenRegister\Db\ObjectEntity` implements this. A consuming app
 * type-hints the interface, never the entity: the entity lives in an app that
 * is absent from a leaf app's unit-test environment, and a return type that
 * cannot load is the whole reason this package exists.
 */
interface ObjectEntityInterface extends JsonSerializable {

	/**
	 * The object's UUID.
	 *
	 * Nullable because the backing property is `?string` — an entity that has
	 * not been persisted has no UUID yet. Declaring `string` here would make
	 * OpenRegister's own class illegal, since a wider implementation return is
	 * not permitted.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string;

	/**
	 * The stored object payload.
	 *
	 * `array-key`, not `string`. The implementation returns
	 * `array{id: …, ...<array-key, mixed>}` — the payload's own keys come from
	 * stored JSON and are not guaranteed to be strings. Declaring `string` here
	 * makes the interface MORE specific than the class implementing it, which
	 * psalm rejects (LessSpecificImplementedReturnType).
	 *
	 * @return array<array-key, mixed>
	 */
	public function getObject(): array;

	/**
	 * The register this object belongs to, as its identifier.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string;

	/**
	 * The schema this object conforms to, as its identifier.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string;

	/**
	 * The owning organisation, when the object carries one.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string;

	/**
	 * The owner's user id, when the object carries one.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string;
}
