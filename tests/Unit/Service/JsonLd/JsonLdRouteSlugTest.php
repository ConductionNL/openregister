<?php

/**
 * Unit coverage for JsonLdRouteSlug: slug, then uuid, then id.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\JsonLd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\JsonLd\JsonLdRouteSlug;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fallback order of a route identifier.
 */
final class JsonLdRouteSlugTest extends TestCase {

	/**
	 * A slug wins, an empty slug falls to the uuid, and an empty uuid to the id.
	 *
	 * @return void
	 */
	public function testTheSlugThenTheUuidThenTheIdIsUsed(): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setUuid('s-uuid');
		$schema->setSlug('cases');
		$this->assertSame('cases', JsonLdRouteSlug::of($schema));

		$schema->setSlug('');
		$this->assertSame('s-uuid', JsonLdRouteSlug::of($schema));

		$register = new Register();
		$register->setId(3);
		$register->setUuid('');
		$this->assertSame('3', JsonLdRouteSlug::of($register));
	}//end testTheSlugThenTheUuidThenTheIdIsUsed()
}//end class
