<?php

/**
 * ClassificationReadGate: refuses a caller an object above their clearance, or above a ceiling on their scope.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use Psr\Log\LoggerInterface;

/**
 * The find-path half of a schema's `x-openregister-classification` block.
 *
 * Two refusals, both final: no schema rule, per-object grant or elevation
 * re-opens them. A ceiling refuses its scope (an anonymous caller is the
 * `public` scope) on every action, because a ceiling says the object is not
 * reachable by that principal at all. A clearance refuses a read of an
 * object ranking above it. An administrator is cleared to the top level and
 * so is never refused on clearance; a ceiling on `public` still holds for an
 * anonymous administrator, which cannot exist.
 *
 * A schema without the block refuses nothing, so every schema that has not
 * declared one reads exactly as before.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
final class ClassificationReadGate {

	/**
	 * The scope an anonymous caller reads in.
	 *
	 * @var string
	 */
	public const PUBLIC_SCOPE = 'public';

	/**
	 * Constructor.
	 *
	 * @param ObjectClassification $classification Ranks the object, warning on an unknown label.
	 * @param ClearanceResolver    $clearances     Resolves the caller's clearance.
	 * @param LoggerInterface      $logger         Records each refusal.
	 */
	public function __construct(
		private readonly ObjectClassification $classification,
		private readonly ClearanceResolver $clearances,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why this caller may not have this action on this object, or null when classification does not stand in the way.
	 *
	 * @param Schema               $schema The object's schema.
	 * @param ObjectEntity         $object The object.
	 * @param ClassificationCaller $caller Who asks, for what.
	 *
	 * @return string|null The refusal, naming the ceiling or the two levels.
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function refusal(Schema $schema, ObjectEntity $object, ClassificationCaller $caller): ?string {
		$block = (new SchemaAnnotation(schema: $schema, key: ClassificationScale::ANNOTATION))->value();
		if (is_array($block) === false) {
			return null;
		}

		$scale = ClassificationScale::fromBlock(block: $block);
		$data = (array)$object->getObject();
		$objectId = (string)$object->getUuid();
		$label = ($data[$scale->property()] ?? null);

		$refusal = $this->ceilingRefusal(limits: ClassificationLimits::fromBlock(scale: $scale, block: $block), label: $label, caller: $caller);
		if ($refusal === null && $caller->action === 'read') {
			$refusal = $this->clearanceRefusal(scale: $scale, objectId: $objectId, data: $data, caller: $caller);
		}

		if ($refusal !== null) {
			$this->logger->info(
				'[ClassificationReadGate] ' . $refusal,
				['object' => $objectId, 'user' => ($caller->userId ?? 'anonymous'), 'action' => $caller->action]
			);
		}

		return $refusal;
	}//end refusal()

	/**
	 * The refusal of a ceiling on the caller's scope, for an anonymous caller.
	 *
	 * @param ClassificationLimits $limits The block's ceilings.
	 * @param mixed                $label  The object's label.
	 * @param ClassificationCaller $caller Who asks.
	 *
	 * @return string|null
	 */
	private function ceilingRefusal(ClassificationLimits $limits, mixed $label, ClassificationCaller $caller): ?string {
		if ($caller->userId !== null) {
			return null;
		}

		$ceiling = $limits->ceilingRefusing(scope: self::PUBLIC_SCOPE, label: $label);
		if ($ceiling === null) {
			return null;
		}

		$level = (string)($ceiling['atOrAbove'] ?? '');

		return 'The classification ceiling for "' . self::PUBLIC_SCOPE . '" at or above "' . $level . '" refuses this object';
	}//end ceilingRefusal()

	/**
	 * The refusal of a read above the caller's clearance.
	 *
	 * @param ClassificationScale  $scale    The declared levels.
	 * @param string               $objectId The object, for the warning on an unknown label.
	 * @param array<string, mixed> $data     The object's data.
	 * @param ClassificationCaller $caller   Who asks.
	 *
	 * @return string|null
	 */
	private function clearanceRefusal(ClassificationScale $scale, string $objectId, array $data, ClassificationCaller $caller): ?string {
		$clearance = $this->clearances->resolve(scale: $scale, principal: $caller->userId, groups: $caller->groups);
		if (in_array('admin', $caller->groups, true) === true) {
			$clearance = $this->clearances->forAdministrator(scale: $scale);
		}

		if ($this->classification->readable(scale: $scale, clearance: $clearance, objectId: $objectId, data: $data) === true) {
			return null;
		}

		$rank = $this->classification->rank(scale: $scale, objectId: $objectId, data: $data);

		return 'The object is classified "' . $scale->levels()[$rank] . '", above the caller\'s clearance "' . $clearance . '"';
	}//end clearanceRefusal()
}//end class
