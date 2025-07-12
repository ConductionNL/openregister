<?php

function test(string $a, int $b, bool $c = false): void
{
}

// Positional
test('hello', 42, true);

// Named parameters
test(a: 'hello', b: 42, c: true);

// Mixed order named
test(c: true, a: 'hello', b: 42);

// Mixed positional and named
test('hello', b: 42, c: true); 