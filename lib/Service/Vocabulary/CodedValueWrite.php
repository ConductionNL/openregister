<?php

/**
 * OpenRegister CodedValueWrite
 *
 * The write a coded-value check judges: the submitted object data, the
 * schema it is written against and the instant its values' validity windows
 * are judged at. A caller that wants the refusals reported rather than
 * thrown (a preview, a revalidation sweep) hands this to
 * {@see CodedValueGuard::collectViolations()}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use DateTimeInterface;
use OCA\OpenRegister\Db\Schema;

/**
 * One object write, as the coded-value guard judges it.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class CodedValueWrite {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>    $object The submitted object data.
	 * @param Schema                 $schema The schema being written against.
	 * @param DateTimeInterface|null $at     The instant to judge windows at; now when null.
	 */
	public function __construct(
		public readonly array $object,
		public readonly Schema $schema,
		public readonly ?DateTimeInterface $at = null,
	) {

	}//end __construct()
}//end class
