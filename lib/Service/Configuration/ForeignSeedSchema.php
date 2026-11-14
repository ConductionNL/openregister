<?php

/**
 * Whether a seed's schema belongs to another app and not to the seed's register.
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
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Tells a stale seed from one that may be written.
 *
 * A seed names its schema by slug, and the import resolves a slug it did not
 * import itself with a GLOBAL lookup. Two apps can ship a schema under the same
 * slug: stackiq and opencatalogi both used `organization`. When opencatalogi
 * dropped its own and kept a stale `organization` seed in its `publication`
 * register, the global lookup found stackiq's schema and the seed was written
 * into a `publication` x stackiq-`organization` table, failing NOT NULL on
 * stackiq's required fields. Whichever app installed second broke.
 *
 * Foreign means all three: the register lists schemas and this one is not among
 * them, the schema names an owning application, and that owner is neither the
 * importing app nor the register's own app. Each condition keeps a legitimate
 * seed working: a register with no schema list yet carries no evidence; an app's
 * own schema may not be linked yet on a first install that imports its register
 * fragments in several passes; and an app seeding into another app's register
 * uses a schema that register lists.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ForeignSeedSchema
{

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param string|null     $appId  The importing app, or null for an import without one.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?string $appId,
	) {
	}//end __construct()

	/**
	 * Whether a seed must be skipped because its schema is foreign.
	 *
	 * @param Register $register The register the seed names.
	 * @param Schema   $schema   The schema its slug resolved to.
	 *
	 * @return bool True when the seed must be skipped.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function isForeign(Register $register, Schema $schema): bool {
		if ($this->registerAdmits(register: $register, schema: $schema) === true
			|| $this->ownerIsAtHome(register: $register, schema: $schema) === true
		) {
			return false;
		}

		$this->logSkip(register: $register, schema: $schema);

		return true;
	}//end isForeign()

	/**
	 * Whether the register lists no schemas yet, or lists this one by id or slug.
	 *
	 * @param Register $register The register the seed names.
	 * @param Schema   $schema   The schema its slug resolved to.
	 *
	 * @return bool True when the register does not speak against the schema.
	 */
	private function registerAdmits(Register $register, Schema $schema): bool {
		$linked = array_map('strval', $register->getSchemas());

		return $linked === []
			|| in_array((string)$schema->getId(), $linked, true) === true
			|| in_array((string)$schema->getSlug(), $linked, true) === true;
	}//end registerAdmits()

	/**
	 * Whether the schema has no owner, or the importing app or the register's app owns it.
	 *
	 * @param Register $register The register the seed names.
	 * @param Schema   $schema   The schema its slug resolved to.
	 *
	 * @return bool True when the owner gives no reason to skip the seed.
	 */
	private function ownerIsAtHome(Register $register, Schema $schema): bool {
		$owner = (string)($schema->getApplication() ?? '');

		return $owner === ''
			|| $owner === (string)($this->appId ?? '')
			|| $owner === (string)($register->getApplication() ?? '');
	}//end ownerIsAtHome()

	/**
	 * Warn about a skipped seed.
	 *
	 * @param Register $register The register the seed names.
	 * @param Schema   $schema   The schema its slug resolved to.
	 *
	 * @return void
	 */
	private function logSkip(Register $register, Schema $schema): void {
		$this->logger->warning(
			message: ImportLogTag::PREFIX.'Skipping seed object: its schema belongs to another app and is not in its register',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'appId' => $this->appId,
				'registerSlug' => $register->getSlug(),
				'schemaSlug' => $schema->getSlug(),
				'schemaId' => $schema->getId(),
				'schemaApplication' => $schema->getApplication(),
			]
		);
	}//end logSkip()
}//end class
