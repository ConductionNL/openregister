<?php

/**
 * SettingsDraftGate — the settings facade's half of the deployment lifecycle.
 *
 * REQ-CAD-006: no domain escapes the lifecycle. Every `update*` method on
 * `SettingsService` asks this gate first. With drafting off, which is the
 * default and which is what every existing instance does, the gate answers null
 * and the facade writes straight through exactly as before. With drafting on,
 * the gate stages the write as a draft and the live value is untouched.
 *
 * Two decisions worth naming, because both could have gone the other way.
 *
 * **The gate drafts the payload merged over the live value, not a normalised
 * blob.** Normalising here would mean a second copy of every handler's
 * defaults, and the copy would drift the first time somebody changed one of
 * them. The handlers and the getters already fill a missing field from the same
 * default, so a merged payload read back through the getter answers exactly
 * what the straight-through write would have answered.
 * `SettingsDraftGateTest::testADraftedDomainReadsBackTheSameAsAStraightWrite`
 * runs the real handler against a recording app config and pins that.
 *
 * **The set is remembered on the instance, not opened per write.** An operator
 * changing four settings wants one set to review, not four. The uuid lives at
 * `configuration_draft_set`, which is reserved so no deployment can move it: a
 * set that could redirect where drafts land could redirect its own review.
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
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCP\IAppConfig;

/**
 * Stage a settings write instead of applying it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants. A settings domain is always an instance
 * key, and an injected layer vocabulary could disagree with the one the
 * deployment applies against.
 */
class SettingsDraftGate {

	/**
	 * The app config key switching drafting on.
	 *
	 * @var string
	 */
	public const DRAFTING_KEY = 'configuration_drafting';

	/**
	 * The app config key remembering which set settings drafts land in.
	 *
	 * @var string
	 */
	public const TARGET_SET_KEY = 'configuration_draft_set';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftService $drafts    The draft half of the lifecycle.
	 * @param ConfigurationValueStore   $store     The live values.
	 * @param SettingsDomainMap         $domains   Which keys a domain writes.
	 * @param IAppConfig                $appConfig Nextcloud's app configuration.
	 * @param string                    $appName   The app the instance keys live under.
	 */
	public function __construct(
		private readonly ConfigurationDraftService $drafts,
		private readonly ConfigurationValueStore $store,
		private readonly SettingsDomainMap $domains,
		private readonly IAppConfig $appConfig,
		private readonly string $appName = 'openregister'
	) {

	}//end __construct()

	/**
	 * Whether this instance stages settings writes.
	 *
	 * @return boolean True when drafting is on.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function isDrafting(): bool {
		return $this->appConfig->getValueBool($this->appName, self::DRAFTING_KEY, false);

	}//end isDrafting()

	/**
	 * Stage a settings write, or answer null so the facade writes through.
	 *
	 * Null is the answer an instance that never drafts always gets, and it is
	 * the only answer that leaves the facade's behaviour as it was.
	 *
	 * @param string               $domain  The settings domain being written.
	 * @param array<string, mixed> $payload The data handed to the facade.
	 *
	 * @return array<string, mixed>|null The pending write, or null to write through.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function draftIfEnabled(string $domain, array $payload): ?array {
		if ($this->isDrafting() === false) {
			return null;
		}

		if ($this->domains->knows($domain) === false) {
			return null;
		}

		$projected = $this->domains->project(domain: $domain, payload: $payload);
		if ($projected === []) {
			return [
				'pending' => true,
				'domain' => $domain,
				'draftSet' => null,
				'drafts' => [],
				'message' => 'drafting is on and this write named no setting, so nothing was staged',
			];
		}

		$set = $this->targetSet();
		$staged = [];

		foreach ($projected as $entry) {
			$value = $this->valueFor(entry: $entry);
			$this->drafts->draftValue(
				setUuid: (string)$set->getUuid(),
				layer: ConfigurationLayer::INSTANCE,
				layerRef: null,
				configKey: (string)$entry['key'],
				value: $value
			);

			$staged[] = ['key' => (string)$entry['key'], 'value' => $value];
		}

		return [
			'pending' => true,
			'domain' => $domain,
			'draftSet' => $set->jsonSerialize(),
			'drafts' => $staged,
			'message' => sprintf(
				'drafting is on, so nothing changed yet. Deploy the set "%s" to make it live.',
				(string)$set->getName()
			),
		];

	}//end draftIfEnabled()

	/**
	 * The set settings drafts land in, opening one when there is none.
	 *
	 * @return ConfigurationDraftSet The open set.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function targetSet(): ConfigurationDraftSet {
		$remembered = $this->appConfig->getValueString($this->appName, self::TARGET_SET_KEY, '');

		if ($remembered !== '') {
			$set = $this->rememberedSet(uuid: $remembered);
			if ($set !== null) {
				return $set;
			}
		}

		// A set that has been deployed or discarded cannot take another value,
		// so the next settings write opens a fresh one rather than failing.
		$set = $this->drafts->openSet(
			name: sprintf('settings drafted on %s', date('Y-m-d')),
			description: 'opened by the settings screen because drafting is on'
		);

		$this->appConfig->setValueString($this->appName, self::TARGET_SET_KEY, (string)$set->getUuid());

		return $set;

	}//end targetSet()

	/**
	 * The remembered set, when it still takes values.
	 *
	 * @param string $uuid The remembered uuid.
	 *
	 * @return ConfigurationDraftSet|null The set, or null when it is gone or closed.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	private function rememberedSet(string $uuid): ?ConfigurationDraftSet {
		try {
			$set = $this->drafts->loadSet(uuid: $uuid);
		} catch (DeploymentRefusedException $exception) {
			return null;
		}

		if ($set->isEditable() === false) {
			return null;
		}

		return $set;

	}//end rememberedSet()

	/**
	 * The value to stage for one projected address.
	 *
	 * A blob key is merged over what is live, so a payload naming three fields
	 * of nine does not stage a blob that drops the other six. A loose scalar
	 * replaces, because it has nothing to merge with.
	 *
	 * @param array<string, mixed> $entry The projected address.
	 *
	 * @return mixed The value to draft.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	private function valueFor(array $entry): mixed {
		$value = $entry['value'];
		if (($entry['merge'] ?? false) === false || is_array($value) === false) {
			return $value;
		}

		$live = $this->store->read(
			layer: ConfigurationLayer::INSTANCE,
			layerRef: null,
			configKey: (string)$entry['key']
		);

		if ($live->present === false || is_array($live->value) === false) {
			return $value;
		}

		return array_replace_recursive($live->value, $value);

	}//end valueFor()
}//end class
