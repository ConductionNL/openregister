<?php

/**
 * ExplicitRevealInterface: the explicit-reveal keys, the per-request grant and the oracle-query refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;

/**
 * The methods of ExplicitReveal that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ExplicitRevealInterface {

	/**
	 * The authorization key that makes a property explicit-reveal.
	 *
	 * @var string
	 */
	public const KEY = 'reveal';

	/**
	 * The authorization value that makes a property explicit-reveal.
	 *
	 * @var string
	 */
	public const EXPLICIT = 'explicit';

	/**
	 * Let the read of one object see the named properties.
	 *
	 * @param string $objectId The object's id (uuid, or the id the route named).
	 * @param string $reveal   Property names, comma separated (the `_reveal` parameter).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function grantFor(string $objectId, string $reveal): void;

	/**
	 * Refuse a query that filters or sorts on an explicit-reveal property.
	 *
	 * A filter or a sort on a value that is only ever shown masked would let
	 * a reader learn it without the logged reveal (try values, read the order).
	 * Trusted server code may filter: it asked for the value on purpose.
	 *
	 * @param array<mixed> $query  The search query.
	 * @param Schema|null  $schema The schema searched.
	 *
	 * @return void
	 *
	 * @throws ExplicitRevealQueryException When the query names such a property.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function refuseOracleQuery(array $query, ?Schema $schema): void;
}//end interface
