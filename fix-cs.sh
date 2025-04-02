#!/bin/bash

# Check if a specific file is provided
if [ "$1" != "" ]; then
    echo "Fixing PHP CS issues in: $1"
    vendor/bin/php-cs-fixer fix "$1" --config=.php-cs-fixer.dist.php
else
    echo "Fixing PHP CS issues in all PHP files..."
    vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php
fi 