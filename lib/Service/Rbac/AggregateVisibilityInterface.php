<?php

/**
 * AggregateVisibilityInterface: whether an aggregate may summarise a property.
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

/**
 * The methods of AggregateVisibility that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface AggregateVisibilityInterface {

	/**
	 * Whether a summary over this property may be shown.
	 *
	 * 🔑 THE CHECK PASSES AN EMPTY OBJECT, WHICH IS NOT AN OVERSIGHT. An
	 * aggregate is not about one record: it asks whether this property is
	 * readable AT ALL for this caller, not whether it is readable on some
	 * particular row. So a CONDITIONAL rule, one that depends on a record's
	 * contents, does not admit the aggregate.
	 *
	 * That is a real restriction and it is the safe direction: a property
	 * readable only on rows the caller owns is not summarisable by them,
	 * because a summary spans rows they do not own.
	 *
	 * FAILS CLOSED. With no way to ask, the summary is withheld, because the
	 * alternative is showing one whose access nobody checked.
	 *
	 * @param Schema|null $schema   The schema the property belongs to.
	 * @param string      $property The property name.
	 *
	 * @return bool Whether the summary may be shown.
	 *
	 * @spec openspec/changes/aggregate-paths-ask-permission/specs/rbac-scopes/spec.md
	 */
	public function maySummarise(?Schema $schema, string $property): bool;

	/**
	 * Whether this caller could read this property on some row.
	 *
	 * An output field cannot come and go per row, so a generated type keeps a property
	 * a conditional rule may show this caller. Fails closed, as maySummarise() does.
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool Whether some row could show it.
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	public function mayReadOnSomeRow(Schema $schema, string $property): bool;

	/**
	 * Whether the VALUES of this property may be summarised: grouped on (a
	 * bucket key is a value), taken a min or max of, or drawn as a heading.
	 *
	 * 🔴 AN EXPLICIT-REVEAL PROPERTY IS WITHHELD FOR EVERY READER, administrators
	 * included, even when its read rule admits them. Its value is shown only
	 * through the single-object reveal, which is recorded; a bucket key would be
	 * the same value with no record. `maySummarise()` stays the read-rule answer
	 * because the OpenAPI and GraphQL descriptions ask it whether the property
	 * may be NAMED, and a masked property may be named.
	 *
	 * @param Schema|null $schema   The schema the property belongs to.
	 * @param string      $property The property name.
	 *
	 * @return bool Whether its values may be summarised.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function maySummariseValues(?Schema $schema, string $property): bool;
}//end interface
