<?php

/**
 * MetadataConditionFindingsInterface: what callers outside the component use of MetadataConditionFindings.
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
 * Declares only what callers outside the component use of MetadataConditionFindings; the rest
 * of the class stays its own.
 */
interface MetadataConditionFindingsInterface {

	/**
	 * Refuse a schema save that adds or changes a condition on unknown metadata.
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
	 * Refuse a register save that adds or changes a condition on unknown metadata.
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
	 * One sentence per rule of an authorization block that names unknown metadata.
	 *
	 * Covers the grant rules of every action and the entries of the `deny`
	 * block. Other control keys are not rule lists and are left to their own
	 * validators. A condition the stored block already holds is not a finding, so
	 * a save can remove a bad rule or edit around one; only adding or changing one
	 * is refused.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 * @param array|null $stored        The block as stored, when saving over one.
	 *
	 * @return array<int, string> The findings; empty when every condition can match.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function findings(?array $authorization, string $context, ?array $stored = null): array;
}//end interface
