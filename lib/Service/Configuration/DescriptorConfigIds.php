<?php

/**
 * The configuration id each of OpenRegister's own register descriptors is imported under.
 *
 * `ImportHandler::importFromApp()` stamps a version and a content hash per
 * configuration id, and the version gate compares against that stamp. Eight of
 * OpenRegister's descriptors therefore import under an id of their own
 * (`openregister.merge-operation`, ...): a gate shared with another descriptor
 * would skip whichever imports second. The repair steps that import them at
 * install and upgrade, and the administrator's re-import of the real
 * configuration ({@see RegisterDescriptorService::reimport()}), read this one
 * map, so a re-import is "the same configuration an install or upgrade
 * imports" (REQ-RDA-010) and does not move the version gate the other
 * descriptors share under `openregister`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Maps an OpenRegister descriptor file to the configuration id its import runs under.
 */
final class DescriptorConfigIds {

	/**
	 * The id the descriptors without a dedicated one share.
	 */
	public const SHARED = 'openregister';

	public const DECISION_TABLES = 'openregister.decision-tables';

	public const DISMISSED_PAIR = 'openregister.dismissed-pair';

	public const EDEPOT_TRANSFERS = 'openregister.edepot-transfers';

	public const DESTRUCTION_LISTS = 'openregister.destruction-lists';

	public const MERGE_OPERATION = 'openregister.merge-operation';

	public const TRUST_CONFIGURATION = 'openregister.trust-configuration';

	public const VOCABULARY = 'openregister.vocabulary';

	public const DSAR_CASES = 'openregister.dsar-cases';

	public const DSAR_POLICY_PACKS = 'openregister.dsar-policy-packs';

	/**
	 * The descriptors that import under an id of their own, by file name.
	 *
	 * @var array<string, string>
	 */
	public const BY_FILE = [
		'decision_table_register.json' => self::DECISION_TABLES,
		'dismissed_pair_register.json' => self::DISMISSED_PAIR,
		'edepot_transfer_register.json' => self::EDEPOT_TRANSFERS,
		'destruction_list_register.json' => self::DESTRUCTION_LISTS,
		'merge_operation_register.json' => self::MERGE_OPERATION,
		'trust_configuration_register.json' => self::TRUST_CONFIGURATION,
		'vocabulary_register.json' => self::VOCABULARY,
		'data_subject_request_register.json' => self::DSAR_CASES,
		'dsar_policy_pack_register.json' => self::DSAR_POLICY_PACKS,
	];

	/**
	 * The configuration id a descriptor of an app is imported under.
	 *
	 * Only OpenRegister's own descriptors have a dedicated id; any other app's
	 * file, whatever it is called, imports under the app's id.
	 *
	 * @param string $appId The app that ships the descriptor.
	 * @param string $file  The descriptor's file name.
	 *
	 * @return string The configuration id.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public static function forFile(string $appId, string $file): string {
		if ($appId !== self::SHARED) {
			return $appId;
		}

		return (self::BY_FILE[$file] ?? self::SHARED);
	}//end forFile()
}//end class
