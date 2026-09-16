<?php

/**
 * ConfigurationBundleService — one configuration, many subjects.
 *
 * D-5: a bundle is a binding, not a template. A gemeente running two hundred
 * zaaktypen cannot keep two hundred copies of the same rechten- en
 * notificatieopzet in step by hand, and a template it copied once is exactly
 * two hundred copies. So nothing here copies a bundle's values onto a subject.
 * The values stay at the bundle layer, the subject carries a binding, and the
 * explainer walks through the bundle on its way to the subject. Change the
 * bundle and every bound subject changes with it, because they were never
 * holding their own answer.
 *
 * Three things this class owns.
 *
 * **The binding** (REQ-CAD-004). Bind a schema to a bundle, list what a bundle
 * carries, list who follows it.
 *
 * **The exception** (REQ-CAD-004, second scenario). A subject that needs to
 * differ writes its own value at the subject layer, which wins by precedence.
 * That is a fact the bundle's binding list reports by name, because an
 * override nobody can see is the drift a template would have caused.
 *
 * **The copy** (REQ-CAD-005, D-6). A whole permission or transition matrix
 * copied from one role or schema onto another, landing as drafts in a set
 * somebody reviews. Copying six roles across twenty-one schemas is the act
 * most likely to be wrong and the act nobody reviews today.
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

use OCA\OpenRegister\Db\ConfigurationBinding;
use OCA\OpenRegister\Db\ConfigurationBindingMapper;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCP\AppFramework\Http;
use OCP\IUserSession;

/**
 * Bind a bundle to its subjects, and copy a matrix as a draft.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants, and the bundle layer this class writes
 * against has to be the one the deployment applies against.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A Nextcloud DI constructor
 * over the binding mapper, the value rows, the draft half of the lifecycle and
 * the session, plus the entity types it returns and the refusal it throws.
 */
class ConfigurationBundleService {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationBindingMapper $bindings The bindings.
	 * @param ConfigurationValueMapper   $values   The layered value rows.
	 * @param ConfigurationDraftService  $drafts   The draft half of the lifecycle.
	 * @param IUserSession               $session  The current session.
	 */
	public function __construct(
		private readonly ConfigurationBindingMapper $bindings,
		private readonly ConfigurationValueMapper $values,
		private readonly ConfigurationDraftService $drafts,
		private readonly IUserSession $session
	) {

	}//end __construct()

