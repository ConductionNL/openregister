<?php

/**
 * ObjectClassification: places one object on its schema's classification scale.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use Psr\Log\LoggerInterface;

/**
 * Reads an object's label and compares it, logging what cannot be placed.
 *
 * An absent, empty or unknown label ranks most restrictive, so the object is
 * hidden from everyone below the top level. That is safe and also invisible,
 * which is why it is logged naming the object and the value.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-an-unknown-label-is-most-restrictive-and-an-unresolvable-clearance-is-the-default
 */
final class ObjectClassification {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warning for an unplaceable label.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The object's label as the scale ranks it, logging a label it cannot place.
	 *
	 * @param ClassificationScale  $scale    The schema's scale.
	 * @param string               $objectId The object's uuid, for the log line.
	 * @param array<string, mixed> $data     The object's data.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-an-unknown-label-is-most-restrictive-and-an-unresolvable-clearance-is-the-default
	 */
	public function rank(ClassificationScale $scale, string $objectId, array $data): int {
		$label = ($data[$scale->property()] ?? null);
		if ($scale->isKnown(label: $label) === false) {
			$shown = 'nothing';
			if (is_scalar($label) === true && (string)$label !== '') {
				$shown = '"' . (string)$label . '"';
			}

			$this->logger->warning(
				'Object "' . $objectId . '" carries ' . $shown . ' in "' . $scale->property()
				. '", which is not a declared classification level; treating it as "' . $scale->highest() . '"'
			);
		}

		return $scale->rankOf(label: $label);
	}//end rank()

	/**
	 * Whether a caller holding a clearance may read the object.
	 *
	 * @param ClassificationScale  $scale     The schema's scale.
	 * @param string               $clearance The caller's resolved clearance.
	 * @param string               $objectId  The object's uuid.
	 * @param array<string, mixed> $data      The object's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function readable(ClassificationScale $scale, string $clearance, string $objectId, array $data): bool {
		return $this->rank(scale: $scale, objectId: $objectId, data: $data) < count($scale->admittedLabels(clearance: $clearance));
	}//end readable()
}//end class
