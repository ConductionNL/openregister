<?php

/**
 * Recorded messages for ValidateObjectErrorMessageTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'required one' => 'The required property (title) is missing. Please provide a value for this property or set it to null if allowed.',
  'required many' => 'The required properties (title, status, owner) are missing. Please provide values for these properties.',
  'required none' => 'Required property is missing',
  'required not a list' => 'Required property is missing',
  'required no args' => 'Required property is missing',
  'type' => 'Property \'a.b\' should be type \'string\' but is \'integer\'. Please provide a value of the correct type.',
  'type at root' => 'Property \'root\' should be type \'object\' but is \'string\'. Please provide a value of the correct type.',
  'type list' => 'Property \'p\' should be type \'string or null\' but is \'number\'. Please provide a value of the correct type.',
  'type no expected' => 'Property \'p\' should be type \'unknown\' but is \'boolean\'. Please provide a value of the correct type.',
  'type empty object' => 'Property \'p\' expects object but got empty ({}). For non-required object properties, set this to null to clear the field. For required object properties, provide a valid object with the necessary properties.',
  'type empty array' => 'Property \'p\' expects non-empty array but got empty array ([]). This likely has a minItems constraint. Please provide at least one item.',
  'type empty string' => 'Property \'p\' expects non-empty string but got empty string. For non-required string properties, set this to null to clear the field. For required string properties, provide a valid string value.',
  'type object for array' => 'Property \'p\' should be type \'array\' but is \'object\'. Please provide a value of the correct type.',
  'type filled list for object' => 'Property \'p\' should be type \'object\' but is \'array\'. Please provide a value of the correct type.',
  'type null' => 'Property \'0.p\' should be type \'integer\' but is \'null\'. Please provide a value of the correct type.',
  'minItems' => 'Property \'tags\' requires at least 2 items, has 1. Please add more items to the array or set to null if the property is not required.',
  'minItems not a list' => 'Property \'tags\' requires at least 0 items, has 0. Please add more items to the array or set to null if the property is not required.',
  'maxItems' => 'Property \'tags\' allows at most 2 items, has 3. Please remove some items from the array.',
  'maxItems not a list' => 'Property \'tags\' allows at most 0 items, has 0. Please remove some items from the array.',
  'format' => 'Property \'mail\' should match format \'email\' but \'nope\' does not. Please provide a value in the correct format.',
  'format no args' => 'Property \'mail\' should match format \'unknown\' but \'nope\' does not. Please provide a value in the correct format.',
  'minLength empty' => 'Property \'t\' requires at least 3 characters, but is empty. Please provide a non-empty string value.',
  'minLength short' => 'Property \'t\' requires at least 3 chars, has 2. Please provide a longer string value.',
  'minLength not a string' => 'Property \'t\' requires at least 0 characters, but is empty. Please provide a non-empty string value.',
  'maxLength' => 'Property \'t\' allows at most 3 chars, has 6. Please provide a shorter string value.',
  'maxLength not a string' => 'Property \'t\' allows at most 0 chars, has 0. Please provide a shorter string value.',
  'minimum' => 'Property \'n\' should be at least 5, but is 1. Please provide a larger number.',
  'minimum no args' => 'Property \'n\' should be at least 0, but is -1. Please provide a larger number.',
  'maximum' => 'Property \'n\' should be at most 5, but is 9.5. Please provide a smaller number.',
  'maximum no args' => 'Property \'n\' should be at most 0, but is 9. Please provide a smaller number.',
  'enum from args' => 'Property \'s\' should be one of: \'a\', \'b\', but is \'x\'. Please choose one of the allowed values.',
  'enum from schema' => 'Property \'s\' should be one of: \'open\', \'closed\', but is \'sent\'. Please choose one of the allowed values.',
  'enum schema without enum' => 'Property \'s\' has an invalid value \'sent\'. Please provide one of the allowed values.',
  'enum schema enum not a list' => 'Property \'s\' has an invalid value \'sent\'. Please provide one of the allowed values.',
  'enum schema not an object' => 'Property \'s\' has an invalid value \'sent\'. Please provide one of the allowed values.',
  'enum args not a list' => 'Property \'s\' has an invalid value \'sent\'. Please provide one of the allowed values.',
  'pattern' => 'Property \'c\' should match pattern \'^[a-z]+$\' but \'x1\' does not. Please provide a value that matches the required pattern.',
  'pattern no args' => 'Property \'c\' should match pattern \'unknown\' but \'x1\' does not. Please provide a value that matches the required pattern.',
  'unknown keyword' => 'Property \'n\' failed validation for rule \'multipleOf\'. Please check the property value and schema requirements.',
  'unknown keyword at root' => 'Property \'root\' failed validation for rule \'const\'. Please check the property value and schema requirements.',
  'sub-error used' => 'Property \'a.b\' should be type \'string\' but is \'integer\'. Please provide a value of the correct type.',
  'nested sub-errors' => 'Property \'x\' should be at least 1, but is 0. Please provide a larger number.',
  'opis required' => 'The required property (a) is missing. Please provide a value for this property or set it to null if allowed.',
  'opis nested type' => 'Property \'a.b\' should be type \'integer\' but is \'string\'. Please provide a value of the correct type.',
  'opis enum' => 'Property \'s\' should be one of: \'open\', \'closed\', but is \'sent\'. Please choose one of the allowed values.',
  'opis format' => 'Property \'d\' should match format \'date\' but \'yesterday\' does not. Please provide a value in the correct format.',
  'opis items' => 'Property \'0\' should be type \'string\' but is \'integer\'. Please provide a value of the correct type.',
  'opis min items' => 'Property \'root\' requires at least 2 items, has 1. Please add more items to the array or set to null if the property is not required.',
  'opis one of' => 'Property \'root\' should be type \'string\' but is \'number\'. Please provide a value of the correct type.',
  'opis multiple of' => 'Property \'root\' failed validation for rule \'multipleOf\'. Please check the property value and schema requirements.',
  'opis valid' => 'Validation passed',
);
