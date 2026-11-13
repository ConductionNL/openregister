<?php

/**
 * Unit tests for the links a contact's cases panel is built from.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\ContactCasesPanel;
use OCA\OpenRegister\Service\Integration\ContactCasesResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A link that is no array is not a case and is not counted; real links are, and no resolve is attempted without a uuid.
 *
 * @covers \OCA\OpenRegister\Service\Integration\ContactCasesResolver
 * @uses   \OCA\OpenRegister\Service\Integration\ContactCasesPanel
 * @uses \OCA\OpenRegister\Service\Integration\ContactCasesPanel
 */
class ContactCasesResolverLinksTest extends TestCase {

	/**
	 * Non-array links are skipped before counting.
	 *
	 * @return void
	 */
	public function testANonArrayLinkIsSkipped(): void {
		$resolved = [];
		$panel    = (new ContactCasesResolver(new ContactCasesPanel(), $this->createMock(LoggerInterface::class)))->panelFor(
			[['schema' => 'zaak', 'objectUuid' => ''], 'garbage', 42],
			static function (...$args) use (&$resolved) {
				$resolved[] = $args;
				return null;
			}
		);

		$this->assertSame(1, $panel['total']);
		$this->assertFalse($panel['truncated']);
		$this->assertSame([], $resolved, 'a link without a uuid is not resolved');

	}//end testANonArrayLinkIsSkipped()
}//end class
