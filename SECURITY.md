# Security Policy

## Reporting a Vulnerability

If you've found a security issue in Hey Woo, please **do not** open a public GitHub issue.

Instead, report it privately so we can address it before disclosure:

- Email: **security@automattic.com**
- Or use Automattic's coordinated disclosure form: <https://hackerone.com/automattic>

Please include:

- A description of the issue and its potential impact
- Steps to reproduce
- The plugin version (`Hey Woo` plugin header → `Version`)
- The WordPress and WooCommerce versions
- Any relevant configuration details

We aim to acknowledge reports within 5 working days and to provide a fix or mitigation plan within a reasonable window depending on severity.

## Scope

In scope:
- Vulnerabilities in the Hey Woo plugin source code (including the analytics abilities, knowledge providers, scoring engine, and REST/MCP surfaces)
- Issues in the documented MCP integration with WooCommerce core

Out of scope:
- Vulnerabilities in WooCommerce core itself (report those to <https://hackerone.com/automattic>)
- Vulnerabilities in third-party MCP clients (Claude Desktop, etc.)
- Issues that require an attacker to already have admin access to the WordPress site

## Supported Versions

Security fixes are provided for the most recent minor release. Older versions may receive fixes at our discretion depending on the severity of the issue.
