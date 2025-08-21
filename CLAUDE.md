# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

This is a WordPress plugin that automatically posts to Twitter/X when WordPress articles are published. The plugin uses OAuth 1.0a authentication and Twitter API v2 to provide secure, reliable auto-posting functionality with link card support.

## Development Commands

### Code Quality & Standards
```bash
# Install PHP dependencies and development tools
composer install

# Check code against WordPress Coding Standards
composer run lint

# Auto-fix coding standards violations
composer run fix

# Direct PHPCS commands
./vendor/bin/phpcs --standard=phpcs.xml .
./vendor/bin/phpcbf --standard=phpcs.xml .
```

### WordPress Plugin Testing
The plugin provides built-in testing functionality through its admin interface:
- **Connection Test**: Verify OAuth 1.0a authentication with Twitter API
- **Manual Post Test**: Send test posts to validate functionality
- **Debug Logging**: WordPress error logs capture API interactions

## Architecture Overview

### Three-Class Architecture
The plugin follows Single Responsibility Principle with three main classes:

#### `Twitter_OAuth_Signature`
- **Purpose**: OAuth 1.0a signature generation and validation
- **Key Methods**: 
  - `generate()` - Creates HMAC-SHA1 signatures using RFC3986 encoding
  - `generate_nonce()` - Generates unique OAuth nonces
  - `build_auth_header()` - Constructs Authorization headers
- **Critical**: Uses `PHP_QUERY_RFC3986` flag for proper parameter encoding

#### `Twitter_API_Client`
- **Purpose**: Twitter API v2 communication layer
- **Endpoint**: `https://api.twitter.com/2/`
- **Authentication**: OAuth 1.0a with dependency-injected signature class
- **Error Handling**: Comprehensive HTTP error code processing and API error message extraction

#### `TwitterAutoPost` (Main Class)
- **Purpose**: WordPress integration and plugin lifecycle management
- **WordPress Hooks**: 
  - `publish_post` for automatic posting
  - `admin_menu` and `admin_init` for settings UI
  - AJAX handlers for testing functionality
- **Features**: Duplicate post prevention, customizable post formats, 280-character limit handling

### Key Design Patterns
- **Dependency Injection**: API Client is injected into main class via `get_api_client()`
- **WordPress Settings API**: Standard WordPress options management
- **Filter Hooks**: `twitter_auto_post_content` and `twitter_auto_post_should_post` for customization
- **Meta Field Tracking**: `_twitter_posted` prevents duplicate posting

## Authentication Requirements

### Twitter Developer Setup
- Twitter Developer Account required for API access
- App permissions must be set to "Read and write"
- OAuth 1.0a credentials needed:
  - API Key (Consumer Key)
  - API Key Secret (Consumer Secret) 
  - Access Token
  - Access Token Secret

### Configuration Location
WordPress admin: Settings → Twitter/X Auto Post

## API Integration Details

### Twitter API v2 Endpoints
- **Authentication Test**: `users/me` (GET)
- **Tweet Creation**: `tweets` (POST)

### OAuth 1.0a Implementation
- **Signature Method**: HMAC-SHA1
- **Parameter Encoding**: RFC3986 compliant
- **Base String Construction**: Method + URL + sorted parameters
- **Critical Fix**: Uses `http_build_query()` with `PHP_QUERY_RFC3986` flag

## WordPress Standards Compliance

### Coding Standards (phpcs.xml)
- WordPress-Core, WordPress-Docs, WordPress-Extra standards
- Text domain: `twitter-auto-post`
- PHP 7.4+ compatibility
- Custom exclusions for development-friendly rules (debug functions, missing DocBlocks)

### Security Practices
- Input sanitization with `esc_attr()`, `esc_textarea()`
- AJAX nonce verification
- Capability checks (`manage_options`)
- Direct access prevention (`ABSPATH` checks)

## Plugin Features

### Post Format Customization
- Placeholder system: `{title}`, `{url}`, `{excerpt}`
- Multi-line textarea support with preserved formatting
- 280-character automatic truncation

### Error Handling & Debugging
- WordPress error logging integration
- HTTP error code interpretation
- User-friendly error messages in admin interface
- API response validation and error extraction