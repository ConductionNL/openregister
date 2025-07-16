<?php
/**
 * Test file for demonstrating Named Parameters in PHP 8.0+
 */

/**
 * Example function with multiple parameters
 *
 * @param string $name The person's name
 * @param int    $age  The person's age
 * @param string $city The person's city
 * @param bool   $isActive Whether the person is active
 *
 * @return string Formatted message
 */
function createUser(string $name, int $age, string $city, bool $isActive = true): string
{
    return "User: $name, Age: $age, City: $city, Active: " . ($isActive ? 'Yes' : 'No');
}

// Traditional positional arguments
$user1 = createUser('John Doe', 25, 'New York', true);

// Named parameters - same order
$user2 = createUser(
    name: 'Jane Smith',
    age: 30,
    city: 'London',
    isActive: false
);

// Named parameters - different order
$user3 = createUser(
    city: 'Paris',
    name: 'Pierre Dubois',
    isActive: true,
    age: 35
);

// Mixed: positional then named
$user4 = createUser('Alice Brown', 28, city: 'Berlin');

// Skip optional parameter with named arguments
$user5 = createUser(
    name: 'Bob Wilson',
    age: 40,
    city: 'Sydney'
    // isActive defaults to true
);

echo $user1 . "\n";
echo $user2 . "\n";
echo $user3 . "\n";
echo $user4 . "\n";
echo $user5 . "\n"; 