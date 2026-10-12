<?php

/**
 * OpenRegister BuiltinPackSlugLockedException
 *
 * Raised when an update would change the slug (the pack document's own `id`)
 * of a built-in migration pack.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * An update refused because it renames a built-in migration pack.
 *
 * Apps that seed a built-in pack find it again by its slug. Renaming it would
 * make that lookup miss, and the next seed run would create a second copy
 * under the original slug, so the slug of a built-in pack is fixed.
 *
 * Extends InvalidPackDefinitionException (and so InvalidArgumentException),
 * so callers that already translate an invalid definition into a 422 keep
 * doing so; the error responder tells it apart to return its own error code.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
 */
class BuiltinPackSlugLockedException extends InvalidPackDefinitionException {
	/**
	 * Constructor.
	 *
	 * @param string $message Which built-in pack the update tried to rename.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
	 */
	public function __construct(string $message) {
		parent::__construct(errors: [$message]);
	}//end __construct()
}//end class
