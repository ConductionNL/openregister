<?php

// SPDX-FileCopyrightText: 2026 Conduction
// SPDX-License-Identifier: EUPL-1.2

require_once __DIR__ . '/vendor/autoload.php';

$config = new Conduction\CodingStandard\Config();
$config->getFinder()->in(__DIR__ . '/src')->in(__DIR__ . '/tests');

return $config;
