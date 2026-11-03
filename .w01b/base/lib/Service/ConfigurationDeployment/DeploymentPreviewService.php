<?php

/**
 * DeploymentPreviewService — what a deployment would change, before it does.
 *
 * The preview is the answer to "what is about to happen to my instance", and
 * it is also the gate: the same walk that renders the diff decides whether the
 * set is deployable at all, so an administrator reading a green preview and
 * the deployment refusing afterwards cannot disagree. It writes nothing.
 *
 * Three ways a value refuses.
 *
 * `unknown-key` — the address is not in the declared vocabulary, or the key is
 * reserved. Caught at draft time too, and re-checked here because the
 * vocabulary can move between drafting and deploying.
 *
 * `invalid-value` — the value does not match the key's declared shape.
 *
 * `stale-draft` — the live value is no longer the one the draft was taken
 * against. Somebody else changed it in between, and applying the draft would
 * silently discard their change. This is the refusal that earns the
 * `base_value` column.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;

/**
 * The diff a deployment would apply.
 */
class DeploymentPreviewService {

	/**
	 * The address is not in the declared vocabulary.
	 *
	 * @var string
	 */
	public const REFUSAL_UNKNOWN_KEY = 'unknown-key';

	/**
	 * The value does not match the key's declared shape.
	 *
	 * @var string
	 */
	public const REFUSAL_INVALID_VALUE = 'invalid-value';

	/**
	 * The live value moved since the draft was taken.
	 *
	 * @var string
	 */
	public const REFUSAL_STALE_DRAFT = 'stale-draft';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftService $drafts   The pending values.
	 * @param ConfigurationValueStore   $store    The live values.
	 * @param ConfigurationKeyRegistry  $registry The declared vocabulary.
	 */
	public function __construct(
		private readonly ConfigurationDraftService $drafts,
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationKeyRegistry $registry
	) {

	}//end __construct()

	/**
	 * What deploying a set would change.
	 *
	 * @param ConfigurationDraftSet $set The set to preview.
	 *
	 * @return array<string, mixed> The diff, the refusals and the counts.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function preview(ConfigurationDraftSet $set): array {
		$changes = [];
		$unchanged = [];
		$refusals = [];

		foreach ($this->drafts->draftsIn(setUuid: (string)$set->getUuid()) as $draft) {
			$verdict = $this->judge(draft: $draft);

			match ($verdict['status']) {
				'refused' => $refusals[] = $verdict,
				'unchanged' => $unchanged[] = $verdict,
				default => $changes[] = $verdict,
			};
		}

		return [
			'set' => $set->jsonSerialize(),
			'changes' => $changes,
			'unchanged' => $unchanged,
			'refusals' => $refusals,
			'counts' => [
				'toChange' => count($changes),
				'unchanged' => count($unchanged),
				'refused' => count($refusals),
			],
			'fourEyesRequired' => $this->drafts->requiresFourEyes(),
			'deployable' => ($refusals === [] && $changes !== []),
		];

	}//end preview()

	/**
	 * The refusals a set carries, if any.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return array<int, array<string, mixed>> The refusing values.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function refusalsFor(ConfigurationDraftSet $set): array {
		return $this->preview(set: $set)['refusals'];

	}//end refusalsFor()

	/**
	 * What one pending value would do.
	 *
	 * @param ConfigurationDraft $draft The pending value.
	 *
	 * @return array<string, mixed> The verdict for this address.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function judge(ConfigurationDraft $draft): array {
		$layer = (string)$draft->getLayer();
		$layerRef = $draft->getLayerRef();
		$configKey = (string)$draft->getConfigKey();
		$removes = ($draft->getRemoves() ?? false);
		$proposed = $draft->readDraftValue();

		$verdict = [
			'draft' => $draft->getUuid(),
			'layer' => $layer,
			'layerRef' => $layerRef,
			'key' => $configKey,
			'removes' => $removes,
			'from' => $draft->readBaseValue(),
			'to' => match ($removes) {
				true => null,
				false => $proposed,
			},
		];

		$keyRefusal = $this->registry->refusalFor(layer: $layer, configKey: $configKey);
		if ($keyRefusal !== null) {
			return array_merge(
				$verdict,
				['status' => 'refused', 'refusal' => self::REFUSAL_UNKNOWN_KEY, 'reason' => $keyRefusal]
			);
		}

		if ($removes === false) {
			$valueRefusal = $this->registry->valueRefusalFor(configKey: $configKey, value: $proposed);
			if ($valueRefusal !== null) {
				return array_merge(
					$verdict,
					['status' => 'refused', 'refusal' => self::REFUSAL_INVALID_VALUE, 'reason' => $valueRefusal]
				);
			}
		}

		$live = $this->store->read(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		$verdict['from'] = $live->value;
		$verdict['fromPresent'] = $live->present;

		if ($this->hasMoved(draft: $draft, live: $live) === true) {
			return array_merge(
				$verdict,
				[
					'status' => 'refused',
					'refusal' => self::REFUSAL_STALE_DRAFT,
					'reason' => sprintf(
						'"%s" has changed since this draft was taken; deploying it would discard that change',
						$configKey
					),
				]
			);
		}

		if ($removes === true) {
			return array_merge(
				$verdict,
				[
					'status' => match ($live->present) {
						true => 'remove',
						false => 'unchanged',
					},
				]
			);
		}

		if ($live->present === true && $this->sameValue(left: $live->value, right: $proposed) === true) {
			return array_merge($verdict, ['status' => 'unchanged']);
		}

		return array_merge(
			$verdict,
			[
				'status' => match ($live->present) {
					true => 'change',
					false => 'create',
				},
			]
		);

	}//end judge()

	/**
	 * Whether the live value moved since the draft recorded its base.
	 *
	 * @param ConfigurationDraft     $draft The pending value.
	 * @param ConfigurationSnapshot $live  The live value now.
	 *
	 * @return boolean True when somebody else changed it in between.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function hasMoved(ConfigurationDraft $draft, ConfigurationSnapshot $live): bool {
		if (($draft->getBasePresent() ?? false) !== $live->present) {
			return true;
		}

		if ($live->present === false) {
			return false;
		}

		return $this->sameValue(left: $draft->readBaseValue(), right: $live->value) === false;

	}//end hasMoved()

	/**
	 * Whether two configuration values are the same value.
	 *
	 * Compared as their JSON encodings, because an object read back from the
	 * database has its keys in whatever order the encoder left them, and a
	 * loose comparison would call `1` and `"1"` equal, which for a setting
	 * that is stored as a string and read as an integer is exactly the pair
	 * that must NOT be called equal.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return boolean True when they are the same value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function sameValue(mixed $left, mixed $right): bool {
		if (is_array($left) === true && is_array($right) === true) {
			$leftSorted = $left;
			$rightSorted = $right;
			ksort($leftSorted);
			ksort($rightSorted);

			return json_encode($leftSorted) === json_encode($rightSorted);
		}

		return $left === $right;

	}//end sameValue()
}//end class
