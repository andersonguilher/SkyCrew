# SkyCrew CMS - Full Version

A complete Crew Management System with Authentication, Pilot Dashboard, and Admin Panel.

## Setup Instructions

1. **Database Update**:
   - IMPORTANT: You must update your database to support the new features.
   - Run the script `database_full.sql` in PHPMyAdmin (it will drop/recreate tables or add new ones).
   - Database Name: `virtual_airline_cms`

2. **Credentials**:
   - **Admin Access**:
     - URL: `http://localhost/escalas de voo/` (Redirects to login)
     - Email: `admin@skycrew.com`
     - Password: `123456`
   - **Pilot Access**:
     - Email: `shepard@skycrew.com`
     - Password: `123456`

## Features Added
- **Authentication**: Secure Login/Logout with sessions.
- **Admin Area**: 
  - Dashboard with system stats.
  - Manage Pilots (Create/List).
- **Pilot Area**:
  - Personal Dashboard.
  - PBS Auto-scheduler (Algorithm updated).
  - Preferences Manager (Edit availability).
  
## Directory Structure
- `/admin` - Administration pages.
- `/pilot` - Pilot visible pages.
- `/includes` - Shared logic (Auth, Scheduler).
- `login.php` - Unified entry point.
