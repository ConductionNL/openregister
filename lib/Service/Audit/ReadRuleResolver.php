<?php

/**
 * Which of a schema's read rules let the caller see an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ReadRuleMatch;
use OCA\OpenRegister\Kernel\Contracts\ReadRuleResolverInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\GroupRuleEntries;
use OCA\OpenRegister\Service\Object\GroupRuleRequest;
use OCA\OpenRegister\Service\Rbac\McpScope;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names the first entry of the schema's `read` list the caller matched.
 *
 * Runs after the read was allowed, for the read audit entry only: it never grants or
 * refuses anything. Each entry is judged by {@see GroupRuleEntries}, the same per-entry
 * matcher the permission check uses, so the named rule is one the check could have
 * granted on. No rule mediated the read, and none is named, for an administrator, the
 * object's owner, or a schema without read rules (or one opting in with `public: true`).
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */
final class ReadRuleResolver implements ReadRuleResolverInterface {

	/**
	 * The verb whose rules are read.
	 */
	private const ACTION = 'read';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas          Loads the object's schema.
	 * @param IUserSession          $userSession      The reader.
	 * @param IGroupManager         $groupManager     The reader's groups.
	 * @param ConditionMatcher      $conditionMatcher The shared `match` evaluator.
	 * @param LoggerInterface       $logger           Logs a schema that could not be read.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ConditionMatcher $conditionMatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rule that let the signed-in caller read the object, or null when no rule mediated it.
	 *
	 * Fail-soft: a schema that cannot be loaded names no rule rather than failing the read.
	 *
	 * @param ObjectEntityInterface $object The object that was read.
	 *
	 * @return ReadRuleMatch|null The rule, or null.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function resolve(ObjectEntityInterface $object): ?ReadRuleMatch {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ReadRuleResolver] schema of a read object could not be loaded; the read entry names no rule',
				context: ['schema' => $schemaId, 'exception' => $e::class]
			);
			return null;
		}

		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$groups = [];
		if ($user !== null) {
			$groups = $this->groupManager->getUserGroupIds($user);
		}

		return $this->matchFor(
			authorization: $schema->getAuthorization(),
			version: $schema->getVersion(),
			object: $object,
			caller: new ReadRuleCaller(userId: $uid, groups: $groups)
		);
	}//end resolve()

	/**
	 * The first `read` entry of an authorization block that admits the caller to the object.
	 *
	 * @param array<string, mixed>|null $authorization The schema's authorization block.
	 * @param string|null               $version       The schema version.
	 * @param ObjectEntityInterface     $object        The object read.
	 * @param ReadRuleCaller            $caller        The reader and their groups.
	 *
	 * @return ReadRuleMatch|null The rule, or null when none mediated the read.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function matchFor(?array $authorization, ?string $version, ObjectEntityInterface $object, ReadRuleCaller $caller): ?ReadRuleMatch {
		if ($this->bypassesRules(object: $object, caller: $caller) === true) {
			return null;
		}

		$entries = ($authorization[self::ACTION] ?? null);
		if (is_array($entries) === false || $entries === [] || (($authorization['public'] ?? null) === true)) {
			return null;
		}

		$matcher = new GroupRuleEntries(conditionMatcher: $this->conditionMatcher);
		foreach ($entries as $entry) {
			if ((is_string($entry) === false && is_array($entry) === false) || McpScope::isScopeEntry(entry: $entry) === true) {
				continue;
			}

			if ($this->entryAdmits(matcher: $matcher, entry: $entry, authorization: $authorization, object: $object, caller: $caller) === true) {
				return new ReadRuleMatch(rule: $entry, digest: RuleDigest::ofEntry(entry: $entry), version: $version);
			}
		}

		return null;
	}//end matchFor()

	/**
	 * Whether the caller reads without a rule: an administrator, or the object's owner.
	 *
	 * @param ObjectEntityInterface $object The object read.
	 * @param ReadRuleCaller $caller The reader.
	 *
	 * @return bool True for a bypass.
	 */
	private function bypassesRules(ObjectEntityInterface $object, ReadRuleCaller $caller): bool {
		if ($caller->userId === null) {
			return false;
		}

		return in_array('admin', $caller->groups, true) === true || $object->getOwner() === $caller->userId;
	}//end bypassesRules()

	/**
	 * Whether one entry admits the caller through any principal the caller holds.
	 *
	 * @param GroupRuleEntries     $matcher       The per-entry matcher.
	 * @param array<mixed>|string  $entry         The rule entry.
	 * @param array<string, mixed> $authorization The block the entry belongs to.
	 * @param ObjectEntityInterface $object        The object read.
	 * @param ReadRuleCaller       $caller        The reader.
	 *
	 * @return bool True when the entry admits the caller.
	 */
	private function entryAdmits(GroupRuleEntries $matcher, array|string $entry, array $authorization, ObjectEntityInterface $object, ReadRuleCaller $caller): bool {
		foreach ($caller->principals() as $principal) {
			$request = (new GroupRuleRequest(authorization: $authorization, action: self::ACTION))
				->forGroup(groupId: $principal, userGroup: null)
				->forUser(userId: $caller->userId, objectOwner: $object->getOwner())
				->onObject(data: self::matchEnvelope(object: $object), organisation: $object->getOrganisation());
			if ($matcher->grants(entry: $entry, request: $request) === true) {
				return true;
			}
		}

		return false;
	}//end entryAdmits()

	/**
	 * The data a `match` clause reads: the entity's own envelope, as the permission check builds it.
	 *
	 * @param ObjectEntityInterface $object The object read.
	 *
	 * @return array<string, mixed>
	 */
	private static function matchEnvelope(ObjectEntityInterface $object): array {
		if ($object instanceof ObjectEntity) {
			return $object->getMatchEnvelope();
		}

		return $object->getObject();
	}//end matchEnvelope()
}//end class
