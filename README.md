<p align="center">
  <img src="public/logo.svg" width="120" alt="Slenix Logo">
</p>

<h1 align="center">Slenix Framework</h1>

<p align="center">
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.1%2B-blue?style=flat-square" alt="PHP Version"></a>
  <a href="#"><img src="https://img.shields.io/badge/Version-2.6-green?style=flat-square" alt="Version"></a>
  <a href="#"><img src="https://img.shields.io/badge/License-MIT-orange?style=flat-square" alt="License"></a>
</p>

---

## Overview

Slenix is a lightweight, high-performance, and expressive web application framework for PHP. Built on a clean Model-View-Controller (MVC) architecture, it eliminates unnecessary abstraction layers to provide a predictable, explicit development environment with zero hidden magic.

---

## Core Capabilities

| Subsystem | Components & Specifications |
| :--- | :--- |
| **HTTP & Routing** | Dynamic routing engine, lifecycle middleware pipelines, isolated request/response abstractions, and automated error handling. |
| **Database Architecture** | Fluent Query Builder, Object-Relational Mapping (ORM) supporting advanced associations (`HasManyThrough`, `MorphMany`), database blueprint migrations, and model seeding. |
| **Security Architecture** | Native CSRF token verification, encrypted sessions, programmatic Rate Limiting, token-based JWT authentication, and customizable Auth Guards. |
| **Asynchronous & Real-time**| Built-in asynchronous multi-connection **WebSocket Server** and a managed **Queue Processing Subsystem** with background worker daemons. |
| **System Ecosystem** | **Celestial CLI** development utility, **Luna Template Engine**, driver-based Caching, transactional Mail layouts, and system Logging logs. |

---

## Directory Structure

The repository enforces a strict decoupling between application boundaries and core framework primitives:

```text
.
├── celestial               # Framework Command-Line Interface binary
├── public/                 # Document root (Application entry point & static assets)
├── routes/                 # HTTP routing declarations
├── src/                    # Framework Core Source
│   ├── Config/             # Environment configuration mapping
│   ├── Core/               # Kernel, Environment loaders, CLI handlers, and WebSocket layers
│   ├── Database/           # Migrations, Seeds, ORM relationships, and Query engines
│   ├── Http/               # Routing, Request/Response structures, and Middleware implementations
│   └── Supports/           # Authentication, Token encryption, Cache, Queues, Luna Engine, and Validation
└── views/                  # UI presentation components (.luna.php templates)

```

---

## Technical Prerequisites

To deploy or integrate Slenix, the target runtime environment must meet the following baseline requirements:

* **PHP Runtime**: Version 8.1 or higher
* **Core Extensions**: `curl` (active client capability), `PDO` (with database-specific drivers enabled), and `json` compilation support.

---

## Documentation

The comprehensive architectural reference guides, detailed implementation API specifications, and configuration details are hosted at:

👉 **[Official Technical Documentation](https://slenix.vercel.app)**

---

## Security Vulnerabilities

To report a security vulnerability or exploit within this system, please utilize the private vulnerability reporting mechanism via GitHub Issues or contact the maintainers directly. All security inquiries are treated with high priority.

---

## License

The Slenix Framework is open-source software distributed under the terms of the **MIT License**.