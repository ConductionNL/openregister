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
class DeploymentPreviewService implements DeploymentPreviewServiceInterface {

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
		$groups = ['changes' => [], 'unchanged' => [], 'refusals' => []];
		foreach ($this->drafts->draftsIn(setUuid: (string)$set->getUuid()) as $draft) {
			$verdict = $this->judge(draft: $draft);
			$groups[self::groupOf(status: $verdict['status'])][] = $verdict;
		}

		return $this->previewOf(set: $set, groups: $groups);

	}//end preview()

	/**
	 * The preview group a verdict belongs in.
	 *
	 * @param string $status The verdict's status.
	 *
	 * @return string `refusals`, `unchanged` or `changes`.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function groupOf(string $status): string {
		return match ($status) {
			'refused' => 'refusals',
			'unchanged' => 'unchanged',
			default => 'changes',
		};

	}//end groupOf()

	/**
	 * The preview of a set: its verdicts by group, their counts, and whether it can deploy.
	 *
	 * Whether a second approver is required comes from the draft service.
	 *
	 * @param ConfigurationDraftSet                                   $set    The set.
	 * @param array{changes: array, unchanged: array, refusals: array} $groups The verdicts by group.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function previewOf(ConfigurationDraftSet $set, array $groups): array {
		['changes' => $changes, 'unchanged' => $unchanged, 'refusals' => $refusals] = $groups;
		return [
			'set' => $set->jsonSerialize(),
			'changes' => $changes,
			'unchanged' => $unchanged,
			'refusals' => $refusals,
			'counts' => ['toChange' => count($changes), 'unchanged' => count($unchanged), 'refused' => count($refusals)],
			'fourEyesRequired' => $this->drafts->requiresFourEyes(),
			'deployable' => ($refusals === [] && $changes !== []),
		];

	}//end previewOf()

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
		$verdict = self::draftVerdict(draft: $draft);
		$refusal = $this->declarationRefusal(verdict: $verdict);
		if ($refusal !== null) {
			return array_merge($verdict, $refusal);
		}

		$live = $this->store->read(layer: $verdict['layer'], layerRef: $verdict['layerRef'], configKey: $verdict['key']);
		$verdict['from'] = $live->value;
		$verdict['fromPresent'] = $live->present;

		if ($this->hasMoved(draft: $draft, live: $live) === true) {
			return array_merge($verdict, self::staleRefusal(configKey: $verdict['key']));
		}

		return array_merge($verdict, ['status' => $this->liveStatus(live: $live, verdict: $verdict)]);

	}//end judge()

	/**
	 * The verdict fields a pending value carries itself: its address, its base and its proposal.
	 *
	 * @param ConfigurationDraft $draft The pending value.
	 *
	 * @return array{draft: ?string, layer: string, layerRef: ?string, key: string, removes: bool, from: mixed, to: mixed}
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function draftVerdict(ConfigurationDraft $draft): array {
		$removes = ($draft->getRemoves() ?? false);
		return [
			'draft' => $draft->getUuid(),
			'layer' => (string)$draft->getLayer(),
			'layerRef' => $draft->getLayerRef(),
			'key' => (string)$draft->getConfigKey(),
			'removes' => $removes,
			'from' => $draft->readBaseValue(),
			'to' => match ($removes) {
				true => null,
				false => $draft->readDraftValue(),
			},
		];

	}//end draftVerdict()

	/**
	 * The refusal of an undeclared key, or of a value the key does not accept; null when the declaration allows it.
	 *
	 * A removal carries no value, so only its key is checked.
	 *
	 * @param array<string, mixed> $verdict The pending value's verdict fields.
	 *
	 * @return array{status: string, refusal: string, reason: string}|null
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function declarationRefusal(array $verdict): ?array {
		$keyRefusal = $this->registry->refusalFor(layer: $verdict['layer'], configKey: $verdict['key']);
		if ($keyRefusal !== null) {
			return ['status' => 'refused', 'refusal' => self::REFUSAL_UNKNOWN_KEY, 'reason' => $keyRefusal];
		}

		if ($verdict['removes'] === true) {
			return null;
		}

		$valueRefusal = $this->registry->valueRefusalFor(configKey: $verdict['key'], value: $verdict['to']);
		if ($valueRefusal === null) {
			return null;
		}

		return ['status' => 'refused', 'refusal' => self::REFUSAL_INVALID_VALUE, 'reason' => $valueRefusal];

	}//end declarationRefusal()

	/**
	 * The refusal of a draft whose live value moved since it was taken.
	 *
	 * @param string $configKey The configuration key.
	 *
	 * @return array{status: string, refusal: string, reason: string}
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function staleRefusal(string $configKey): array {
		return [
			'status' => 'refused',
			'refusal' => self::REFUSAL_STALE_DRAFT,
			'reason' => sprintf('"%s" has changed since this draft was taken; deploying it would discard that change', $configKey),
		];

	}//end staleRefusal()

	/**
	 * What an allowed pending value does to the live value: create, change, remove, or nothing.
	 *
	 * @param ConfigurationSnapshot $live    The live value now.
	 * @param array<string, mixed>  $verdict The pending value's verdict fields: `removes` and the proposed `to`.
	 *
	 * @return string `create`, `change`, `remove` or `unchanged`.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function liveStatus(ConfigurationSnapshot $live, array $verdict): string {
		$removes = (bool)$verdict['removes'];
		if ($live->present === false) {
			return $this->absentStatus(removes: $removes);
		}

		if ($removes === true) {
			return 'remove';
		}

		if ($this->sameValue(left: $live->value, right: $verdict['to']) === true) {
			return 'unchanged';
		}

		return 'change';

	}//end liveStatus()

	/**
	 * The status of a pending value at an address that holds nothing live.
	 *
	 * @param bool $removes Whether the pending value removes the address.
	 *
	 * @return string 'unchanged' for a removal, else 'create'.
	 */
	private function absentStatus(bool $removes): string {
		return match ($removes) {
			true => 'unchanged',
			false => 'create',
		};

	}//end absentStatus()

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
