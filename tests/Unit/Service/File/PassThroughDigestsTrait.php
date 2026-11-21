<?php

/**
 * A published digest service double that makes the file public and records nothing.
 *
 * For the tests of FilePublishingHandler that are about something else than the
 * digest: the share closure runs, so they see the share exactly as before the
 * digest was recorded. PublishedDigestTest drives the real service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Closure;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds the pass-through digest service.
 */
trait PassThroughDigestsTrait {

	/**
	 * A digest service whose publishSealed() only runs the share closure.
	 *
	 * @return PublishedDigestService&MockObject The double.
	 */
	private function passThroughDigests(): PublishedDigestService&MockObject {
		$record = $this->createStub(originalClassName: FileDigest::class);
		$digests = $this->createMock(originalClassName: PublishedDigestService::class);
		$digests->method('publishSealed')->willReturnCallback(
			static function (mixed $object, mixed $file, Closure $makePublic) use ($record): FileDigest {
				$makePublic();
				return $record;
			}
		);
		return $digests;
	}//end passThroughDigests()
}//end trait
