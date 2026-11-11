<?php

/**
 * GeneratedIdentifierDeclarationInterface: one property's generated-identifier declaration, as the generated identifier listener reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

use DateTimeInterface;

/**
 * A property's generated-identifier declaration, typed for callers outside the Service component.
 *
 * Its outside caller, the generated identifier listener, calls sequence(), periodAt(),
 * render() and parse(), so those are the whole contract. The listener obtains one
 * through {@see GeneratedIdentifierDeclarationReader::fromProperty()}, so it never
 * names the value class.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */
interface GeneratedIdentifierDeclarationInterface {
	/**
	 * The counter's name.
	 *
	 * Named rather than derived from the schema, because two schemas naming the
	 * same counter is how they come to share one.
	 *
	 * @return string The sequence name.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-two-schemas-may-share-one-sequence
	 */
	public function sequence(): string;

	/**
	 * The period that keys the counter at a given moment.
	 *
	 * An empty string for `resetOn: never`, the four-digit year for
	 * `resetOn: year`. The period is part of the counter's identity, which is
	 * what makes `Z-2026-00001` and `Z-2027-00001` two different first values
	 * rather than one collision.
	 *
	 * @param DateTimeInterface $moment The moment the object is being created.
	 *
	 * @return string The period key.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function periodAt(DateTimeInterface $moment): string;

	/**
	 * Render one value of this identifier.
	 *
	 * @param int $sequenceValue The number taken from the counter.
	 * @param DateTimeInterface $moment The moment the object is being created.
	 *
	 * @return string The rendered identifier.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function render(int $sequenceValue, DateTimeInterface $moment): string;

	/**
	 * Read back the sequence number and period a value was rendered from.
	 *
	 * This is what lets an import advance the counter past the values it
	 * supplied. Without it, importing `Z-2026-00120` leaves the counter at zero
	 * and the next create issues `Z-2026-00001`, which collides on the
	 * hundred-and-twentieth create rather than the first, so the bug ships.
	 *
	 * The pattern is built from the SAME format that renders, so the two cannot
	 * drift: a placeholder added to one is added to the other.
	 *
	 * @param string $value A value already in hand.
	 *
	 * @return array{sequence: int, period: string}|null The parsed parts, or null when the value does not match.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function parse(string $value): ?array;
}//end interface
