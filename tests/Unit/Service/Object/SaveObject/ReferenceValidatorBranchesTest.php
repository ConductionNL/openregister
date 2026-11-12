<?php

/**
 * ReferenceValidator branches: which properties are checked, URLs, and strict versus warn.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard;
use OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the validator checks and what it does with a refusal.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceCheck
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Exception\BrokenReference
 * @uses \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Event\AbstractReferenceValidationEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidatedEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidationFailedEvent
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
class ReferenceValidatorBranchesTest extends TestCase {

	/**
	 * The object finder the existence check reads.
	 *
	 * @var MagicObjectFinderInterface&MockObject
	 */
	private MagicObjectFinderInterface $finder;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Uuids the finder was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $looked = [];

	/**
	 * Build the boundaries: one schema "contact" (id 5); the finder knows only "c-ok".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->finder = $this->createMock(MagicObjectFinderInterface::class);
		$this->finder->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				$this->looked[] = (string)$identifier;
				if ($identifier !== 'c-ok') {
					throw new DoesNotExistException('missing');
				}

				return new ObjectEntity();
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The validator with real collaborators over mocked mappers.
	 *
	 * @return ReferenceValidator
	 */
	private function validator(): ReferenceValidator {
		$contact = new Schema();
		$contact->setId(5);
		$contact->setSlug('contact');
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([$contact]);
		$schemas->method('find')->willReturn($contact);
		$lookups = new SaveLookups($schemas, $this->createMock(RegisterMapperInterface::class));
		$events = new ReferenceEvents($this->createMock(IEventDispatcher::class), $this->logger);

		return new ReferenceValidator(
			new ReferenceExistence($lookups, new SaveCallStack(), $this->finder, $events),
			new ReferenceFilterGuard($lookups, $this->createMock(MagicMapperInterface::class)),
			$events,
			$this->logger
		);
	}//end validator()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * Unvalidated, unreferenced, empty and unchanged properties are not looked up; a list is checked item by item, skipping empty ones.
	 *
	 * @return void
	 */
	public function testOnlyChangedValidatedReferencesAreLookedUp(): void {
		$schema = $this->schema(
			[
				'plain' => ['$ref' => 'contact'],
				'noRef' => ['type' => 'string', 'validateReference' => true],
				'off' => ['$ref' => 'contact', 'validateReference' => true, 'validationStrictness' => 'off'],
				'blank' => ['$ref' => 'contact', 'validateReference' => true],
				'same' => ['$ref' => 'contact', 'validateReference' => true],
				'many' => ['type' => 'array', 'items' => ['$ref' => 'contact'], 'validateReference' => true],
			]
		);
		$run = ReferencePropertyRun::forSave(data: ['plain' => 'x', 'noRef' => 'x', 'off' => 'x', 'blank' => '', 'same' => 'old', 'many' => ['c-ok', '', 'c-ok']], register: null);
		$run->oldData = ['same' => 'old'];

		$this->validator()->validate($schema, $run);

		$this->assertSame(['c-ok'], $this->looked, 'the second c-ok is answered from the verdict cache');
	}//end testOnlyChangedValidatedReferencesAreLookedUp()

	/**
	 * A strict reference to a missing object refuses the save.
	 *
	 * @return void
	 */
	public function testAStrictMissingReferenceRefuses(): void {
		$schema = $this->schema(['owner' => ['$ref' => 'contact', 'validateReference' => true]]);

		$this->expectException(ReferenceValidationException::class);
		$this->validator()->validate($schema, ReferencePropertyRun::forSave(data: ['owner' => 'c-gone'], register: null));
	}//end testAStrictMissingReferenceRefuses()

	/**
	 * A warn-only reference to a missing object is logged with its target, and the save goes on.
	 *
	 * @return void
	 */
	public function testAWarnOnlyMissingReferenceIsLogged(): void {
		$schema = $this->schema(['owner' => ['$ref' => 'contact', 'validateReference' => true, 'validationStrictness' => 'warn', 'register' => null]]);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context['property'] ?? null, $context['uuid'] ?? null, $context['targetSchema'] ?? null];
			}
		);

		$this->validator()->validate($schema, ReferencePropertyRun::forSave(data: ['owner' => 'c-gone'], register: null));

		$this->assertContains(['[SaveObject] Reference validation failed (warn-only)', 'owner', 'c-gone', 'contact'], $warnings);
	}//end testAWarnOnlyMissingReferenceIsLogged()

	/**
	 * A well-formed http(s) URL is accepted without a lookup; a malformed one is refused in URL terms with the bare schema slug.
	 *
	 * @return void
	 */
	public function testUrlReferences(): void {
		$schema = $this->schema(['site' => ['$ref' => '#/components/schemas/contact', 'validateReference' => true]]);
		$validator = $this->validator();

		$validator->validate($schema, ReferencePropertyRun::forSave(data: ['site' => 'https://example.org/x'], register: null));
		$this->assertSame([], $this->looked);

		$messages = [];
		foreach (['http://', 'https:///nohost', 'http:///x'] as $url) {
			try {
				$validator->validate($schema, ReferencePropertyRun::forSave(data: ['site' => $url], register: null));
			} catch (ReferenceValidationException $e) {
				$messages[] = [$e->getMessage(), $e->getTargetSchemaSlug()];
			}
		}

		$this->assertSame('External URL reference "http://" is malformed: scheme MUST be http or https and host MUST parse.', $messages[0][0]);
		$this->assertSame('contact', $messages[0][1]);
		$this->assertCount(3, $messages);
		$this->assertSame([], $this->looked, 'a URL is never looked up as a uuid');
	}//end testUrlReferences()
}//end class
