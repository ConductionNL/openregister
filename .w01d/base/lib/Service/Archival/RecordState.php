<?php

/**
 * The Archiefwet record lifecycle, in one vocabulary.
 *
 * 🔴 THIS EXISTS BECAUSE `archiefstatus` MEANT TWO DIFFERENT THINGS UNDER ONE
 * NAME. Recorded as gap A4 in openspec/changes/archival-conformance:
 *
 *   - {@see \OCA\OpenRegister\Service\RetentionService} wrote
 *     `retention.archiefstatus` from `nog_te_archiveren` / `gearchiveerd` /
 *     `vernietigd` / `overgebracht`;
 *   - {@see \OCA\OpenRegister\Service\TmloService} wrote `tmlo.archiefstatus`
 *     from `actief` / `semi_statisch` / `overgebracht` / `vernietigd`.
 *
 * `nog_te_archiveren` is not in the TMLO set, so the TMLO validator would have
 * rejected the value the retention service wrote, into a field of the same
 * name. Nothing in the code said which vocabulary applied where, and the name
 * itself gave no clue.
 *
 * There is one lifecycle underneath: a record is live, then semi-static, then
 * either transferred to an e-Depot or destroyed. This class is that lifecycle,
 * named once, in English, alongside every other abstract archival key.
 *
 * READS ACCEPT THE OLD SPELLINGS, WRITES DO NOT. Stored data carries whatever
 * spelling was current when it was written, and there is no migration: a guard
 * that stopped recognising `overgebracht` would unlock every transferred record
 * in every existing install, and a destruction sweep that stopped recognising
 * `nog_te_archiveren` would silently skip every pre-existing record, which is
 * the direction that keeps personal data past its lawful term. So each state
 * carries an alias list holding its English name and the Dutch spellings it
 * replaces, and every comparison goes through the alias list.
 *
 * CONSTANTS ONLY, NO METHODS. phpmd's StaticAccess rule refuses static helper
 * calls, and a helper injected purely to compare two strings is not worth the
 * wiring, so the alias lists are the API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The Archiefwet lifecycle states, and every spelling that means each one.
 */
final class RecordState {

	/**
	 * Live. Still in use, not yet transferred and not yet destroyed.
	 */
	public const ACTIVE = 'active';

	/**
	 * Closed but still held by the creating organisation.
	 */
	public const SEMI_STATIC = 'semi_static';

	/**
	 * Handed to an e-Depot. Immutable from here.
	 */
	public const TRANSFERRED = 'transferred';

	/**
	 * Destroyed under a disposal decision. Immutable from here.
	 */
	public const DESTROYED = 'destroyed';

	/**
	 * The lifecycle, in order.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::ACTIVE,
		self::SEMI_STATIC,
		self::TRANSFERRED,
		self::DESTROYED,
	];

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

	/**
	 * Every spelling that means SEMI_STATIC.
	 *
	 * @var string[]
	 */
	public const SEMI_STATIC_ALIASES = ['semi_static', 'semi_statisch', 'gearchiveerd'];

	/**
	 * Every spelling that means TRANSFERRED.
	 *
	 * @var string[]
	 */
	public const TRANSFERRED_ALIASES = ['transferred', 'overgebracht'];

	/**
	 * Every spelling that means DESTROYED.
	 *
	 * @var string[]
	 */
	public const DESTROYED_ALIASES = ['destroyed', 'vernietigd'];

	/**
	 * Every spelling of a state a record cannot leave.
	 *
	 * A transferred record belongs to the e-Depot and a destroyed one is gone;
	 * neither may be edited or deleted again. Both spellings of both states
	 * are here, because an install that has not written a record since this
	 * vocabulary landed still holds the Dutch one.
	 *
	 * @var string[]
	 */
	public const IMMUTABLE_ALIASES = ['transferred', 'overgebracht', 'destroyed', 'vernietigd'];

	/**
	 * Every accepted spelling, mapped to the state it means.
	 *
	 * 🔴 ONE HOME, BECAUSE A SECOND COPY DRIFTS. ArchivalDecisionResolver
	 * carried its own private alias map and it had already drifted: it knew
	 * `actief`, `semi_statisch`, `overgebracht`, `vernietigd` and
	 * `nog_te_archiveren`, but NOT `gearchiveerd`, which this class has always
	 * listed as semi-static. A record stored with that spelling resolved to
	 * the raw Dutch word in `_retention.recordState`, so a consumer comparing
	 * against `semi_static` saw no match and the abstract layer, whose whole
	 * job is to hand out one vocabulary, handed out two.
	 *
	 * A spelling this map does not know passes through unchanged rather than
	 * being forced to a state nobody stored.
	 *
	 * @var array<string,string>
	 */
	public const CANONICAL = [
		'active' => self::ACTIVE,
		'actief' => self::ACTIVE,
		'nog_te_archiveren' => self::ACTIVE,
		'semi_static' => self::SEMI_STATIC,
		'semi_statisch' => self::SEMI_STATIC,
		'gearchiveerd' => self::SEMI_STATIC,
		'transferred' => self::TRANSFERRED,
		'overgebracht' => self::TRANSFERRED,
		'destroyed' => self::DESTROYED,
		'vernietigd' => self::DESTROYED,
	];

	/**
	 * Every accepted spelling of every state.
	 *
	 * @var string[]
	 */
	public const ALL_ALIASES = [
		'active',
		'actief',
		'nog_te_archiveren',
		'semi_static',
		'semi_statisch',
		'gearchiveerd',
		'transferred',
		'overgebracht',
		'destroyed',
		'vernietigd',
	];
}//end class
