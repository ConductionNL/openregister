<?php

/**
 * OpenRegister GroupRightsReconciler
 *
 * Restores a product-owned group's rights to what its app declares (REQ-RAH-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compares the live authorization blocks of the registers and schemas an app
 * ships with the rights it declares for its own groups, and repairs them.
 *
 * Only the rules of groups the app declares are touched: a declared rule that
 * is missing is restored, a rule of a declared group that is not declared is
 * removed. Rules of any other group stay as they are, and group membership is
 * never read or written. An app whose rights were never stored (imported
 * before this existed) is left alone until its next import stores them.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
 */
class GroupRightsReconciler {

	/**
	 * A declared rule put back.
	 *
	 * @var string
	 */
	public const RESTORED = 'restored';

	/**
	 * An undeclared rule of a declared group taken out.
	 *
	 * @var string
	 */
	public const REMOVED = 'removed';

	/**
	 * Constructor.
	 *
	 * @param GroupDeclarationSources $sources    Live registers, live schemas and stored declarations.
	 * @param DeclaredGroupRights     $rights     Reads the group a rule names.
	 * @param IEventDispatcher        $dispatcher Carries the administrative audit rows.
	 * @param LoggerInterface         $logger     PSR logger.
	 */
	public function __construct(
		private readonly GroupDeclarationSources $sources,
		private readonly DeclaredGroupRights $rights,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair every app's declared group rights. Never throws: a failure on one
	 * register or schema is logged and the rest are still repaired.
	 *
	 * @return list<array{app: string, kind: string, slug: string, group: string, action: string, direction: string}> The changes made.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function reconcile(): array {
		$changes = [];
		try {
			foreach ($this->storedDeclarations() as $appId => $declaration) {
				$changes = array_merge($changes, $this->reconcileApp(appId: $appId, declaration: $declaration));
			}
		} catch (Throwable $e) {
			$this->logger->error(message: '[GroupRightsReconciler] sweep failed: ' . $e->getMessage(), context: ['exception' => $e]);
		}

		return $changes;
	}//end reconcile()

	/**
	 * The new rules of one authorization action, and what changed, for the groups an app declares.
	 *
	 * The live order is kept; restored rules are appended in declared order.
	 *
	 * @param list<mixed> $live     The live rules.
	 * @param list<mixed> $declared The declared rules.
	 * @param string[]    $managed  The groups the app declares.
	 *
	 * @return array{rules: list<mixed>, restored: list<mixed>, removed: list<mixed>}
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function repairRules(array $live, array $declared, array $managed): array {
		$declaredKeys = array_map($this->rights->ruleKey(...), $declared);
		$rules = [];
		$removed = [];
		$present = [];
		foreach ($live as $rule) {
			$group = $this->rights->groupOf(rule: $rule);
			$key = $this->rights->ruleKey(rule: $rule);
			if ($group !== null && in_array($group, $managed, true) === true && in_array($key, $declaredKeys, true) === false) {
				$removed[] = $rule;
				continue;
			}

			$rules[] = $rule;
			$present[] = $key;
		}

		$restored = [];
		foreach ($declared as $index => $rule) {
			if (in_array($declaredKeys[$index], $present, true) === false) {
				$rules[] = $rule;
				$restored[] = $rule;
				$present[] = $declaredKeys[$index];
			}
		}

		return ['rules' => $rules, 'restored' => $restored, 'removed' => $removed];
	}//end repairRules()

	/**
	 * Repair one authorization block for the groups an app declares.
	 *
	 * @param array<string, mixed>              $live     The live block.
	 * @param array<string, list<mixed>>        $declared The declared rights, by action.
	 * @param string[]                          $managed  The groups the app declares.
	 *
	 * @return array{block: array<string, mixed>, changes: list<array{group: string, action: string, direction: string}>}
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function repairBlock(array $live, array $declared, array $managed): array {
		$block = $live;
		$changes = [];
		$actions = array_unique(array_merge(array_keys($live), array_keys($declared)));
		foreach ($actions as $action) {
			if (self::isRightsAction(action: $action, live: $live) === false) {
				continue;
			}

			$repair = $this->repairRules(live: array_values($live[$action] ?? []), declared: ($declared[$action] ?? []), managed: $managed);
			if ($repair['restored'] === [] && $repair['removed'] === []) {
				continue;
			}

			$block[$action] = $repair['rules'];
			if ($repair['rules'] === [] && array_key_exists($action, $declared) === false) {
				// The action was not declared at all: it goes back to absent, not to an empty list.
				unset($block[$action]);
			}

			$changes = array_merge(
				$changes,
				$this->describe(rules: $repair['restored'], action: $action, direction: self::RESTORED),
				$this->describe(rules: $repair['removed'], action: $action, direction: self::REMOVED)
			);
		}//end foreach

		return ['block' => $block, 'changes' => $changes];
	}//end repairBlock()

	/**
	 * Whether a block key holds rules: an action, not the role map or a flag such as `public: true`.
	 *
	 * @param mixed                $action The key.
	 * @param array<string, mixed> $live   The live block.
	 *
	 * @return bool True for an action whose rules may be repaired.
	 */
	private static function isRightsAction(mixed $action, array $live): bool {
		if (is_string($action) === false || $action === 'roles') {
			return false;
		}

		return isset($live[$action]) === false || is_array($live[$action]) === true;
	}//end isRightsAction()

	/**
	 * Repair the registers and schemas one app ships.
	 *
	 * @param string                                                                                                    $appId       The app.
	 * @param array{groups: string[], rights: array<string, array<string, array<string, list<mixed>>>>} $declaration What it declares.
	 *
	 * @return list<array{app: string, kind: string, slug: string, group: string, action: string, direction: string}>
	 */
	private function reconcileApp(string $appId, array $declaration): array {
		$changes = [];
		foreach ($this->liveOwners(appId: $appId, rights: $declaration['rights']) as $owner) {
			try {
				$changes = array_merge($changes, $this->reconcileOwner(appId: $appId, owner: $owner, declaration: $declaration));
			} catch (Throwable $e) {
				$this->logger->error(
					message: '[GroupRightsReconciler] could not repair ' . $owner['kind'] . ' ' . $owner['slug'] . ': ' . $e->getMessage(),
					context: ['exception' => $e, 'app' => $appId]
				);
			}
		}

		return $changes;
	}//end reconcileApp()

	/**
	 * Repair one register or schema, store it when it changed, and audit each change.
	 *
	 * @param string                                                         $appId       The app.
	 * @param array{kind: string, slug: string, entity: Register|Schema}     $owner       The live register or schema.
	 * @param array{groups: string[], rights: array<string, array<string, array<string, list<mixed>>>>} $declaration What the app declares.
	 *
	 * @return list<array{app: string, kind: string, slug: string, group: string, action: string, direction: string}>
	 */
	private function reconcileOwner(string $appId, array $owner, array $declaration): array {
		$entity = $owner['entity'];
		$repair = $this->repairBlock(
			live: ($entity->getAuthorization() ?? []),
			declared: $declaration['rights'][$owner['kind'] . 's'][$owner['slug']],
			managed: $declaration['groups']
		);
		if ($repair['changes'] === []) {
			return [];
		}

		$entity->setAuthorization($repair['block']);
		$this->mapperFor(kind: $owner['kind'])->update($entity);

		$changes = [];
		foreach ($repair['changes'] as $change) {
			$change = ['app' => $appId, 'kind' => $owner['kind'], 'slug' => $owner['slug']] + $change;
			$this->audit(change: $change);
			$changes[] = $change;
		}

		return $changes;
	}//end reconcileOwner()

	/**
	 * The live registers and schemas of an app that its declaration lists.
	 *
	 * A schema is looked up by its slug within the app, so a same-slug schema
	 * of another app is never touched; a register likewise by slug and app.
	 *
	 * @param string                                                     $appId  The app.
	 * @param array<string, array<string, array<string, list<mixed>>>>   $rights The declared rights.
	 *
	 * @return list<array{kind: string, slug: string, entity: Register|Schema}>
	 */
	private function liveOwners(string $appId, array $rights): array {
		$owners = [];
		foreach (array_keys($rights['schemas'] ?? []) as $slug) {
			$schema = $this->sources->schemaMapper->findByApplicationAndSlug((string)$slug, $appId);
			if ($schema !== null) {
				$owners[] = ['kind' => 'schema', 'slug' => (string)$slug, 'entity' => $schema];
			}
		}

		$registerSlugs = array_map('strval', array_keys($rights['registers'] ?? []));
		if ($registerSlugs === []) {
			return $owners;
		}

		foreach ($this->sources->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$slug = strtolower((string)$register->getSlug());
			if ($register->getApplication() === $appId && in_array($slug, $registerSlugs, true) === true) {
				$owners[] = ['kind' => 'register', 'slug' => $slug, 'entity' => $register];
			}
		}

		return $owners;
	}//end liveOwners()

	/**
	 * Each app's stored declaration: its groups and the rights it gives them.
	 *
	 * @return array<string, array{groups: string[], rights: array<string, mixed>}> App id => declaration.
	 */
	private function storedDeclarations(): array {
		$config = $this->sources->appConfig;
		$declarations = [];
		foreach ($config->getKeys('openregister') as $key) {
			if (str_starts_with($key, DeclaredGroupRights::PREFIX) === false) {
				continue;
			}

			$appId = substr($key, strlen(DeclaredGroupRights::PREFIX));
			$rights = json_decode($config->getValueString('openregister', $key, ''), true);
			$groups = json_decode($config->getValueString('openregister', GroupReconciler::DECLARED_GROUPS_PREFIX . $appId, '[]'), true);
			if ($appId === '' || is_array($rights) === false || is_array($groups) === false || $groups === []) {
				continue;
			}

			$declarations[$appId] = [
				'groups' => array_values(array_filter($groups, 'is_string')),
				'rights' => ['schemas' => ($rights['schemas'] ?? []), 'registers' => ($rights['registers'] ?? [])],
			];
		}//end foreach

		return $declarations;
	}//end storedDeclarations()

	/**
	 * One change per rule, naming its group.
	 *
	 * @param list<mixed> $rules     The rules.
	 * @param string      $action    The action.
	 * @param string      $direction Restored or removed.
	 *
	 * @return list<array{group: string, action: string, direction: string}>
	 */
	private function describe(array $rules, string $action, string $direction): array {
		$changes = [];
		foreach ($rules as $rule) {
			$changes[] = ['group' => (string)$this->rights->groupOf(rule: $rule), 'action' => $action, 'direction' => $direction];
		}

		return $changes;
	}//end describe()

	/**
	 * Write the administrative audit row of one change, and log it.
	 *
	 * @param array{app: string, kind: string, slug: string, group: string, action: string, direction: string} $change The change.
	 *
	 * @return void
	 */
	private function audit(array $change): void {
		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'Product-owned group "%s": right %s %s on %s "%s", as declared by %s',
				[
					'group'     => $change['group'],
					'action'    => $change['action'],
					'direction' => $change['direction'],
					'kind'      => $change['kind'],
					'slug'      => $change['slug'],
					'app'       => $change['app'],
				]
			)
		);
		$this->logger->warning(message: '[GroupRightsReconciler] product-owned group right ' . $change['direction'], context: $change);
	}//end audit()

	/**
	 * The mapper that stores a register or a schema.
	 *
	 * @param string $kind `register` or `schema`.
	 *
	 * @return \OCA\OpenRegister\Db\RegisterMapperInterface|\OCA\OpenRegister\Db\SchemaMapperInterface
	 */
	private function mapperFor(string $kind): object {
		if ($kind === 'register') {
			return $this->sources->registerMapper;
		}

		return $this->sources->schemaMapper;
	}//end mapperFor()

}//end class
