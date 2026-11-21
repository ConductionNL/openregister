<?php

/**
 * OpenRegister StateFieldSaveRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One save being checked against its schema's state field rules.
 *
 * Built by {@see StateFieldRuleListener} once the schema resolved; it never
 * leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class StateFieldSaveRun {

	/**
	 * The object as it would be saved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $newObject = null;

	/**
	 * The object as currently stored, null on a create.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $oldObject = null;

	/**
	 * The object's schema.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The schema's slug, or the empty string when it has none.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function slug(): string {
		return (string)($this->schema?->getSlug() ?? '');
	}//end slug()
}//end class
