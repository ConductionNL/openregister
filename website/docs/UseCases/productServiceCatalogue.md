---
title: Product and Service Catalogue
sidebar_position: 3
---

import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Product and Service Catalogue

## Project Introduction

The Product and Service Catalogue is a standardized system for managing and sharing information about products and services offered by government agencies and businesses. This implementation guide focuses on European standards and international best practices for product catalogues, with special attention to the needs of public sector organizations.

### European Standards Integration

This project aligns with several key European standards and initiatives:

- **European Core Vocabularies** - Core data models for standardizing fundamental concepts
- **Universal Business Language (UBL)** - XML-based business document standards
- **PEPPOL BIS Catalogue** - Pan-European Public Procurement Online catalogue specifications
- **Common Procurement Vocabulary (CPV)** - EU-wide classification system for public procurement

### Common Ground Integration

The catalogue implementation follows Common Ground principles:

1. **Component-based architecture** - Modular catalogue components
2. **Data at the source** - Single source of truth for product information
3. **Standard APIs** - Standardized interfaces for catalogue access
4. **Open standards** - Adoption of open standards and specifications

## Standards Analysis

### Core Standards and Vocabularies

#### European Core Vocabularies

The Core Public Service Vocabulary (CPSV) provides a foundation for describing public services:

- **Service Description** - Standardized way to describe services including:
  - Name and description
  - Service provider
  - Requirements and conditions
  - Processing time and costs
  - Contact points and channels

- **Service Evidence** - Documentation required for service delivery:
  - Required documents
  - Proof of identity
  - Certifications
  - Supporting materials

#### Schema.org Integration

Schema.org provides widely-adopted vocabularies for products and services:

- **Product Schema** - Detailed product information structure
- **Service Schema** - Service offering descriptions
- **Offer Schema** - Pricing and availability details

### Implementation Approach

#### Data Model Design

The catalogue implements a flexible data model that combines:

1. **Core Vocabularies** - CPSV foundation
2. **Schema.org** - Extended product/service attributes
3. **Custom Extensions** - Organization-specific needs

#### API Design

RESTful APIs following OpenAPI Specification (OAS) 3.0:

1. **Catalogue Management**
   - Create/update products and services
   - Manage categories and classifications
   - Handle versioning and updates

2. **Search and Discovery**
   - Full-text search
   - Faceted navigation
   - Category browsing

3. **Integration Points**
   - PEPPOL catalogue exchange
   - UBL document generation
   - External catalogue synchronization

## Implementation Guide

### Getting Started

1. **Schema Setup**
   - Import core vocabularies
   - Configure Schema.org mappings
   - Define custom extensions

2. **Data Migration**
   - Map existing catalogues
   - Transform to standard format
   - Validate against schemas

3. **API Configuration**
   - Set up endpoints
   - Configure authentication
   - Enable required features

### Best Practices

1. **Data Quality**
   - Mandatory field validation
   - Consistent terminology
   - Regular data audits

2. **Performance**
   - Efficient indexing
   - Caching strategies
   - Load balancing

3. **Security**
   - Access control
   - Data encryption
   - Audit logging

## Use Cases

### Government Services

Example implementation for municipal services:

<Tabs>
<TabItem value='schema' label='Schema Definition'>
{
  'type': 'object',
  'properties': {
    'identifier': {
      'type': 'string',
      'description': 'Unique service identifier'
    },
    'name': {
      'type': 'string',
      'description': 'Service name'
    },
    'description': {
      'type': 'string',
      'description': 'Detailed service description'
    }
  }
}
</TabItem>
</Tabs>

### Commercial Products

Example implementation for product catalogues:

<Tabs>
<TabItem value='schema' label='Schema Definition'>
{
  'type': 'object',
  'properties': {
    'sku': {
      'type': 'string',
      'description': 'Stock keeping unit'
    },
    'name': {
      'type': 'string',
      'description': 'Product name'
    },
    'price': {
      'type': 'number',
      'description': 'Product price'
    }
  }
}
</TabItem>
</Tabs>