#!/usr/bin/env python3
"""
Remove the duplicate "0. Create Organization" test from Newman collection.
This test creates a second organization which overrides the test organization
context, causing multitenancy issues.
"""

import json

def main():
    """Main function to remove duplicate org creation."""
    input_file = '/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json'
    
    print("📖 Reading Postman collection...")
    with open(input_file, 'r') as f:
        collection = json.load(f)
    
    # Find and remove "0. Create Organization" test.
    print("🔍 Searching for '0. Create Organization' test...")
    original_count = len(collection['item'])
    
    collection['item'] = [
        item for item in collection['item']
        if item.get('name') != '0. Create Organization'
    ]
    
    new_count = len(collection['item'])
    removed = original_count - new_count
    
    if removed > 0:
        print(f"✅ Removed {removed} test(s) named '0. Create Organization'")
    else:
        print("⚠️  Test '0. Create Organization' not found")
    
    # Write updated collection.
    print("💾 Writing updated collection...")
    with open(input_file, 'w') as f:
        json.dump(collection, f, indent=2)
    
    print("")
    print("✅ Successfully removed duplicate organization creation!")
    print("")
    print("Impact:")
    print("  - Test organization is now used consistently")
    print("  - All resources (registers, schemas, objects) stay in same org")
    print("  - File operations should now work correctly")
    print(f"  - Total tests: {original_count} → {new_count}")
    

if __name__ == '__main__':
    main()

