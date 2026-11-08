<?php

/**
 * RecordStateInterface: the record-state values callers outside the component compare against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The Archiefwet lifecycle values read outside the component.
 *
 * RecordState implements this, so `RecordState::DESTROYED` and
 * `RecordState::ACTIVE_ALIASES` stay the one home of these values.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
interface RecordStateInterface {

	/**
	 * Destroyed under a disposal decision. Immutable from here.
	 */
	public const DESTROYED = 'destroyed';

	/**
	 * Every spelling that means ACTIVE.
	 *
	 * `nog_te_archiveren` is RetentionService's own word for a record still to
	 * be archived, which is exactly a record that is live and not yet
	 * transferred. `actief` is TmloService's.
	 *
	 * @var string[]
	 */
	public const ACTIVE_ALIASES = ['active', 'actief', 'nog_te_archiveren'];
}//end interface
