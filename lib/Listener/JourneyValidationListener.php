<?php

/**
 * JourneyValidationListener: refuse a journey object its author may not save.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Form\JourneyAuthoringValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * On ObjectCreatingEvent and ObjectUpdatingEvent: a `journey` in the `forms` register is judged before it is written.
 *
 * Access, steps, next rules and each write's mapping are checked by
 * JourneyAuthoringValidator; a finding stops the write with the findings
 * attached. An object whose schema or register cannot be read is refused
 * rather than written unchecked.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */
class JourneyValidationListener implements IEventListener {
	use ValidatesPreWriteObject;

	/**
	 * The register journeys live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'forms';

	/**
	 * The journey schema's slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'journey';

	/**
	 * The refusal code.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'journey-invalid';

	/**
	 * Wire the listener.
	 *
	 * @param SchemaMapperInterface     $schemas   Reads the written object's schema slug.
	 * @param RegisterMapperInterface   $registers Reads the written object's register slug.
	 * @param JourneyAuthoringValidator $validator Judges the journey.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly RegisterMapperInterface $registers,
		private readonly JourneyAuthoringValidator $validator,
	) {
	}//end __construct()

	/**
	 * Judge a journey before it is written; leave every other object alone.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param ObjectEntity                            $object The object about to be written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	protected function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		$schema = $this->slugOf(
			find: fn (string $ref) => $this->schemas->find($ref, _rbac: false, _multitenancy: false),
			reference: (string)$object->getSchema()
		);
		if ($schema !== null && $schema !== self::SCHEMA) {
			return;
		}

		$register = $this->slugOf(
			find: fn (string $ref) => $this->registers->find($ref, _rbac: false, _multitenancy: false),
			reference: (string)$object->getRegister()
		);
		if ($schema !== null && $register !== null && $register !== self::REGISTER) {
			return;
		}

		if ($schema === null || $register === null) {
			$this->refuse(
				event: $event,
				message: 'The object could not be checked against the journey rules, so it was not saved: its schema or register could not be read.',
				findings: []
			);
			return;
		}

		$findings = $this->validator->validate(body: $this->writtenData(object: $object));
		if ($findings === []) {
			return;
		}

		$this->refuse(
			event: $event,
			message: 'The journey cannot be saved: ' . implode('; ', array_column($findings, 'message')),
			findings: $findings
		);
	}//end validate()

	/**
	 * Stop the write with the refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-write event.
	 * @param string                                  $message  What is wrong.
	 * @param list<array<string, mixed>>              $findings The findings.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message, array $findings): void {
		$event->setErrors(['code' => self::ERROR_CODE, 'message' => $message, 'findings' => $findings]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * A schema's or register's slug: '' when absent or unknown, null when it cannot be read.
	 *
	 * @param callable $find      Finds the entity by reference.
	 * @param string   $reference The id, uuid or slug.
	 *
	 * @return string|null The slug.
	 */
	private function slugOf(callable $find, string $reference): ?string {
		if ($reference === '') {
			return '';
		}

		try {
			return (string)$find($reference)->getSlug();
		} catch (DoesNotExistException) {
			return '';
		} catch (Throwable) {
			return null;
		}
	}//end slugOf()
}//end class
