<?php

/**
 * CalendarLinkMapper and ConfigurationDraftSetMapper write tests.
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
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use PHPUnit\Framework\TestCase;

/**
 * The event unlink is scoped to one object and event; a set save restamps updated.
 */
class CalendarLinkAndDraftSetWritesTest extends TestCase {
	use RecordsQueries;

	/**
	 * deleteByObjectAndEvent() deletes only that object's link to that event.
	 *
	 * @return void
	 */
	public function testDeleteByObjectAndEventIsScoped(): void {
		$this->affected = 1;

		$deleted = (new CalendarLinkMapper($this->recordingDb()))->deleteByObjectAndEvent('obj-1', 'evt-9');

		$this->assertSame(1, $deleted);
		$this->assertSame(
			'delete(openregister_calendar_links) where(eq(object_uuid, :p0)) andWhere(eq(event_uid, :p1)) executeStatement()',
			$this->sql()
		);
		$this->assertSame(['obj-1', 'evt-9'], $this->boundValues());
	}//end testDeleteByObjectAndEventIsScoped()

	/**
	 * A delete that matched nothing reports zero.
	 *
	 * @return void
	 */
	public function testDeleteByObjectAndEventWithoutMatchIsZero(): void {
		$this->affected = 0;

		$this->assertSame(0, (new CalendarLinkMapper($this->recordingDb()))->deleteByObjectAndEvent('obj-1', 'evt-none'));
	}//end testDeleteByObjectAndEventWithoutMatchIsZero()

	/**
	 * save() of a draft set restamps updated and writes by id.
	 *
	 * @return void
	 */
	public function testDraftSetSaveRestampsUpdated(): void {
		$old = new DateTime('2020-01-01T00:00:00+00:00');
		$set = new ConfigurationDraftSet();
		$set->setId(4);
		$set->setUpdated($old);
		$set->resetUpdatedFields();

		$saved = (new ConfigurationDraftSetMapper($this->recordingDb()))->save($set);

		$this->assertNotSame($old, $saved->getUpdated());
		$this->assertStringStartsWith('update(openregister_config_sets) set(updated, :p0) where(eq(id, :p1))', $this->sql());
		$this->assertSame(4, $this->bound(':p1'));
	}//end testDraftSetSaveRestampsUpdated()
}//end class
