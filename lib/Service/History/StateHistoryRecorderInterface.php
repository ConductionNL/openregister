<?php

/**
 * StateHistoryRecorderInterface: what callers outside the Service component use of StateHistoryProjector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\History
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

namespace OCA\OpenRegister\Service\History;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;

/**
 * The methods of StateHistoryProjector that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to StateHistoryProjector. Guarded by F05HistoryInterfaceBindingsTest.
 */
interface StateHistoryRecorderInterface {

	/**
	 * Record one transition.
	 *
	 * @param ObjectTransitionedEvent $move      The committed transition: its object, register and target state.
	 * @param Schema|null             $schema    The object's schema, or null when it cannot be resolved.
	 * @param DateTime                $stampedAt The moment of the move.
	 *
	 * @return bool True when an interval was written.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function record(ObjectTransitionedEvent $move, ?Schema $schema, DateTime $stampedAt): bool;
}//end interface
