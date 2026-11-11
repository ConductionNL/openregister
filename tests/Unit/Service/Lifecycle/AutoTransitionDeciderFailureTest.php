<?php

/**
 * AutoTransitionDecider: an object or schema that cannot be read decides nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecider;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The two read failures before any transition is selected.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecider
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class AutoTransitionDeciderFailureTest extends TestCase {

	/**
	 * The deferred entry the decider is asked about.
	 *
	 * @var array<string, string>
	 */
	private const ENTRY = ['uuid' => 'o-1', 'register' => '3', 'schema' => '7'];

	/**
	 * An object that cannot be re-read is logged and decides nothing; the schema is never asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectDecidesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DeferredEntryObjectResolver::class)->willThrowException(new RuntimeException('no resolver'));
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->never())->method('find');
		$selector = $this->createMock(AutoTransitionSelector::class);
		$selector->expects($this->never())->method('select');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[AutoTransitionRunner] Could not re-read the object to decide an automatic transition.',
			['uuid' => 'o-1', 'schema' => '7', 'error' => 'no resolver']
		);

		$this->assertNull((new AutoTransitionDecider($container, new SchemaReading($schemas, $logger), $selector))->decide(self::ENTRY, []));
	}//end testAnUnreadableObjectDecidesNothing()

	/**
	 * A schema that cannot be loaded decides nothing and selects nothing.
	 *
	 * @return void
	 */
	public function testAnUnloadableSchemaDecidesNothing(): void {
		$resolver = $this->createMock(DeferredEntryObjectResolver::class);
		$resolver->expects($this->once())->method('resolve')->with(self::ENTRY)->willReturn(new ObjectEntity());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($resolver);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('find')->with('7')->willThrowException(new RuntimeException('gone'));
		$selector = $this->createMock(AutoTransitionSelector::class);
		$selector->expects($this->never())->method('select');

		$this->assertNull((new AutoTransitionDecider($container, new SchemaReading($schemas, $this->createMock(LoggerInterface::class)), $selector))->decide(self::ENTRY, []));
	}//end testAnUnloadableSchemaDecidesNothing()
}//end class
