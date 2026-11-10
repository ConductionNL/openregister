<?php

/**
 * Tests for SipContents.
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

use OCA\OpenRegister\Service\Edepot\SipContents;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipContents
 */
class SipContentsTest extends TestCase {

	public function testEveryEntryIsListedAndTheManifestComesLast(): void {
		$contents = new SipContents();
		$contents->addMetadataDocument(uuid: 'u', path: 'objects/u/mdto.xml', content: '<m/>');
		$contents->addFile(path: 'objects/u/content/original/a', file: ['path' => '/tmp/a', 'size' => 3, 'checksum' => 'c']);
		$row = $contents->addDocument(path: 'mets.xml', content: 'mets');

		$this->assertSame(['path' => 'mets.xml', 'size' => 4, 'checksum' => hash('sha256', 'mets')], $row);
		$this->assertSame(['u' => [['path' => 'objects/u/mdto.xml', 'size' => 4, 'checksum' => hash('sha256', '<m/>')]]], $contents->metadataFiles());

		$entries = $contents->entries();
		$this->assertSame(['objects/u/mdto.xml', 'objects/u/content/original/a', 'mets.xml', 'sip-manifest.json'], array_column($entries, 'path'));
		$this->assertSame('file', $entries[1]['kind']);
		$this->assertSame('/tmp/a', $entries[1]['filePath']);
		$manifest = json_decode($entries[3]['content'], true);
		$this->assertSame(['objects/u/mdto.xml', 'objects/u/content/original/a', 'mets.xml'], array_column($manifest, 'path'));
		$this->assertSame('c', $manifest[1]['checksum']);
	}
}//end class
