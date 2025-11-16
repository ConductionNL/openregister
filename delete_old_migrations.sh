#!/bin/bash

# Script to delete old migrations after consolidating them
# The new consolidated migration is: Version1Date20251117000000.php

cd "$(dirname "$0")/lib/Migration"

echo "🗑️  Deleting old migration files..."
echo ""
echo "Keeping:"
echo "  ✅ Version1Date20251117000000.php (NEW CONSOLIDATED MIGRATION)"
echo ""
echo "Deleting all other migrations..."

# Count files before
total=$(ls -1 Version*.php 2>/dev/null | wc -l)
echo "Total migration files: $total"

# Delete all migrations EXCEPT the new consolidated one
find . -maxdepth 1 -name 'Version*.php' ! -name 'Version1Date20251117000000.php' -type f -delete

# Count files after
remaining=$(ls -1 Version*.php 2>/dev/null | wc -l)
deleted=$((total - remaining))

echo ""
echo "✅ Deleted $deleted old migration files"
echo "✅ Remaining: $remaining migration file (the consolidated one)"
echo ""
echo "📝 Note: Old migrations are preserved in git history"