	/**
	 * Bind one subject to one bundle.
	 *
	 * A subject already following another bundle is refused by name rather
	 * than silently rebound: rebinding two hundred zaaktypen by accident is
	 * the failure this whole capability exists to prevent.
	 *
	 * @param string $bundle      The bundle name.
	 * @param string $subject     The subject to bind.
	 * @param string $subjectType What kind of subject it is.
	 *
	 * @return ConfigurationBinding The binding.
	 *
	 * @throws DeploymentRefusedException When a name is empty or the subject follows another bundle.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function bind(
		string $bundle,
		string $subject,
		string $subjectType = ConfigurationBinding::TYPE_SCHEMA
	): ConfigurationBinding {
		$bundle = trim($bundle);
		$subject = trim($subject);

		if ($bundle === '' || $subject === '') {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_INVALID,
				message: 'a binding needs a bundle and a subject',
				refusals: [],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$existing = $this->bindings->findBySubject(subject: $subject);
		if ($existing !== null && $existing->getBundle() === $bundle) {
			return $existing;
		}

		if ($existing !== null) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_STATE,
				message: sprintf(
					'"%s" already follows the bundle "%s"; unbind it before binding it to "%s"',
					$subject,
					(string)$existing->getBundle(),
					$bundle
				)
			);
		}

		return $this->bindings->createFromArray(
			[
				'bundle' => $bundle,
				'subject' => $subject,
				'subjectType' => $subjectType,
				'createdBy' => $this->actor(),
			]
		);

	}//end bind()

	/**
	 * Stop a subject following a bundle.
	 *
	 * The subject's own values are left where they are. Deleting them here
	 * would make unbinding a destructive act nobody asked for, and a subject
	 * that leaves a bundle usually leaves because it needs its own answers.
	 *
	 * @param string $subject The subject to unbind.
	 *
	 * @return boolean True when a binding was removed.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function unbind(string $subject): bool {
		$binding = $this->bindings->findBySubject(subject: $subject);
		if ($binding === null) {
			return false;
		}

		$this->bindings->remove(binding: $binding);

		return true;

	}//end unbind()

	/**
	 * The bundle one subject follows, when it follows one.
	 *
	 * @param string $subject The subject.
	 *
	 * @return string|null The bundle name, or null.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function bundleFor(string $subject): ?string {
		return $this->bindings->findBySubject(subject: $subject)?->getBundle();

	}//end bundleFor()

	/**
	 * What a bundle carries, by key.
	 *
	 * @param string $bundle The bundle name.
	 *
	 * @return array<string, mixed> The bundle's values.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function valuesOf(string $bundle): array {
		$rows = $this->values->findAtLayer(layer: ConfigurationLayer::BUNDLE, layerRef: $bundle);

		$carried = [];
		foreach ($rows as $row) {
			$carried[(string)$row->getConfigKey()] = $row->readValue();
		}

		return $carried;

	}//end valuesOf()

	/**
	 * Who follows a bundle, and which of them overrides it.
	 *
	 * REQ-CAD-004, second scenario: an exception is visible as an exception.
	 * A subject overriding one value of its bundle is listed as overriding,
	 * naming the value, rather than sitting in the list looking compliant.
	 *
	 * @param string $bundle The bundle name.
	 *
	 * @return array<int, array<string, mixed>> One entry per bound subject.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function bindingsOf(string $bundle): array {
		$carried = array_keys($this->valuesOf(bundle: $bundle));

		$listed = [];
		foreach ($this->bindings->findByBundle(bundle: $bundle) as $binding) {
			$subject = (string)$binding->getSubject();
			$overrides = $this->overridesOf(subject: $subject, carried: $carried);

			$listed[] = [
				'subject' => $subject,
				'subjectType' => $binding->getSubjectType(),
				'boundBy' => $binding->getCreatedBy(),
				'overriding' => ($overrides !== []),
				'overrides' => $overrides,
			];
		}

		return $listed;

	}//end bindingsOf()

	/**
	 * Every bundle that exists, with what it carries and who follows it.
	 *
	 * A bundle exists because something references it: a value recorded
	 * against it, or a subject bound to it. There is no bundle row to create
	 * first, which is the point of D-5.
	 *
	 * @return array<int, array<string, mixed>> The bundles, by name.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function listBundles(): array {
		$names = [];

		foreach ($this->bindings->findAllBindings() as $binding) {
			$names[(string)$binding->getBundle()] = true;
		}

		foreach ($this->values->findAllAtLayer(layer: ConfigurationLayer::BUNDLE) as $row) {
			$names[(string)$row->getLayerRef()] = true;
		}

		// A bundle-layer row always carries a reference, so a null one would be
		// a row nothing can address. Listing it as a bundle named "" would put
		// a bundle nobody can open in front of an operator.
		unset($names['']);

		$bundles = [];
		foreach (array_keys($names) as $name) {
			$carried = $this->valuesOf(bundle: $name);
			$bound = $this->bindingsOf(bundle: $name);

			$bundles[] = [
				'name' => $name,
				'keys' => array_keys($carried),
				'subjects' => count($bound),
				'overriding' => count(array_filter($bound, static fn (array $entry): bool => $entry['overriding'])),
			];
		}

		return $bundles;

	}//end listBundles()

	/**
	 * Copy a matrix from one address onto another, as drafts.
	 *
	 * D-6: landing it as a draft makes the review possible without making the
	 * copy harder. Nothing here writes a live value, so the target's live
	 * matrix is unchanged until the set is deployed.
	 *
	 * @param string      $setUuid  The draft set to copy into.
	 * @param string      $prefix   The key prefix naming the matrix.
	 * @param string      $fromRef  The subject or bundle to copy from.
	 * @param string      $toRef    The subject or bundle to copy onto.
	 * @param string      $layer    The layer both addresses live at.
	 *
	 * @return array<int, array<string, mixed>> The keys drafted onto the target.
	 *
	 * @throws DeploymentRefusedException When the copy is empty, unaddressed or onto itself.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function copyMatrix(
		string $setUuid,
		string $prefix,
		string $fromRef,
		string $toRef,
		string $layer = ConfigurationLayer::SUBJECT
	): array {
		$this->requireCopyable(prefix: $prefix, fromRef: $fromRef, toRef: $toRef, layer: $layer);

		$rows = $this->values->findAtLayer(layer: $layer, layerRef: $fromRef, prefix: $prefix);
		if ($rows === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf('"%s" holds no value under "%s", so there is nothing to copy', $fromRef, $prefix),
				refusals: [],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$copied = [];
		foreach ($rows as $row) {
			$configKey = (string)$row->getConfigKey();
			$this->drafts->draftValue(
				setUuid: $setUuid,
				layer: $layer,
				layerRef: $toRef,
				configKey: $configKey,
				value: $row->readValue()
			);

			$copied[] = ['key' => $configKey, 'layer' => $layer, 'layerRef' => $toRef];
		}

		return $copied;

	}//end copyMatrix()

	/**
	 * Refuse a copy that cannot be addressed.
	 *
	 * @param string $prefix  The key prefix.
	 * @param string $fromRef The source reference.
	 * @param string $toRef   The target reference.
	 * @param string $layer   The layer.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When the copy is unaddressed or onto itself.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function requireCopyable(string $prefix, string $fromRef, string $toRef, string $layer): void {
		$refusal = null;

		if (ConfigurationLayer::isKnown($layer) === false) {
			$refusal = sprintf('unknown layer "%s"', $layer);
		}

		if ($refusal === null && ConfigurationLayer::requiresReference($layer) === false) {
			$refusal = 'a matrix is copied between two subjects or bundles, and the instance layer has neither';
		}

		if ($refusal === null && ($prefix === '' || str_ends_with($prefix, '.') === false)) {
			// Without a prefix ending in a dot, "permission" would also match
			// "permissions_legacy", and a copy that takes more than it was
			// asked for is a copy nobody can review against what they asked.
			$refusal = 'a matrix is named by a key prefix ending in a dot, such as "permission."';
		}

		if ($refusal === null && ($fromRef === '' || $toRef === '')) {
			$refusal = 'a copy needs a source and a target';
		}

		if ($refusal === null && $fromRef === $toRef) {
			$refusal = sprintf('"%s" is both the source and the target of this copy', $fromRef);
		}

		if ($refusal === null) {
			return;
		}

		throw new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_INVALID,
			message: $refusal,
			refusals: [],
			statusCode: Http::STATUS_BAD_REQUEST
		);

	}//end requireCopyable()

	/**
	 * Which of a bundle's keys one subject answers for itself.
	 *
	 * @param string             $subject The subject.
	 * @param array<int, string> $carried The keys the bundle carries.
	 *
	 * @return array<int, string> The overridden keys.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function overridesOf(string $subject, array $carried): array {
		if ($carried === []) {
			return [];
		}

		$own = [];
		foreach ($this->values->findAtLayer(layer: ConfigurationLayer::SUBJECT, layerRef: $subject) as $row) {
			$own[] = (string)$row->getConfigKey();
		}

		return array_values(array_intersect($carried, $own));

	}//end overridesOf()

	/**
	 * The current user id, or null when there is no session.
	 *
	 * @return string|null The actor.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function actor(): ?string {
		return $this->session->getUser()?->getUID();

	}//end actor()
}//end class
