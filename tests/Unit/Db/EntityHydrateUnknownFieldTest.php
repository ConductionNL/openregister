<?php

/**
 * Consumer and EndpointLog hydrate edge tests.
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

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\EndpointLog;
use PHPUnit\Framework\TestCase;

/**
 * hydrate() keeps an empty JSON list as a list and skips keys with no setter
 * instead of aborting the rest of the payload.
 */
class EntityHydrateUnknownFieldTest extends TestCase {

	/**
	 * A Consumer payload with an unknown key still sets every known field after it.
	 *
	 * @return void
	 */
	public function testConsumerSkipsUnknownKeysAndKeepsEmptyLists(): void {
		$consumer = new Consumer();

		$result = $consumer->hydrate(['bogusField' => 'x', 'name' => 'Portal', 'domains' => [], 'ips' => ['10.0.0.1']]);

		$this->assertSame($consumer, $result);
		$this->assertSame('Portal', $consumer->getName());
		$this->assertSame([], $consumer->getDomains());
		$this->assertSame(['10.0.0.1'], $consumer->getIps());
		$this->assertArrayNotHasKey('bogusField', $consumer->getUpdatedFields());
	}//end testConsumerSkipsUnknownKeysAndKeepsEmptyLists()

	/**
	 * An EndpointLog payload with an unknown key still sets every known field after it.
	 *
	 * @return void
	 */
	public function testEndpointLogSkipsUnknownKeysAndKeepsEmptyLists(): void {
		$log = new EndpointLog();

		$result = $log->hydrate(['notAColumn' => 1, 'statusCode' => 502, 'request' => [], 'response' => ['ok' => false]]);

		$this->assertSame($log, $result);
		$this->assertSame(502, $log->getStatusCode());
		$this->assertSame([], $log->getRequest());
		$this->assertSame(['ok' => false], $log->getResponse());
		$this->assertArrayHasKey('request', $log->getUpdatedFields());
		$this->assertArrayNotHasKey('notAColumn', $log->getUpdatedFields());
	}//end testEndpointLogSkipsUnknownKeysAndKeepsEmptyLists()
}//end class
