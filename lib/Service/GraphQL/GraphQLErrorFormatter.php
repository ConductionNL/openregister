<?php

/**
 * GraphQL Error Formatter
 *
 * Formats GraphQL errors into structured responses with extension codes.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Error\ClientAware;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * Formats GraphQL errors into a structured response with extension codes.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class GraphQLErrorFormatter
{
    /**
     * Format a GraphQL error into a structured response.
     *
     * @param Error $error The GraphQL error
     *
     * @return array<string, mixed> The formatted error
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-misc-annotate/tasks.md#task-8
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function format(Error $error): array
    {
        $formatted = FormattedError::createFromException($error);

        $previous = $error->getPrevious();

        if ($previous instanceof NotAuthorizedException) {
            $formatted['extensions']['code'] = 'FORBIDDEN';
        } else if ($previous instanceof \OCA\OpenRegister\Exception\ValidationException
            || $previous instanceof \OCA\OpenRegister\Exception\CustomValidationException
        ) {
            $formatted['extensions']['code'] = 'VALIDATION_ERROR';
        } else if ($error->getExtensions() !== null && isset($error->getExtensions()['code']) === true) {
            $formatted['extensions']['code'] = $error->getExtensions()['code'];
        } else if ($previous !== null) {
            $formatted['extensions']['code'] = 'INTERNAL_ERROR';
        }

        return $formatted;

    }//end format()

    /**
     * Create a field-level forbidden error.
     *
     * @param string        $field The field name
     * @param array<string> $path  The field path
     *
     * @return Error The GraphQL error
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-37
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-37
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public static function fieldForbidden(string $field, array $path): Error
    {
        return new Error(
            "Not authorized to read field '$field'",
            null,
            null,
            [],
            $path,
            null,
            ['code' => 'FIELD_FORBIDDEN']
        );

    }//end fieldForbidden()

    /**
     * Create a not-found error.
     *
     * @param string $type The object type
     * @param string $id   The object ID
     *
     * @return Error The GraphQL error
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-37
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-37
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public static function notFound(string $type, string $id): Error
    {
        return new Error(
            "$type with ID '$id' not found",
            null,
            null,
            [],
            null,
            null,
            ['code' => 'NOT_FOUND']
        );

    }//end notFound()
}//end class
