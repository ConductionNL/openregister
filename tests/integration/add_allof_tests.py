#!/usr/bin/env python3
"""Add comprehensive allOf schema composition tests to Newman collection."""

import json

def create_allof_tests():
    """Create comprehensive allOf test suite."""
    return {
        "name": "Schema Composition (allOf) Tests",
        "item": [
            {
                "name": "1. Create Grandparent Schema (Living Thing)",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 201', () => pm.response.to.have.status(201));",
                            "pm.test('Grandparent schema created', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.id).to.exist;",
                            "    pm.expect(json.properties).to.have.property('alive');",
                            "    pm.expect(json.required).to.include('alive');",
                            "    pm.collectionVariables.set('grandparent_schema_id', json.id);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "POST",
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "title": "LivingThing",
                            "description": "Base schema for all living things",
                            "properties": {
                                "alive": {"type": "boolean", "title": "Is Alive"}
                            },
                            "required": ["alive"]
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas"]
                    }
                }
            },
            {
                "name": "2. Create Parent Schema (Person) - Single Inheritance",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 201', () => pm.response.to.have.status(201));",
                            "pm.test('Parent inherits from grandparent', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.allOf).to.include(pm.collectionVariables.get('grandparent_schema_id'));",
                            "    pm.expect(json.properties).to.have.property('name');",
                            "    pm.expect(json.required).to.include('name');",
                            "    pm.collectionVariables.set('parent_schema_id', json.id);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "POST",
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": {
                        "mode": "raw",
                        "raw": "{\n  \"title\": \"Person\",\n  \"description\": \"Person schema inheriting from LivingThing\",\n  \"allOf\": [\"{{grandparent_schema_id}}\"],\n  \"properties\": {\n    \"name\": {\"type\": \"string\", \"title\": \"Name\"}\n  },\n  \"required\": [\"name\"]\n}"
                    },
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas"]
                    }
                }
            },
            {
                "name": "3. Verify Parent Resolution (Multi-Level)",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 200', () => pm.response.to.have.status(200));",
                            "pm.test('Parent resolves grandparent properties', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.properties).to.have.property('alive', 'Inherited from grandparent');",
                            "    pm.expect(json.properties).to.have.property('name');",
                            "    pm.expect(json.required).to.include.members(['alive', 'name']);",
                            "});",
                            "pm.test('Property metadata shows sources', function() {",
                            "    const json = pm.response.json();",
                            "    if (json['@self'] && json['@self'].propertyMetadata) {",
                            "        pm.expect(json['@self'].propertyMetadata.alive.source).to.equal('inherited');",
                            "        pm.expect(json['@self'].propertyMetadata.name.source).to.equal('native');",
                            "    }",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "GET",
                    "header": [],
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas/{{parent_schema_id}}",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas", "{{parent_schema_id}}"]
                    }
                }
            },
            {
                "name": "4. Create Child Schema (Employee) - 3-Level Chain",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 201', () => pm.response.to.have.status(201));",
                            "pm.test('Child inherits from parent', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.allOf).to.include(pm.collectionVariables.get('parent_schema_id'));",
                            "    pm.expect(json.properties).to.have.property('employeeId');",
                            "    pm.collectionVariables.set('child_schema_id', json.id);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "POST",
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": {
                        "mode": "raw",
                        "raw": "{\n  \"title\": \"Employee\",\n  \"description\": \"Employee schema - 3-level inheritance\",\n  \"allOf\": [\"{{parent_schema_id}}\"],\n  \"properties\": {\n    \"employeeId\": {\"type\": \"string\", \"title\": \"Employee ID\"}\n  },\n  \"required\": [\"employeeId\"]\n}"
                    },
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas"]
                    }
                }
            },
            {
                "name": "5. Verify 3-Level Inheritance Chain",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 200', () => pm.response.to.have.status(200));",
                            "pm.test('All properties inherited through chain', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.properties).to.have.property('alive', 'From grandparent');",
                            "    pm.expect(json.properties).to.have.property('name', 'From parent');",
                            "    pm.expect(json.properties).to.have.property('employeeId', 'Native');",
                            "});",
                            "pm.test('All required fields merged', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.required).to.include.members(['alive', 'name', 'employeeId']);",
                            "});",
                            "pm.test('Property metadata distinguishes sources', function() {",
                            "    const json = pm.response.json();",
                            "    if (json['@self'] && json['@self'].propertyMetadata) {",
                            "        const meta = json['@self'].propertyMetadata;",
                            "        pm.expect(meta.employeeId.source).to.equal('native');",
                            "        pm.expect(meta.name.source).to.equal('inherited');",
                            "        pm.expect(meta.alive.source).to.equal('inherited');",
                            "    }",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "GET",
                    "header": [],
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas/{{child_schema_id}}",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas", "{{child_schema_id}}"]
                    }
                }
            },
            {
                "name": "6. Create Schema for Multiple Inheritance",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 201', () => pm.response.to.have.status(201));",
                            "pm.test('Second parent created', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.properties).to.have.property('address');",
                            "    pm.collectionVariables.set('parent2_schema_id', json.id);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "POST",
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "title": "Addressable",
                            "description": "Schema for entities with addresses",
                            "properties": {
                                "address": {"type": "string", "title": "Address"}
                            },
                            "required": ["address"]
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas"]
                    }
                }
            },
            {
                "name": "7. Create Schema with Multiple Parents",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 201', () => pm.response.to.have.status(201));",
                            "pm.test('Multiple inheritance configured', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.allOf).to.be.an('array').with.lengthOf(2);",
                            "    pm.collectionVariables.set('multi_schema_id', json.id);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "POST",
                    "header": [{"key": "Content-Type", "value": "application/json"}],
                    "body": {
                        "mode": "raw",
                        "raw": "{\n  \"title\": \"Customer\",\n  \"description\": \"Customer with multiple parent schemas\",\n  \"allOf\": [\"{{parent_schema_id}}\", \"{{parent2_schema_id}}\"],\n  \"properties\": {\n    \"customerId\": {\"type\": \"string\"}\n  },\n  \"required\": [\"customerId\"]\n}"
                    },
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas"]
                    }
                }
            },
            {
                "name": "8. Verify Multiple Inheritance",
                "event": [{
                    "listen": "test",
                    "script": {
                        "exec": [
                            "pm.test('Status code is 200', () => pm.response.to.have.status(200));",
                            "pm.test('Properties from both parents merged', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.properties).to.have.property('name', 'From parent 1');",
                            "    pm.expect(json.properties).to.have.property('address', 'From parent 2');",
                            "    pm.expect(json.properties).to.have.property('customerId', 'Native');",
                            "});",
                            "pm.test('Required fields from all parents', function() {",
                            "    const json = pm.response.json();",
                            "    pm.expect(json.required).to.include.members(['name', 'address', 'customerId']);",
                            "});"
                        ],
                        "type": "text/javascript"
                    }
                }],
                "request": {
                    "method": "GET",
                    "header": [],
                    "url": {
                        "raw": "{{base_url}}/index.php/apps/openregister/api/schemas/{{multi_schema_id}}",
                        "host": ["{{base_url}}"],
                        "path": ["index.php", "apps", "openregister", "api", "schemas", "{{multi_schema_id}}"]
                    }
                }
            }
        ]
    }

