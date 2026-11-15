<?php

/**
 * OpenRegister SchemaMapperServices
 *
 * What a schema save runs through: the event dispatcher, the property validator, the action checks and the logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Rules\RelativeTimeEvaluation;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * What a schema save runs through: the event dispatcher, the property validator, the action checks and the logger.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final readonly class SchemaMapperServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IEventDispatcher                            $eventDispatcher Event dispatcher for schema events.
	 * @param PropertyValidatorHandlerInterface           $validator       Schema property validator for validation.
	 * @param LoggerInterface                             $logger          Structured logger (surfaces unknown annotation keys).
	 * @param ObjectActionValidatorInterface|Closure|null $objectActions   Verifies declared actions; a Closure defers it to first use.
	 * @param RelativeTimeEvaluation|Closure|null         $relativeTime    Resolves the calendar a `$age` condition counts in;
	 *                                                                     a Closure defers it to first use.
	 */
	public function __construct(
		public IEventDispatcher $eventDispatcher,
		public PropertyValidatorHandlerInterface $validator,
		public LoggerInterface $logger,
		public ObjectActionValidatorInterface|Closure|null $objectActions = null,
		public RelativeTimeEvaluation|Closure|null $relativeTime = null,
	) {
	}//end __construct()
}//end class
