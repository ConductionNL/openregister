<?php

/**
 * ConfigurationDraftMapper query behaviour tests.
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

use DateTime;
use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Drafts are addressed by set, layer, layer ref and key.
 */
class ConfigurationDraftMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var ConfigurationDraftMapper
	 */
	private ConfigurationDraftMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ConfigurationDraftMapper($this->recordingDb());
	}//end setUp()

	/**
	 * findBySet() lists a set's drafts in insertion order.
	 *
	 * @return void
	 */
	public function testFindBySetListsInIdOrder(): void {
		$this->results[] = [['id' => 1, 'config_key' => 'a'], ['id' => 2, 'config_key' => 'b']];

		$drafts = $this->mapper->findBySet('set-1');

		$this->assertSame(['a', 'b'], array_map(static fn (ConfigurationDraft $d): ?string => $d->getConfigKey(), $drafts));
		$this->assertSame(
			'select(*) from(openregister_config_drafts) where(eq(set_uuid, :p0)) orderBy(id, ASC) executeQuery()',
			$this->sql()
		);
	}//end testFindBySetListsInIdOrder()

	/**
	 * A null layer ref matches drafts without one.
	 *
	 * @return void
	 */
	public function testFindAtAddressWithoutLayerRefMatchesNull(): void {
		$this->results[] = [['id' => 3, 'layer' => 'app']];

		$draft = $this->mapper->findAtAddress('set-1', 'app', null, 'theme');

		$this->assertSame(3, $draft?->getId());
		$this->assertSame(
			'select(*) from(openregister_config_drafts) where(eq(set_uuid, :p0)) andWhere(eq(layer, :p1)) andWhere(eq(config_key, :p2)) setMaxResults(1) andWhere(isNull(layer_ref)) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['set-1', 'app', 'theme'], $this->boundValues());
	}//end testFindAtAddressWithoutLayerRefMatchesNull()

	/**
	 * A layer ref is bound, and no match answers null.
	 *
	 * @return void
	 */
	public function testFindAtAddressWithLayerRefBindsIt(): void {
		$this->assertNull($this->mapper->findAtAddress('set-1', 'register', 'reg-7', 'title'));
		$this->assertStringContainsString('andWhere(eq(layer_ref, :p3))', $this->sql());
		$this->assertStringNotContainsString('isNull(layer_ref)', $this->sql());
		$this->assertSame('reg-7', $this->bound(':p3'));
	}//end testFindAtAddressWithLayerRefBindsIt()

	/**
	 * findAt() reads one address object and builds the same query.
	 *
	 * @return void
	 */
	public function testFindAtTakesTheAddressObject(): void {
		$this->results[] = [['id' => 4, 'layer' => 'register']];

		$draft = $this->mapper->findAt('set-1', new ConfigurationAddress('register', 'reg-7', 'title'));

		$this->assertSame(4, $draft?->getId());
		$this->assertSame(
			'select(*) from(openregister_config_drafts) where(eq(set_uuid, :p0)) andWhere(eq(layer, :p1)) andWhere(eq(config_key, :p2)) setMaxResults(1) andWhere(eq(layer_ref, :p3)) executeQuery()',
			$this->sql()
		);
		$this->assertSame('reg-7', $this->bound(':p3'));
	}//end testFindAtTakesTheAddressObject()

	/**
	 * findByUuid() throws when the draft is gone.
	 *
	 * @return void
	 */
	public function testFindByUuidThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);
		$this->mapper->findByUuid('d-gone');
	}//end testFindByUuidThrowsWhenMissing()

	/**
	 * findByUuid() returns the draft by uuid.
	 *
	 * @return void
	 */
	public function testFindByUuidReturnsTheDraft(): void {
		$this->results[] = [['id' => 6, 'uuid' => 'd-1']];

		$this->assertSame('d-1', $this->mapper->findByUuid('d-1')->getUuid());
		$this->assertSame('select(*) from(openregister_config_drafts) where(eq(uuid, :p0)) executeQuery()', $this->sql());
	}//end testFindByUuidReturnsTheDraft()

	/**
	 * createFromArray() mints uuid and stamps; a supplied uuid and created survive.
	 *
	 * @return void
	 */
	public function testCreateFromArrayStampsOnlyWhatIsMissing(): void {
		$created = new DateTime('2026-02-02T00:00:00+00:00');

		$minted = $this->mapper->createFromArray(['setUuid' => 'set-1', 'configKey' => 'k']);
		$kept   = $this->mapper->createFromArray(['uuid' => 'd-kept', 'created' => $created]);

		$this->assertSame(36, strlen((string) $minted->getUuid()));
		$this->assertSame($minted->getCreated(), $minted->getUpdated());
		$this->assertSame(42, $minted->getId());
		$this->assertStringStartsWith('insert(openregister_config_drafts)', $this->sql(0));
		$this->assertSame('d-kept', $kept->getUuid());
		$this->assertSame($created, $kept->getCreated());
		$this->assertNotSame($created, $kept->getUpdated());
	}//end testCreateFromArrayStampsOnlyWhatIsMissing()

	/**
	 * save() restamps updated and updates by id.
	 *
	 * @return void
	 */
	public function testSaveRestampsUpdated(): void {
		$old   = new DateTime('2020-01-01T00:00:00+00:00');
		$draft = new ConfigurationDraft();
		$draft->setId(11);
		$draft->setUpdated($old);
		$draft->resetUpdatedFields();

		$saved = $this->mapper->save($draft);

		$this->assertNotSame($old, $saved->getUpdated());
		$this->assertStringStartsWith('update(openregister_config_drafts) set(updated, :p0) where(eq(id, :p1))', $this->sql());
		$this->assertSame(11, $this->bound(':p1'));
	}//end testSaveRestampsUpdated()
}//end class
