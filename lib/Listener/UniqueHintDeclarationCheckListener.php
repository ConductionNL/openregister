<?php
/**
 * Contributes the unique-hint declaration check to the schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Search\Service\SearchAnnotationValidators;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the `x-openregister-unique-hint` check, in the slot it held in the mapper.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
final class UniqueHintDeclarationCheckListener implements IEventListener {

	/**
	 * Add the checks to the schema save.
	 *
	 * @param Event $event The collect event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$checks = [
			SchemaDeclarationOrder::UNIQUE_HINT => fn (Schema $schema) => $this->checkUniqueHint(schema: $schema),
		];
		foreach ($checks as $position => $check) {
			$event->add(check: new PositionedDeclarationCheck(position: $position, check: $check));
		}
	}//end handle()

	/**
	 * Validate the optional `x-openregister-unique-hint` annotation.
	 *
	 * FATAL, unlike {@see AdvisoryAnnotationChecks::checkDedup()}, which degrades
	 * a malformed block to a logged warning. The difference is the cost of being
	 * wrong. A malformed dedup block costs a duplicate sweep you can re-run; a
	 * nomination of a property that does not exist costs a uniqueness alert that
	 * silently never fires, and the only symptom is a second case on the same
	 * KvK number found months later or never. The administrator who typed the
	 * name is the one person who can fix it, and they are still holding the form.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws UniqueHintException When a nomination names a property the schema does not declare.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function checkUniqueHint(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-unique-hint');
		if ($annotation->isWritten() === false) {
			return;
		}

		$errors = SearchAnnotationValidators::uniqueHint()->validate($annotation->shape());
		if (count($errors) > 0) {
			$messages = array_map(static fn (array $err) => $err['message'], $errors);
			throw new UniqueHintException(
				message: 'x-openregister-unique-hint: ' . implode(' ', $messages),
				errors: $errors
			);
		}
	}//end checkUniqueHint()
}//end class
