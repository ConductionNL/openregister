#!/usr/bin/env python3
"""
Add organization cleanup to Newman test collection.
"""

import json

def create_org_cleanup_test():
    """Create the 'Cleanup: Delete Test Organization' request."""
    return {
        "name": "Cleanup: Delete Test Organization",
        "event": [
            {
                "listen": "test",
                "script": {
                    "exec": [
                        "pm.test('Status code is 200, 204, or 404 (org may be already deleted)', function () {",
                        "    pm.expect(pm.response.code).to.be.oneOf([200, 204, 404]);",
                        "});",
                        "",
                        "console.log('✅ Test Organization cleanup complete');",
                        "console.log('📊 All tests finished!');"
                    ],
                    "type": "text/javascript"
                }
            }
        ],
        "request": {
            "method": "DELETE",
            "header": [],
            "url": {
                "raw": "{{base_url}}/index.php/apps/openregister/api/organisations/{{test_org_uuid}}",
                "host": ["{{base_url}}"],
                "path": [
                    "index.php",
                    "apps",
                    "openregister",
                    "api",
                    "organisations",
                    "{{test_org_uuid}}"
                ]
            }
        },
        "response": []
    }


def main():
    """Main function to add organization cleanup."""
    input_file = '/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json'
    
    print("📖 Reading Postman collection...")
    with open(input_file, 'r') as f:
        collection = json.load(f)
    
    # Add cleanup test at the very end.
    print("📝 Adding organization cleanup test at the end...")
    org_cleanup_test = create_org_cleanup_test()
    collection['item'].append(org_cleanup_test)
    
    # Write updated collection.
    print("💾 Writing updated collection...")
    with open(input_file, 'w') as f:
        json.dump(collection, f, indent=2)
    
    print("✅ Successfully added organization cleanup!")
    print("")
    print("Changes made:")
    print("1. ✅ Added 'Cleanup: Delete Test Organization' as final test")
    print("")
    print("Test order now ends with:")
    print("  ... all tests ...")
    print("  23. Delete Schema")
    print("  24. Delete Register")
    print("  25. Cleanup: Delete Test Organization (NEW)")
    

if __name__ == '__main__':
    main()

