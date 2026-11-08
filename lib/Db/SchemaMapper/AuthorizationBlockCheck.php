<?php

/**
 * AuthorizationBlockCheck: the save-time check of a schema's authorization block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\AuthorizationDeclarationValidators;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * Refuses a block with unknown verbs or rules, or whose deny contradicts its grants.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AuthorizationBlockCheck {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection    $db              Reads the columns a rule may name.
	 * @param IEventDispatcher $eventDispatcher Collects the permission catalogue.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Refuse an authorization block whose deny contradicts its own grants.
	 *
	 * A deny is the first rule in this layer that subtracts, and the two
	 * contradictions it makes possible are cheap to catch here and expensive to
	 * discover later: a principal granted and denied the same verb at one level,
	 * and a register left with no principal holding `manage`.
	 *
	 * The refusal is HTTP 422 rather than 400. The request was understood and
	 * well formed; the rules inside it disagree with each other, and the message
	 * names both so the author knows which two to look at.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\AuthorizationBlockException When the block contradicts itself.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function check(Schema $schema): void {
		AuthorizationDeclarationValidators::metadataConditions(db: $this->db)->assertSchemaSavable(schema: $schema);
		AuthorizationDeclarationValidators::unknownRuleKeys(db: $this->db)->assertSchemaSavable(schema: $schema);

		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === true && $authorization !== []) {
			$this->assertGrantsAndDeny(schema: $schema, authorization: $authorization);
		}
	}//end check()

	/**
	 * Check the verbs of a block against the catalogue, then its deny against its grants.
	 *
	 * The catalogue check runs FIRST. An unknown verb is the mistake that
	 * survives longest: it matches nothing, so it grants nothing and denies
	 * nothing, and the block looks correct in every screen that shows it.
	 * Refusing it here is what publishing the set is for.
	 *
	 * @param Schema               $schema        The schema being saved.
	 * @param array<string, mixed> $authorization The block as written.
	 *
	 * @return void
	 */
	private function assertGrantsAndDeny(Schema $schema, array $authorization): void {
		$subject = sprintf('the schema "%s"', (string)($schema->getSlug() ?? $schema->getTitle() ?? ''));
		AuthorizationDeclarationValidators::permissionCatalogue(eventDispatcher: $this->eventDispatcher)->assertGrantable(
			authorization: $this->withoutDeclaredActions(authorization: $authorization, declared: $schema->declaredActionNames()),
			roleDefinitions: null,
			subject: $subject
		);

		AuthorizationDeclarationValidators::denyBlocks()->assertStorable(
			authorization: $authorization,
			subject: $subject
		);
	}//end assertGrantsAndDeny()

	/**
	 * The block without the actions the schema declares itself, at its top level and in its deny.
	 *
	 * A schema may grant its own `x-openregister-action` entries
	 * (declared-actions spec), which the app-wide catalogue does not list.
	 *
	 * @param array<string, mixed> $authorization The block as written.
	 * @param array<int, string>   $declared      The schema's declared action keys.
	 *
	 * @return array<string, mixed> The block the catalogue checks.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private function withoutDeclaredActions(array $authorization, array $declared): array {
		$names = array_flip($declared);
		$checked = array_diff_key($authorization, $names);
		if (is_array($checked[DenyResolverInterface::DENY_KEY] ?? null) === true) {
			$checked[DenyResolverInterface::DENY_KEY] = array_diff_key($checked[DenyResolverInterface::DENY_KEY], $names);
		}

		return $checked;
	}//end withoutDeclaredActions()
}//end class
