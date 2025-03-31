---
title: Register
sidebar_position: 1
description: An overview of how core concepts in Open Register interact with each other.
keywords:
  - Open Register
  - Core Concepts
  - Relationships
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Registers

## What is a Register?

In Open Register, a **Register** is a specialized container that holds objects conforming to specific schemas. You can think of a register as:

- A **collection** of related data objects
- A **domain-specific database** focused on a particular subject area
- A **logical grouping** of information with common validation rules
- A **database** for structured data storage
- A **object store** for unstructured data
- A **data lake** for large-scale data storage and analysis

Registers provide a way to organize data by purpose, domain, or function, making it easier to manage and retrieve related information.

## Register Structure

<ApiSchema id="open-register" example   pointer="#/components/schemas/Register" />

## Register Use Cases

Registers can be used for various purposes:

### 1. Master Data Management

Create registers for core business entities like customers, products, or locations to ensure a single source of truth.

### 2. Domain-Specific Data Collections

Organize data by business domains such as:
- HR Register (employees, departments, positions)
- Financial Register (accounts, transactions, budgets)
- Product Register (products, categories, specifications)

### 3. Integration Hubs

Use registers as integration points between different systems, providing a standardized way to exchange data.

## Working with Registers
## Introduction

Registers in Open Register can be maintained through both the user interface (UI) and the API. This flexibility allows users to manage registers in a way that best suits their workflow and technical capabilities. 

For detailed information on the API endpoints and how to use them, please refer to the [API documentation for registers](https://openregisters.app/api#tag/Registers).


### Creating a Register

To create a new register, you need to define its basic properties and specify which schemas it will support:

```json
POST /api/registers
{
  "title": "Customer Register",
  "description": "Central repository for customer information",
  "schemas": ["customer", "address", "preference"],
  "source": "primary-source",
  "databaseId": "customer-db"
}
```

### Retrieving Register Information

You can retrieve information about a specific register:

```
GET /api/registers/{id}
```

Or list all available registers:

```
GET /api/registers
```

### Updating a Register

Registers can be updated to add or remove supported schemas or change other properties:

```json
PUT /api/registers/{id}
{
  "title": "Customer Register",
  "description": "Updated repository for customer information",
  "schemas": ["customer", "address", "preference", "communication-history"],
  "source": "primary-source",
  "databaseId": "customer-db"
}
```

### Deleting a Register

Registers can be deleted when no longer needed:

```
DELETE /api/registers/{id}
```

## Best Practices

1. **Logical Grouping**: Create registers around logical domains or business functions
2. **Clear Naming**: Use clear, descriptive names for registers
3. **Documentation**: Provide detailed descriptions of each register's purpose
4. **Schema Selection**: Carefully select which schemas belong in each register
5. **Access Control**: Define appropriate access controls for each register

## Relationship to Other Concepts

- **Schemas**: Registers specify which schemas they support, defining what types of objects can be stored
- **Objects**: Objects are stored within registers and must conform to one of the register's supported schemas
- **Sources**: Registers use sources to determine where and how their data is stored

## Conclusion

Registers are a fundamental organizing principle in Open Register, providing structure and context for your data. By grouping related schemas and objects into registers, you create a more manageable and intuitive data architecture that aligns with your business domains. 