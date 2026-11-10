<?php

/**
 * The explicit organisation accessors of the entities that share them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\Source;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * setOrganisation() marks the field updated even when the value does not change.
 */
class OrganisationFieldTraitTest extends TestCase {

	/**
	 * The entities.
	 *
	 * @return array<string, array{Entity}>
	 */
	public static function entities(): array {
		return [
			'application' => [new Application()],
			'federated share' => [new FederatedShare()],
			'source' => [new Source()],
		];
	}//end entities()

	/**
	 * Unchanged and changed values are both marked and read back.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return void
	 */
	#[DataProvider('entities')]
	public function testSetOrganisationAlwaysMarks(Entity $entity): void {
		$this->assertNull($entity->getOrganisation());
		$entity->setOrganisation(null);
		$this->assertSame(['organisation' => true], $entity->getUpdatedFields());
		$entity->setOrganisation('org-1');
		$this->assertSame('org-1', $entity->getOrganisation());
	}//end testSetOrganisationAlwaysMarks()
}//end class
