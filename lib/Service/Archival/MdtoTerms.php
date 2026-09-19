<?php

/**
 * OpenRegister MDTO Terms
 *
 * The MDTO begrippenlijst terms openregister declares and exports, in one
 * place, so the check that refuses a term and the document that cites the list
 * cannot disagree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The MDTO terms a schema may declare, and the lists they come from.
 *
 * ## Why a term outside these lists is refused
 *
 * MDTO declares both of these begrippenlijsten OPEN, so the standard itself
 * permits other terms. openregister refuses them anyway, for a reason that is
 * about honesty rather than strictness: the exported element cites the list it
 * took the term from, as `begripBegrippenlijst/verwijzingNaam`. A term that is
 * not on the named list would make the document claim a provenance the term
 * does not have.
 *
 * The way to support a local term is therefore to let a schema name its own
 * begrippenlijst alongside it, not to drop the check. That is a change to the
 * annotation's shape and is not made here.
 *
 * {@see Appraisal} is the same idea for `waardering`, whose list MDTO does
 * declare closed.
 *
 * @psalm-suppress UnusedClass
 */
final class MdtoTerms {

	/**
	 * The begrippenlijst `aggregatieniveau` terms come from.
	 */
	public const AGGREGATION_LEVEL_LIST = 'Aggregatieniveaus';

	/**
	 * The terms of that list, as the Nationaal Archief publishes them.
	 *
	 * @var array<int, string>
	 */
	public const AGGREGATION_LEVELS = ['Archief', 'Serie', 'Dossier', 'Archiefstuk'];

	/**
	 * The begrippenlijst `beperkingGebruikType` terms come from.
	 */
	public const USE_RESTRICTION_LIST = 'BeperkingGebruikTypeLijst';

	/**
	 * The terms of that list, as the Nationaal Archief publishes them.
	 *
	 * `Nader te bepalen` is the term for a restriction that has not been
	 * recorded, which is what an export falls back to when nothing declares
	 * one; see MdtoXmlGenerator.
	 *
	 * @var array<int, string>
	 */
	public const USE_RESTRICTION_TYPES = ['Geen beperking', 'Nader te bepalen', 'Overig'];
}//end class
