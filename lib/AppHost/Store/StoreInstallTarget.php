<?php

/**
 * OpenRegister AppHost — Store Install Target
 *
 * The optional `installTarget` of a store block: which property of which
 * installed objects records the target an administrator installs into.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\Kernel\Contracts\Value\BundleObjectStamp;

/**
 * Where an install can go: the property stamped and the schemas it is stamped on.
 *
 * An app declares, for example, `{"property": "setting", "schemas":
 * ["larping_skill", "condition", "effect"]}`: an install with a `target`
 * then writes that id into `setting` on every installed skill, condition
 * and effect. Without a declaration a `target` is refused, never ignored.
 *
 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
 */
final class StoreInstallTarget {

	/**
	 * A target id: a UUID, a numeric id or a slug. Nothing that could be a path or a query.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

	/**
	 * Constructor.
	 *
	 * @param string             $property The object property the target id is written to.
	 * @param array<int, string> $schemas  The schema slugs whose installed objects get it.
	 */
	public function __construct(
		public readonly string $property = '',
		public readonly array $schemas = [],
	) {
	}//end __construct()

	/**
	 * Whether the app declared where an install can go.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function isDeclared(): bool {
		return $this->property !== '' && $this->schemas !== [];
	}//end isDeclared()

	/**
	 * Whether a requested target id is well-formed: a UUID, a number or a slug.
	 *
	 * @param string $target The id from the install request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function isWellFormed(string $target): bool {
		return preg_match(self::ID_PATTERN, $target) === 1;
	}//end isWellFormed()

	/**
	 * The stamp that writes one target into the declared property.
	 *
	 * @param string $target The chosen target id; '' stamps nothing.
	 *
	 * @return BundleObjectStamp
	 *
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function stampFor(string $target): BundleObjectStamp {
		if ($this->isDeclared() === false) {
			return new BundleObjectStamp(property: '', schemas: [], value: '');
		}

		return new BundleObjectStamp(property: $this->property, schemas: $this->schemas, value: $target);
	}//end stampFor()
}//end class
