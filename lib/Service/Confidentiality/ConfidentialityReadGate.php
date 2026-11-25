<?php

/**
 * ConfidentialityReadGate: refuses a single-object read the confidentiality block does not admit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Confidentiality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Confidentiality;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use Psr\Log\LoggerInterface;

/**
 * The PHP side of the derived confidentiality rule.
 *
 * The rule restricts: it is a condition every read must meet on top of the
 * schema's own authorization, never a grant beside it. Combining it with OR
 * would let its "untiered objects are open to everyone" condition reopen
 * objects an authored rule keeps from the caller.
 *
 * The conditions are the ones {@see ConfidentialityRule::readRules()} derives,
 * evaluated through the shared {@see ConditionMatcher}, so `$now` and every
 * operator resolve exactly as they do for authored rules, and the list query
 * (MagicRbacHandler) compiles the same conditions into SQL.
 *
 * Owner and administrator pass, as they do for every authored rule.
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
 */
final class ConfidentialityReadGate {

	/**
	 * The action the block governs.
	 */
	public const READ = 'read';

	/**
	 * The schemas whose fail-closed block was already logged this request, by key.
	 *
	 * @var array<string, true>
	 */
	private static array $logged = [];

	/**
	 * Constructor.
	 *
	 * @param ConditionMatcher $matcher Evaluates the derived match conditions.
	 * @param LoggerInterface  $logger  Records a block that fails closed.
	 */
	public function __construct(
		private readonly ConditionMatcher $matcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a schema declares a confidentiality block at all.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-schema-with-no-confidentiality-annotation-is-unaffected
	 */
	public static function declares(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])[ConfidentialityAnnotationValidator::ANNOTATION] ?? null) !== null;
	}//end declares()

	/**
	 * The rule a schema's block derives, or null when the schema declares none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return ConfidentialityRule|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ConfidentialityRule is built by its named constructor.
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-schema-with-no-confidentiality-annotation-is-unaffected
	 */
	public static function ruleFor(Schema $schema): ?ConfidentialityRule {
		if (self::declares(schema: $schema) === false) {
			return null;
		}

		$block = ($schema->getConfiguration() ?? [])[ConfidentialityAnnotationValidator::ANNOTATION];

		return ConfidentialityRule::fromBlock(block: $block, properties: ($schema->getProperties() ?? []));
	}//end ruleFor()

	/**
	 * Log, once per schema and request, that a faulty block hides every tiered object.
	 *
	 * @param LoggerInterface     $logger The logger.
	 * @param Schema              $schema The schema.
	 * @param ConfidentialityRule $rule   Its rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-malformed-confidentiality-annotation-must-fail-closed-not-silently-permit-access
	 */
	public static function logFailClosed(LoggerInterface $logger, Schema $schema, ConfidentialityRule $rule): void {
		if ($rule->failsClosed() === false) {
			return;
		}

		$key = (string)($schema->getId() ?? $schema->getSlug());
		if (isset(self::$logged[$key]) === true) {
			return;
		}

		self::$logged[$key] = true;
		$logger->error(
			message: '[Confidentiality] Invalid confidentiality annotation on schema "{schema}"; objects with a tier stay hidden until fixed: {errors}',
			context: ['schema' => (string)$schema->getSlug(), 'errors' => implode('; ', $rule->errors())]
		);
	}//end logFailClosed()

	/**
	 * Whether the block refuses this caller this object.
	 *
	 * @param Schema               $schema   The object's schema.
	 * @param string               $action   The action asked.
	 * @param array<string, mixed> $envelope The object as conditions read it (its data plus `@self`).
	 * @param string|null          $userId   The caller, null when anonymous.
	 * @param array<int, string>   $groups   The caller's groups.
	 * @param string|null          $owner    The object's owner.
	 * @param bool|null            $administrator Nextcloud's administrator verdict for the caller, or null to read the groups.
	 *
	 * @return bool True when the read is refused.
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function refuses(Schema $schema, string $action, array $envelope, ?string $userId, array $groups, ?string $owner, ?bool $administrator=null): bool {
		if ($action !== self::READ) {
			return false;
		}

		$rule = self::ruleFor(schema: $schema);
		if ($rule === null) {
			return false;
		}

		// The bypasses every authored rule has: the administrator and the owner.
		// A verdict from Nextcloud wins over the group list, so an `admin` member
		// outside `allowed_admin_ranges` is not let past here.
		$isAdministrator = ($administrator ?? in_array('admin', $groups, true));
		if ($isAdministrator === true || ($userId !== null && $owner === $userId)) {
			return false;
		}

		self::logFailClosed(logger: $this->logger, schema: $schema, rule: $rule);
		foreach ($rule->readRules() as $derived) {
			if ($this->qualifies(group: $derived['group'], groups: $groups) === true
				&& $this->matcher->objectMatchesConditions(object: $envelope, match: $derived['match']) === true
			) {
				return false;
			}
		}

		return true;
	}//end refuses()

	/**
	 * Whether a derived condition's group applies to the caller.
	 *
	 * The everyone pseudo-group applies to every caller, signed in or not,
	 * whatever the schema says about inheriting `public` rights: the derived
	 * conditions only narrow, so this cannot open anything.
	 *
	 * @param string             $group  The condition's group.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return bool
	 */
	private function qualifies(string $group, array $groups): bool {
		return $group === ConfidentialityRule::EVERYONE || in_array($group, $groups, true) === true;
	}//end qualifies()
}//end class
