<?php

/**
 * UnknownRuleKeyFindingsInterface: what callers outside the component use of UnknownRuleKeyFindings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationBlockException;

/**
 * Declares only what callers outside the component use of UnknownRuleKeyFindings; the rest
 * of the class stays its own.
 */
interface UnknownRuleKeyFindingsInterface {

	/**
	 * Refuse a schema save that adds or changes a condition on an unknown or malformed key.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the save adds or changes one.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertSchemaSavable(Schema $schema): void;

	/**
	 * Refuse a register save that adds or changes a condition on a malformed key.
	 *
	 * @param Register $register The register being saved.
	 * @param string   $subject  What the block belongs to, for the message.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the save adds or changes one.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertRegisterSavable(Register $register, string $subject): void;

	/**
	 * The findings of a schema's own block and of every property's rules.
	 *
	 * @param Schema      $schema The schema.
	 * @param Schema|null $stored The schema as stored, when saving over one.
	 *
	 * @return array<int, string> The findings.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function schemaFindings(Schema $schema, ?Schema $stored = null): array;

	/**
	 * The findings of a register's block, where only a key's form can be judged.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 * @param array|null $stored        The block as stored, when saving over one.
	 *
	 * @return array<int, string> The findings.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function registerFindings(?array $authorization, string $context, ?array $stored = null): array;
}//end interface
