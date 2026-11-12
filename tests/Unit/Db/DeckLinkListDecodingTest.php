<?php

/**
 * DeckLink label and assignee decoding tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DeckLink;
use PHPUnit\Framework\TestCase;

/**
 * Stored JSON lists decode to arrays; blank or non-list JSON reads as empty.
 */
class DeckLinkListDecodingTest extends TestCase {

	/**
	 * A stored JSON list is served decoded.
	 *
	 * @return void
	 */
	public function testStoredJsonListsAreDecoded(): void {
		$link = new DeckLink();
		$link->setLabels('[{"title":"urgent"}]');
		$link->setAssignees('["jan","piet"]');

		$json = $link->jsonSerialize();

		$this->assertSame([['title' => 'urgent']], $json['labels']);
		$this->assertSame(['jan', 'piet'], $json['assignees']);
	}//end testStoredJsonListsAreDecoded()

	/**
	 * Empty, missing or scalar JSON reads as an empty list.
	 *
	 * @return void
	 */
	public function testBlankOrScalarJsonReadsAsEmpty(): void {
		$link = new DeckLink();
		$link->setLabels('');
		$link->setAssignees('"jan"');

		$json = $link->jsonSerialize();

		$this->assertSame([], $json['labels']);
		$this->assertSame([], $json['assignees']);
	}//end testBlankOrScalarJsonReadsAsEmpty()
}//end class
