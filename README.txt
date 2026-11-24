FLOODWATCH - PHP / MYSQL STARTER PROJECT

REQUIREMENTS
- Windows with XAMPP
- Apache and MySQL enabled
- PHP 7.4+ (PHP 8+ recommended)

INSTALLATION
1. Extract the flood_watch folder.
2. Copy the folder into C:\xampp\htdocs\
3. Open XAMPP Control Panel.
4. Start Apache and MySQL.
5. Visit http://localhost/flood_watch/

DATABASE
The project automatically creates:
- Database: flood_watch_db
- Table: users

DEFAULT ADMIN ACCOUNT
Username: admin
Password: Admin@12345

Change the default administrator password before deploying publicly.
The sign-up form only creates resident accounts. Administrator accounts cannot be selected during public registration.

NOTES
- The background image and Google Font currently load from the internet.
- For the database auto-creation feature, the configured MySQL account needs permission to create databases.
- This is a starter login/registration project. The dashboard is a placeholder, not a complete flood-monitoring system.
- Before public deployment, use secure database credentials, HTTPS, login rate limiting, and a proper admin password-change workflow.
