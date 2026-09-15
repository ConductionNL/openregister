<?php

/**
 * The object's own version number, stamped on create and bumped on update.
 *
 * 🔴 THIS EXISTS BECAUSE NOTHING WROTE IT. `@self.version` has been declared
 * on the entity, serialized into every API response and documented as part of
 * the metadata envelope since the beginning, and a fleet-wide grep for
 * `setVersion(` found configurations, flows and migration packs but not one
 * call on an ObjectEntity outside `AuditTrailMapper::revertObject()`. The
 * legacy `oc_openregister_objects` table hid it: the column carries a DB
 * default of `0.0.1`, so every row read back a plausible-looking version that
 * had never moved. The magic per-schema tables have no such default, so there
 * the field is simply NULL and every consumer — the metadata modal, the audit
 * trail's own `version` column, any client diffing two reads — got nothing.
 *
 * A version that never changes is worse than no version, because it reads as
 * an answer. This class is the one place that answers it.
 *
 * The scheme is semver-shaped and patch-only: a save is a patch. Minor and
 * major moves are editorial decisions no save path can infer, so they are left
 * to whoever makes them, and a stored version carrying them is respected —
 * `1.4.2` bumps to `1.4.3`, not back onto a `0.0.x` line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stamps and bumps `@self.version` on an object entity.
 */
class ObjectVersionHandler {

	/**
	 * The version a newly created object starts on.
	 *
	 * Matches the `oc_openregister_objects.version` column default, so an
	 * object created through the legacy mapper and one created through the
	 * magic mapper agree rather than starting a digit apart.
	 *
	 * @var string
	 */
	public const INITIAL_VERSION = '0.0.1';

	/**
	 * Stamp the initial version on an object being created.
	 *
	 * A version already on the entity is kept: an import, a federation pull or
	 * a migration pack carries the source's version and overwriting it would
	 * lose the only record of where the object came from. Only an absent or
	 * unusable value is replaced.
	 *
	 * @param ObjectEntity $entity The entity being created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function stampInitialVersion(ObjectEntity $entity): void {
		$current = $entity->getVersion();
		if ($this->parse(version: $current) !== null) {
			return;
		}

		$entity->setVersion(self::INITIAL_VERSION);
	}//end stampInitialVersion()

	/**
	 * The version a row being INSERTED should start on.
	 *
	 * Used by the bulk write path, which builds column values directly rather
	 * than going through an entity. A candidate carried by the payload — an
	 * import or a federation pull bringing the source's version — is kept for
	 * the same reason {@see stampInitialVersion()} keeps one.
	 *
	 * Note what this method is NOT for: the UPDATE half of a bulk upsert. There
	 * `_version` is deliberately left out of the update set so an existing row
	 * keeps the version it reached, because a synchronisation pass that changed
	 * nothing must not advance a version.
	 *
	 * @param string|null $candidate The version the payload carried, if any.
	 *
	 * @return string The version to insert with.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function initialVersionFor(?string $candidate): string {
		if ($candidate !== null && trim($candidate) !== '') {
			return trim($candidate);
		}

		return self::INITIAL_VERSION;
	}//end initialVersionFor()

	/**
	 * Bump the patch component of an object's version on update.
	 *
	 * An entity whose stored version cannot be parsed — NULL on a magic-table
	 * row that predates this handler, or a free-text value written by an
	 * import — starts the sequence rather than being left behind, because a
	 * row that never gains a version is exactly the state this class exists to
	 * end.
	 *
	 * @param ObjectEntity $entity The entity being updated.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function bumpVersion(ObjectEntity $entity): void {
		$parts = $this->parse(version: $entity->getVersion());
		if ($parts === null) {
			$entity->setVersion(self::INITIAL_VERSION);
			return;
		}

		$parts[2] = ($parts[2] + 1);
		$entity->setVersion(implode('.', $parts));
	}//end bumpVersion()

	/**
	 * Parse a version string into its three integer components.
	 *
	 * Deliberately strict: only `major.minor.patch` with three non-negative
	 * integers is a version this handler will arithmetic on. Anything else —
	 * a date, a git sha, a two-part `1.0`, an empty string — is reported as
	 * unusable so the caller restarts the sequence rather than producing a
	 * value like `1.0.1` from a string that never meant that.
	 *
	 * @param string|null $version The stored version string.
	 *
	 * @return array{0: int, 1: int, 2: int}|null The components, or null when unusable.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function parse(?string $version): ?array {
		if ($version === null || trim($version) === '') {
			return null;
		}

		$parts = explode('.', trim($version));
		if (count($parts) !== 3) {
			return null;
		}

		$parsed = [];
		foreach ($parts as $part) {
			if (ctype_digit($part) === false) {
				return null;
			}

			$parsed[] = (int)$part;
		}

		return $parsed;
	}//end parse()

}//end class
