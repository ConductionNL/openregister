#!/usr/bin/env python3
"""
Add organization-aware setup to Newman test collection.

This script adds:
1. Create test organization
2. Set organization as active
3. Variable to clear list
"""

import json
import sys

def create_org_test():
    """Create the 'Create Test Organization' request."""
    return {
        "name": "Setup: Create Test Organization",
        "event": [
            {
                "listen": "test",
                "script": {
                    "exec": [
                        "pm.test('Status code is 201', function () {",
                        "    pm.response.to.have.status(201);",
                        "});",
                        "",
                        "if (pm.response.code === 201) {",
                        "    var jsonData = pm.response.json();",
                        "    pm.test('Organization created', function () {",
                        "        pm.expect(jsonData).to.have.property('organisation');",
                        "        pm.expect(jsonData.organisation).to.have.property('uuid');",
                        "    });",
                        "    ",
                        "    // Store org UUID for use in all tests.",
                        "    pm.collectionVariables.set('test_org_uuid', jsonData.organisation.uuid);",
                        "    console.log('✅ Test Organization Created:', jsonData.organisation.uuid);",
                        "}"
                    ],
                    "type": "text/javascript"
                }
            }
        ],
        "request": {
            "method": "POST",
            "header": [
                {
                    "key": "Content-Type",
                    "value": "application/json"
                }
            ],
            "body": {
                "mode": "raw",
                "raw": json.dumps({
                    "name": "Newman Test Organization",
                    "description": "Organization for automated integration tests"
                }, indent=2)
            },
            "url": {
                "raw": "{{base_url}}/index.php/apps/openregister/api/organisations",
                "host": ["{{base_url}}"],
                "path": [
                    "index.php",
                    "apps",
                    "openregister",
                    "api",
                    "organisations"
                ]
            }
        },
        "response": []
    }


def create_set_active_test():
    """Create the 'Set Test Organization Active' request."""
    return {
        "name": "Setup: Set Test Organization Active",
        "event": [
            {
                "listen": "test",
                "script": {
                    "exec": [
                        "pm.test('Status code is 200', function () {",
                        "    pm.response.to.have.status(200);",
                        "});",
                        "",
                        "if (pm.response.code === 200) {",
                        "    var jsonData = pm.response.json();",
                        "    pm.test('Organization set as active', function () {",
                        "        pm.expect(jsonData).to.have.property('message');",
                        "        pm.expect(jsonData.message).to.include('Active organisation set');",
                        "    });",
                        "    console.log('✅ Test Organization is now ACTIVE');",
                        "    console.log('🎯 All subsequent tests will run in this organization context');",
                        "}"
                    ],
                    "type": "text/javascript"
                }
            }
        ],
        "request": {
            "method": "POST",
            "header": [],
            "url": {
                "raw": "{{base_url}}/index.php/apps/openregister/api/organisations/{{test_org_uuid}}/set-active",
                "host": ["{{base_url}}"],
                "path": [
                    "index.php",
                    "apps",
                    "openregister",
                    "api",
                    "organisations",
                    "{{test_org_uuid}}",
                    "set-active"
                ]
            }
        },
        "response": []
    }


def main():
    """Main function to add organization setup."""
    input_file = '/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json'
    
    print("📖 Reading Postman collection...")
    with open(input_file, 'r') as f:
        collection = json.load(f)
    
    # Add test_org_uuid to variables list.
    print("📝 Adding test_org_uuid variable...")
    collection['variable'].append({
        "key": "test_org_uuid",
        "value": "",
        "type": "string"
    })
    
    # Add test_org_uuid to clear list in pre-request script.
    print("📝 Adding test_org_uuid to clear list...")
    prereq_script = collection['event'][0]['script']['exec']
    for i, line in enumerate(prereq_script):
        if "'test_timestamp'" in line:
            # Add test_org_uuid before test_timestamp.
            prereq_script[i] = line.replace("'test_timestamp'", "'test_org_uuid', 'test_timestamp'")
            break
    
    # Insert organization setup tests after RBAC setup (after 2nd test).
    print("📝 Inserting organization setup tests...")
    org_create_test = create_org_test()
    org_active_test = create_set_active_test()
    
    # Insert after "Setup: Verify RBAC Status" (index 1).
    collection['item'].insert(2, org_create_test)
    collection['item'].insert(3, org_active_test)
    
    # Write updated collection.
    print("💾 Writing updated collection...")
    with open(input_file, 'w') as f:
        json.dump(collection, f, indent=2)
    
    print("✅ Successfully added organization-aware setup!")
    print("")
    print("Changes made:")
    print("1. ✅ Added test_org_uuid variable")
    print("2. ✅ Added test_org_uuid to clear list")
    print("3. ✅ Added 'Setup: Create Test Organization' test")
    print("4. ✅ Added 'Setup: Set Test Organization Active' test")
    print("")
    print("Test order now:")
    print("  1. Setup: Enable RBAC")
    print("  2. Setup: Verify RBAC Status")
    print("  3. Setup: Create Test Organization (NEW)")
    print("  4. Setup: Set Test Organization Active (NEW)")
    print("  5. ... rest of tests ...")
    

if __name__ == '__main__':
    main()

