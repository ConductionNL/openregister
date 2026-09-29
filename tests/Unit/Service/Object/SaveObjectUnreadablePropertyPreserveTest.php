<?php

declare(strict_types=1);

/**
 * A full save keeps a property the writer was not allowed to read (openregister#4170).
 *
 * The read path strips a property whose authorization.read the caller fails, so
 * the natural GET, edit, PUT round trip sends a body without it, and the PUT
 * null-fill erased the stored value. The rule is the write-only one (#463)
 * extended to read-restricted properties, and this test drives it through the
 * real update path with a real PropertyRbacHandler.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;

/**
 * Proves the save-side preserve rule is actually WIRED INTO the update path, not merely
 * implemented next to it.
 *
 * PropertyRbacHandlerWriteOnlyPreserveTest pins the rule's behaviour in isolation. This
 * one pins the thing that isolation cannot: that SaveObject::prepareObjectForUpdate()
 * invokes it, with a REAL PropertyRbacHandler, at the one point in the sequence where it
 * works — after prepareObjectData (so an encrypted stored value is not double-encrypted)
 * and before fillMissingSchemaPropertiesWithNull (which materialises every absent property
 * as null, after which an omitted secret and a deliberately-cleared one are byte-identical).
 *
 * A correct implementation placed one line later is a silent no-op that these assertions
 * catch and the isolated tests would not.
 */
class SaveObjectUnreadablePropertyPreserveTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private SaveObject $handler;
	private SchemaMapper $schemaMapper;

	/** @var array<int, string> The groups the writer is in. */
	private array $writerGroups = ['managers'];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		// The writer is a manager: not in `hr`, which alone may read the BSN.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(fn () => $this->writerGroups);

		// A REAL PropertyRbacHandler: the point of this test is the collaboration.
		$propertyRbacHandler = new PropertyRbacHandler(
			$session,
			$groups,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($session, $groups)
		);

		$this->handler = new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$propertyRbacHandler,
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\TmloService::class),
			$this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
			new ArrayLoader()
		);
	}

	/**
	 * An employee schema whose BSN only `hr` may read and update.
	 */
	private function employeeSchema(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setValue($schema, 301);
		$schema->setSlug('employee');
		$schema->setProperties(
			[
				'firstName' => ['type' => 'string'],
				'bsn' => ['type' => 'string', 'authorization' => ['read' => ['hr'], 'update' => ['hr']]],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}

	private function prepareUpdate(Schema $schema, array $stored, array $data): array {
		$entity = new ObjectEntity();
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setSchema($schema->getId());
		$entity->setRegister(65);
		$entity->setObject($stored);

		$method = new ReflectionMethod(SaveObject::class, 'prepareObjectForUpdate');
		/** @var ObjectEntity $prepared */
		$prepared = $method->invokeArgs($this->handler, [$entity, $schema, $data, [], null, null]);

		return $prepared->getObject();
	}

	/**
	 * A manager edits the first name and saves the body they were shown: the BSN stays.
	 */
	public function testAPropertyTheWriterCannotReadSurvivesAFullSave(): void {
		$schema = $this->employeeSchema();

		$result = $this->prepareUpdate($schema, ['firstName' => 'Ann', 'bsn' => '123456782'], ['firstName' => 'Anna']);

		$this->assertSame('123456782', $result['bsn'], 'A full save must not erase a property the writer was never shown.');
		$this->assertSame('Anna', $result['firstName']);
	}

	/**
	 * A writer who can read the property and leaves it out clears it, as a PUT does.
	 */
	public function testAReaderWhoOmitsThePropertyStillClearsIt(): void {
		$this->writerGroups = ['hr'];
		$schema = $this->employeeSchema();

		$result = $this->prepareUpdate($schema, ['firstName' => 'Ann', 'bsn' => '123456782'], ['firstName' => 'Anna']);

		$this->assertNull($result['bsn']);
	}
}
