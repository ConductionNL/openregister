<?php

/**
 * One app config key an example set reads or writes, with what it wants there.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * An app config key, the value meant for it, and how that value is kept.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */
final class ExampleSetConfigValue {

	/**
	 * The key must hold exactly the value.
	 */
	public const KIND_EXACT = 'exact';

	/**
	 * The value is a floor: an integer key already at or above it is left as it is.
	 */
	public const KIND_FLOOR = 'floor';

	/**
	 * The set generates the value itself; any value already there is kept.
	 */
	public const KIND_GENERATED = 'generated';

	/**
	 * Constructor.
	 *
	 * @param string $app   The app.
	 * @param string $key   The key.
	 * @param string $value The value as text; ignored when generating or only reading.
	 * @param string $type  `string`, or `int` for a key the app reads as an integer.
	 * @param string $kind  One of the KIND_ constants.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
		public readonly string $value='',
		public readonly string $type='string',
		public readonly string $kind=self::KIND_EXACT,
	) {
	}//end __construct()
}//end class