def main():
    """Add allOf tests to Newman collection."""
    collection_path = 'openregister-crud.postman_collection.json'
    
    with open(collection_path, 'r') as f:
        collection = json.load(f)
    
    # Add variables
    new_vars = [
        {"key": "grandparent_schema_id", "value": "", "type": "string"},
        {"key": "parent2_schema_id", "value": "", "type": "string"},
        {"key": "multi_schema_id", "value": "", "type": "string"}
    ]
    
    for var in new_vars:
        if not any(v.get('key') == var['key'] for v in collection['variable']):
            collection['variable'].append(var)
    
    # Remove old Schema Composition Tests if exists
    collection['item'] = [item for item in collection['item'] if item.get('name') != 'Schema Composition Tests']
    
    # Add new comprehensive test suite
    collection['item'].append(create_allof_tests())
    
    with open(collection_path, 'w') as f:
        json.dump(collection, f, indent=2)
    
    print("✅ Added comprehensive allOf tests to Newman collection")
    print(f"   • 8 test requests covering:")
    print(f"     - Single parent inheritance")
    print(f"     - Multi-level inheritance (3 levels)")
    print(f"     - Multiple parents")
    print(f"     - Property source metadata")
    print(f"     - Required field merging")
    print(f"   • Total collection items: {len(collection['item'])}")

if __name__ == '__main__':
    main()
