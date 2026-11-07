<?php

/**
 * NotificationAnnotationCheck: the save-time check of `x-openregister-notifications`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationContentValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use Psr\Log\LoggerInterface;

/**
 * Warns about a faulty notification annotation and refuses an unlinkable url action.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationAnnotationCheck {

	/**
	 * Constructor
	 *
	 * @param LoggerInterface $logger Receives the warning.
	 *
	 * @return void
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * Validate the optional `x-openregister-notifications` annotation.
	 *
	 * A malformed notification annotation must NOT block the schema itself and,
	 * on config import, the entire register and every schema that references it
	 * (the import aborts and 0 objects land). The runtime dispatcher only fires
	 * notifications whose trigger matches a known event, so an invalid or legacy
	 * spec (e.g. the older `onCreate`/`onStatusChange` shape without a
	 * trigger.type) is simply inert.
	 *
	 * One fault is refused: a `url` action the dispatcher would drop. Such a
	 * button is never inert by accident, and its author would not learn why it
	 * is missing. A configuration import uses {@see warn()} instead.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws NotificationDeclarationException When a url action cannot be linked to.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function check(Schema $schema): void {
		$errors = $this->errors(schema: $schema);
		$this->refuseUnlinkableActions(errors: $errors);
		$this->warnAbout(schema: $schema, errors: $errors);
	}//end check()

	/**
	 * Validate the annotation as {@see check()} does, with an unlinkable url action as a warning too.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function warn(Schema $schema): void {
		$this->warnAbout(schema: $schema, errors: $this->errors(schema: $schema));
	}//end warn()

	/**
	 * The validation errors of the annotation, none when it is absent.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function errors(Schema $schema): array {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-notifications');
		if ($annotation->isBlock() === false) {
			return [];
		}

		return (new NotificationAnnotationValidator())->validate($annotation->shape());
	}//end errors()

	/**
	 * Log the errors as one warning, if there are any.
	 *
	 * @param Schema                                           $schema The schema.
	 * @param array<int, array{code: string, message: string}> $errors The validation errors.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function warnAbout(Schema $schema, array $errors): void {
		if (count($errors) > 0) {
			$this->logger->warning(
				'Schema "' . $schema->getSlug() . '" has invalid x-openregister-notifications (ignored at runtime, fix to enable): '
				. implode(' ', array_map(static fn (array $err) => $err['message'], $errors)),
				['schema' => $schema->getSlug()]
			);
		}
	}//end warnAbout()

	/**
	 * Refuse when one of the errors is a url action that cannot be linked to.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The validation errors.
	 *
	 * @return void
	 *
	 * @throws NotificationDeclarationException When a url action cannot be linked to.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function refuseUnlinkableActions(array $errors): void {
		$refused = array_filter(
			$errors,
			static fn (array $error): bool => $error['code'] === NotificationContentValidator::BAD_HREF
		);
		if ($refused !== []) {
			throw new NotificationDeclarationException(errors: array_values($refused));
		}
	}//end refuseUnlinkableActions()
}//end class
