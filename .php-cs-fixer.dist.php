<?php

// SPDX-FileCopyrightText: 2026 Conduction
// SPDX-License-Identifier: EUPL-1.2

// The require_once is NOT optional. php-cs-fixer includes this file before the
// app's autoloader runs, so without it the run dies with "Class not found" —
// and in --format=json that fatal is reported as ZERO FILES NEEDING CHANGES,
// which reads exactly like a clean tree.
require_once __DIR__ . '/vendor/autoload.php';

$config = new Conduction\CodingStandard\Config();
$config->getFinder()
    ->notPath('vendor')
    ->notPath('node_modules')
    ->notPath('build')
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests');

return $config;
