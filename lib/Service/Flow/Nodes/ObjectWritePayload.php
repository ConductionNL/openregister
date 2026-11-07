<?php

/**
 * Builds the payload an object-write step writes for one item.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;
use RuntimeException;

/**
 * Templates the configured fields, or reads the object at `payloadFrom`.
 */
final class ObjectWritePayload {

	/**
	 * Reads paths and placeholders out of the item.
	 *
	 * @var ObjectWriteTemplate
	 */
	public readonly ObjectWriteTemplate $template;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(private readonly IL10N $l10n) {
		$this->template = new ObjectWriteTemplate();
	}//end __construct()

	/**
	 * Template the configured field mapping against one item.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan: fields, missing-field policy and `payloadFrom`.
	 * @param array           $json The item's record.
	 *
	 * @return array The payload to write.
	 *
	 * @throws RuntimeException When a value is unresolvable and `onMissing` is `fail`.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function build(ObjectWritePlan $plan, array $json): array {
		if (trim($plan->payloadFrom) !== '') {
			return $this->wholePayload(path: trim($plan->payloadFrom), json: $json);
		}

		$payload = [];
		foreach ($plan->fields as $key => $value) {
			$resolved = $this->template->resolve(value: $value, json: $json);
			if ($resolved['resolved'] === true) {
				$payload[(string)$key] = $resolved['value'];
			}

			$this->guardMissingField(plan: $plan, field: ['key' => (string)$key, 'resolved' => $resolved['resolved']]);
		}

		return $payload;
	}//end build()

	/**
	 * Fail an unresolved field when `onMissing` says so; otherwise it is omitted.
	 *
	 * Omission — not `""`, not `null`, not `{}`. OpenRegister's validator rejects
	 * both `{}` and `null` for an object property nested inside an array item, and
	 * an empty string is worse still: it passes validation and writes a wrong value.
	 *
	 * @param ObjectWritePlan                    $plan  The step's resolved plan.
	 * @param array{key: string, resolved: bool} $field The field and whether it resolved.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the value is unresolved and `onMissing` is `fail`.
	 */
	private function guardMissingField(ObjectWritePlan $plan, array $field): void {
		if ($field['resolved'] === false && $plan->onMissing === ObjectWriteOptions::ON_MISSING_FAIL) {
			throw new RuntimeException(
				$this->l10n->t('The value for field "%s" could not be resolved from the item.', [$field['key']])
			);
		}
	}//end guardMissingField()

	/**
	 * The object at a path, written whole.
	 *
	 * `fields` enumerates the properties to write, which requires knowing them up
	 * front: a synchronization with no mapping has no such list, and that single
	 * gap refused 98 of 99 unmigratable synchronizations measured on the dev
	 * instance. `payloadFrom` names a path whose resolved value IS the payload.
	 *
	 * An unresolvable path THROWS regardless of `onMissing`, deliberately.
	 * `onMissing: omit` is a per-field rule: it drops one property and writes the
	 * rest. There is no "rest" here, and omitting a whole payload would write a
	 * BLANK object.
	 *
	 * @param string $path The configured path.
	 * @param array  $json The item's record.
	 *
	 * @return array The payload to write.
	 *
	 * @throws RuntimeException When the path holds no object.
	 */
	private function wholePayload(string $path, array $json): array {
		$found = $this->template->lookup(path: $path, json: $json);
		if (is_array($found['value']) === false) {
			throw new RuntimeException(
				$this->l10n->t(
					'"payloadFrom" path "%s" did not resolve to an object on this item, so there is '
					. 'nothing to write. Writing an empty object instead would create a blank record.',
					[$path]
				)
			);
		}

		return $found['value'];
	}//end wholePayload()
}//end class
