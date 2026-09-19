<?php

/**
 * What one core share declares about an OpenRegister object grant.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Share\IShare;
use Throwable;

/**
 * Reads the three things a share says about a grant: which object, which
 * extension verbs, and whether it travels to descendants.
 *
 * WHY THIS IS ITS OWN CLASS. ADR-010 rides OpenRegister's extra concepts in
 * core's share attribute bag, because core's share record has no field for a
 * concept core does not have. Reading that bag is fiddly in one direction
 * only: every read has to survive a share whose node has gone, an attribute
 * bag that is null, and a value stored as JSON by one writer and as an array
 * by another. All three answers therefore default to the SAFE side, and
 * keeping them together is what makes "safe" mean one thing.
 *
 * It holds no state and takes the share as an argument, so the resolver and
 * the sharing service can each own one without sharing anything but the
 * rules.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */
class ShareGrantAttributes {

	/**
	 * The attribute scope OpenRegister's extension verbs live under.
	 *
	 * @var string
	 */
	public const VERB_ATTRIBUTE_SCOPE = 'openregister';

	/**
	 * The attribute key holding the verb list.
	 *
	 * @var string
	 */
	public const VERB_ATTRIBUTE_KEY = 'verbs';

	/**
	 * The attribute key marking a grant as not travelling to descendants.
	 *
	 * @var string
	 */
	public const INHERITABLE_ATTRIBUTE_KEY = 'inheritable';

	/**
	 * Whether a grant travels to the object's descendants.
	 *
	 * Rides in the same attribute bag as the extension verbs, for the same
	 * reason ADR-010 puts them there: core's share record has no field for a
	 * concept core does not have.
	 *
	 * DEFAULTS TO TRUE, and that direction is the point. Every grant written
	 * before this flag existed meant "inheritable", because inheritance was
	 * how they were resolved; defaulting to false would silently remove access
	 * from every one of them, which is a lock-out nobody asked for and which
	 * would be blamed on the hierarchy change rather than on this one.
	 *
	 * Only an explicit, recognisable FALSE turns it off. A malformed value is
	 * read as inheritable rather than guessed at, so a typo cannot quietly
	 * narrow a grant either.
	 *
	 * @param IShare $share The share.
	 *
	 * @return bool False only when the grant is explicitly marked as local.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
	 */
	public function inheritableOf(IShare $share): bool {
		try {
			$attributes = $share->getAttributes();
			if ($attributes === null) {
				return true;
			}

			$raw = $attributes->getAttribute(
				self::VERB_ATTRIBUTE_SCOPE,
				self::INHERITABLE_ATTRIBUTE_KEY
			);
		} catch (Throwable $e) {
			return true;
		}

		if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
			return false;
		}

		return true;
	}//end inheritableOf()

	/**
	 * The extension verbs one share carries.
	 *
	 * @param IShare $share The share.
	 *
	 * @return string[] The verbs, empty when it carries none.
	 */
	public function verbsOf(IShare $share): array {
		try {
			$attributes = $share->getAttributes();
			if ($attributes === null) {
				return [];
			}

			$raw = $attributes->getAttribute(self::VERB_ATTRIBUTE_SCOPE, self::VERB_ATTRIBUTE_KEY);
		} catch (Throwable $e) {
			return [];
		}

		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(
			array_filter($raw, static fn ($verb) => is_string($verb) === true && $verb !== '')
		);
	}//end verbsOf()

	/**
	 * The object UUID a share grants, or null when it grants no object.
	 *
	 * An object's folder is named after its UUID — the convention
	 * `FolderManagementHandler` creates and `FileMapper::findOwningObjectUuid()`
	 * already relies on. A share on a FILE inside that folder is a file share
	 * and grants no object.
	 *
	 * @param IShare $share The share to inspect.
	 *
	 * @return string|null The granted object's UUID, or null.
	 */
	public function objectUuidOf(IShare $share): ?string {
		try {
			if ($share->getNodeType() !== 'folder') {
				return null;
			}

			// `getNode()` is typed to return a Node and `getName()` a string, so
			// neither is re-checked here — both throw instead when the node has
			// gone, which the catch below is for.
			$name = $share->getNode()->getName();
		} catch (Throwable $e) {
			// A share whose node has gone is not a grant. Core will clean it up.
			return null;
		}

		if ($name === '') {
			return null;
		}

		// Only accept something UUID-shaped. Register and schema folders sit in
		// the same tree, and admitting one of those by name would turn a share
		// of a CONTAINER into a grant on an object that merely shares its name.
		if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $name) !== 1) {
			return null;
		}

		return $name;
	}//end objectUuidOf()

}//end class
