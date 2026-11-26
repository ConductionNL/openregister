<?php

/**
 * Adding files has no rate limit for signed-in users.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Files\Controller;

use OCA\OpenRegister\Files\Controller\FilesController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nextcloud applies an anonymous rate limit to signed-in users when no user limit is declared,
 * which capped an editor uploading a large batch at 30 files a minute. A user limit that cannot be
 * reached lifts that for signed-in users; anonymous callers keep theirs.
 *
 * @coversNothing
 */
final class FileAddingPostureTest extends TestCase {

	/**
	 * The methods that add files to an object.
	 *
	 * @return array<string, array{string}>
	 */
	public static function addingMethods(): array {
		return [
			'create'          => ['create'],
			'save'            => ['save'],
			'createMultipart' => ['createMultipart'],
		];
	}//end addingMethods()

	/**
	 * A signed-in user is not rate limited when adding files.
	 *
	 * @param string $method The FilesController method.
	 *
	 * @return void
	 */
	#[DataProvider('addingMethods')]
	public function testSignedInUsersAreNotRateLimited(string $method): void {
		$limits = (new ReflectionMethod(FilesController::class, $method))->getAttributes(UserRateLimit::class);

		$this->assertCount(1, $limits);
		$this->assertSame(PHP_INT_MAX, $limits[0]->newInstance()->getLimit());
	}//end testSignedInUsersAreNotRateLimited()

	/**
	 * Anonymous callers keep their limit.
	 *
	 * @param string $method The FilesController method.
	 *
	 * @return void
	 */
	#[DataProvider('addingMethods')]
	public function testAnonymousCallersKeepTheirLimit(string $method): void {
		$limits = (new ReflectionMethod(FilesController::class, $method))->getAttributes(AnonRateLimit::class);

		$this->assertCount(1, $limits);
		$this->assertSame(30, $limits[0]->newInstance()->getLimit());
	}//end testAnonymousCallersKeepTheirLimit()
}//end class
