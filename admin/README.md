# Admin Tools Documentation

AgentHive's admin tools provide a comprehensive interface for managing the system, including module discovery, configuration, and runtime monitoring.

## Overview

The `/admin/` directory contains the web-based administrative interface with several autonomous modules that work together to provide full system control. Admin pages are protected with a bootstrap token flow to avoid fresh-install lockouts.

## Key Features

### Auto-discovery of Modules
The admin system supports auto-discovery of modules through:
- Directory route system (`admin/<module>/index.php`)
- Configuration-driven module loading
- Module-specific route handling

### Module Architecture
Admin modules are organized using the directory route form:
- `/admin/<module>/index.php` (entry point)
- Module-specific UI components
- Shared authentication system

### Security
Admin pages use `lib/auth/auth.php` with:
- Bootstrap token authentication for initial setup
- Session-based authentication for established admins
- Access control based on admin privileges

## Built-in Admin Modules

### 1. Notes System (`/admin/notes/`)
- Human notes with threaded discussions
- AI metadata view
- Bash history tracking
- Search cache management
- Prompts view
- SQLite-backed storage

### 2. CodeWalker (`/admin/codewalker/`)
- AI-powered codebase analysis
- Multiple action types (summarize, rewrite, audit, test, docs, refactor)
- Web dashboard for reviewing results
- CLI and cron integration
- Multi-model support

### 3. AI Header System (`/admin/AI/`)
- AI Header templating system
- Conductor logic for building AI-ready headers
- Model + tools + context coordination
- Performance-ready execution preparation

### 4. System Monitoring (`/admin/system/`)
- Cron job tracking
- Log viewer
- System information display
- Health checks

### 5. Module Management (`/admin/module/`)
- Admin module upload capability
- Auto-discovery of new modules
- Module validation
- Secure module installation

### 6. Configuration Management (`/admin/Settings/`)
- System-wide configuration management
- Module-specific settings
- Environment variable handling
- Runtime configuration adjustments

### 7. API Management (`/admin/API/`)
- API key management
- Route configuration
- Rate limiting settings
- Authentication controls

## Module Directory Structure

The admin system supports several types of modules:

### Core Modules
- `/admin/notes/` - Notes and search system
- `/admin/codewalker/` - AI codebase analysis
- `/admin/AI/` - AI headers and templates
- `/admin/API/` - API management and routing
- `/admin/Settings/` - System configuration

### System Modules
- `/admin/API_Chat/` - Chat API interface
- `/admin/Crontab/` - Cron job management
- `/admin/Logs/` - System logging
- `/admin/sysinfo/` - System information display
- `/admin/Users/` - User account management
- `/admin/INSTALLER/` - System installation tools

### Development Tools
- `/admin/Scripts/` - Script management
- `/admin/HTACCESS/` - .htaccess file management
- `/admin/AI_Jobs/` - AI job processing
- `/admin/AI_System/` - AI system management
- `/admin/AI_MCP/` - AI Management Console

### 5. Universal Data Receiver (`/admin/inbox/`)
- `/v1/inbox` endpoint for auto-creating tables
- Guest mode support
- No-auth ingestion
- Safe table creation

## Module Management

### Adding New Modules
New modules can be added via:
1. Creating a new directory in `/admin/`
2. Adding an `index.php` file
3. The system will automatically make it available

### Module Upload
- Admin interface allows uploading new modules from browser
- Module validation and security checks
- Automatic discovery and integration

## Configuration

Admin settings are managed through:
- Settings database (`/web/private/db/codewalker_settings.db`)
- Runtime configuration
- Environment overrides
- Per-module configuration

## Security Model

### Admin Auth Flow
1. Initial setup uses bootstrap token
2. Subsequent access uses session authentication  
3. Admin pages require valid session
4. Security enforced via `lib/auth/auth.php`

### Access Control
- Admin routes require `auth_require_admin()`
- Session-based authentication
- Permission checking

## Technical Details

### File Dependencies
- `lib/bootstrap.php` - paths, env loader, API guard
- `lib/auth/auth.php` - admin bootstrap-token auth  
- `lib/ratelimit.php` - rate limiting for admin pages

### Module Structure
- Module-specific PHP files in `/admin/<module>/`
- Shared UI components 
- Admin-specific libraries in `/admin/lib/`

## Development Notes

### Adding Admin Pages
1. Create `/admin/new_module/index.php`
2. Implement the page logic
3. Access via `http://localhost/admin/new_module/`

### Admin Authentication
- Uses `auth_require_admin()` function
- Enforces admin session checks
- Handles bootstrap token flow automatically

## Best Practices

### Security
- Ensure write permissions are properly set
- Regular security audits of admin routes
- Monitor for unauthorized module additions

### Performance
- Cache static assets appropriately
- Implement proper session management
- Minimize database queries for admin pages

### Maintainability
- Follow established naming conventions
- Use PHP 7.3+ compatibility standards
- Keep admin modules lightweight and focused

## Troubleshooting

### Common Issues
1. **Authentication failures** - Check `/web/private/bootstrap_admin_token.txt`
2. **Missing modules** - Ensure proper directory structure 
3. **Permission issues** - Verify web server access to `/web/private`