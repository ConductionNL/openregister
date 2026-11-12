<?php

/**
 * OpenRegister readable export objects
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The objects of a configuration export as the caller may read them.
 *
 * An administrator, and a run without a signed-in user (install, repair,
 * background job), export the stored objects. Anyone else gets each object
 * through the schema's property read rules, without write-only values and
 * with explicit-reveal values masked, the way a read shows it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ReadableExportObjects {
	/**
	 * Constructor.
	 *
	 * @param PropertyRbacHandler $properties   The property read rules.
	 * @param IUserSession        $userSession  The caller.
	 * @param IGroupManager       $groupManager Says whether the caller is an administrator.
	 */
	public function __construct(
		private readonly PropertyRbacHandler $properties,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * One serialised object as the caller may export it.
	 *
	 * @param ObjectEntity         $object     The stored object.
	 * @param Schema|null          $schema     Its schema; without one no property is exported.
	 * @param array<string, mixed> $serialised The object as `jsonSerialize()` gives it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function project(ObjectEntity $object, ?Schema $schema, array $serialised): array {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return $serialised;
		}

		$self = ($serialised['@self'] ?? []);
		if ($schema === null) {
			return ['@self' => $self];
		}

		unset($serialised['@self']);
		$readable = $object->filterAsRecord(
			data: $serialised,
			filter: fn (array $probe): array => $this->properties->filterReadableProperties(schema: $schema, object: $probe)
		);

		$projected = ExplicitRevealMasks::maskAll(schema: $schema, object: $readable);
		$projected['@self'] = $self;

		return $projected;
	}//end project()
}//end class
