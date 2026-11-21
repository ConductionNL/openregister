<?php

/**
 * An unrecognised object write failure keeps database text out of the answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use Exception;
use OCA\OpenRegister\Controller\Support\WriteFailureAnswer;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The answer to a write no route recognises.
 */
class WriteFailureAnswerTest extends TestCase {

	private const SQL = 'SQLSTATE[42703]: Undefined column: column "_owner_1" of relation "oc_openregister_table_7_9" does not exist';

	/**
	 * A refusal without a database error keeps its 403 and its message.
	 *
	 * @return void
	 */
	public function testAPlainRefusalKeepsItsMessage(): void {
		$response = (new WriteFailureAnswer(logger: new NullLogger()))->respond(exception: new Exception(message: 'You may not create objects here.'));

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'You may not create objects here.'], actual: $response->getData());
	}//end testAPlainRefusalKeepsItsMessage()

	/**
	 * A database error wrapped in another exception answers without its SQL, and the log keeps it.
	 *
	 * @return void
	 */
	public function testAWrappedDatabaseErrorAnswersWithoutItsText(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$failure = new Exception(message: 'Failed to insert object: ' . self::SQL, previous: new PDOException(message: self::SQL));

		$response = (new WriteFailureAnswer(logger: $logger))->respond(exception: $failure);
		$body     = (string)json_encode(value: $response->getData());

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertStringNotContainsString(needle: 'SQLSTATE', haystack: $body);
		$this->assertStringNotContainsString(needle: 'oc_openregister_table', haystack: $body);
	}//end testAWrappedDatabaseErrorAnswersWithoutItsText()
}//end class
