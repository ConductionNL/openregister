<?php

/**
 * Tests for BagitPayload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\BagitPayload;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\BagitPayload
 */
class BagitPayloadTest extends TestCase {

	public function testThePayloadIsCountedAndAnUnreadableFileRefusesTheBag(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'bag');
		$zip  = new ZipArchive();
		$this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);

		$payload = new BagitPayload();
		$payload->add(zip: $zip, entry: ['path' => 'a.txt', 'kind' => 'string', 'content' => 'abc']);
		$tags = $payload->tagFiles(transferId: 't1');

		$this->assertSame(['bagit.txt', 'bag-info.txt', 'manifest-sha256.txt', 'tagmanifest-sha256.txt'], array_keys($tags));
		$this->assertStringContainsString("Payload-Oxum: 3.1\n", $tags['bag-info.txt']);
		$this->assertStringContainsString("External-Identifier: t1\n", $tags['bag-info.txt']);
		$this->assertSame(hash('sha256', 'abc')."  data/a.txt\n", $tags['manifest-sha256.txt']);

		try {
			$payload->add(zip: $zip, entry: ['path' => 'b.bin', 'kind' => 'file', 'filePath' => '/nonexistent/b.bin']);
			$this->fail('An unreadable payload file must refuse the bag');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('refusing an incomplete manifest', $e->getMessage());
		} finally {
			$zip->close();
			@unlink($path);
		}
	}
}//end class
