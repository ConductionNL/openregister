<?php

/**
 * OpenRegister AppHost: the register holding applications and their versions.
 *
 * Its slug differs per instance, and a schema of the same name in any other
 * register is not one of ours.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Places an object in the applications register.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ApplicationRegister {
	/**
	 * The canonical slug of the register holding applications.
	 */
	private const CANONICAL = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registers Names an object's register.
	 * @param SchemaMapper $schemas Names an object's schema.
	 * @param RegisterSlugResolverInterface $slugs Finds the slug this instance carries.
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly RegisterSlugResolverInterface $slugs,
	) {
	}//end __construct()

	/**
	 * The slug the register has on this instance.
	 *
	 * @return string|null The slug, or null when the instance has none.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function slug(): ?string {
		return $this->slugs->slugOrNull(canonical: self::CANONICAL);
	}//end slug()

	/**
	 * Whether an object is of this schema in this register.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $schema The schema slug.
	 *
	 * @return bool False for a same-named schema in any other register.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function holds(ObjectEntity $object, string $schema): bool {
		try {
			$register = $this->registers->find(id: (string)$object->getRegister(), _rbac: false, _multitenancy: false);
			$found = $this->schemas->find(id: (string)$object->getSchema(), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return false;
		}

		return $register->getSlug() === $this->slug() && $found->getSlug() === $schema;
	}//end holds()
}//end class
