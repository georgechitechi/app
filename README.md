# CodeIgniter 3 to 4 Upgrader

This package helps you upgrade your CodeIgniter 3 projects to CodeIgniter 4. It handles the migration of your project structure, namespaces, and various CodeIgniter-specific components.

## Installation

```bash
composer require georgechitechi/upgrader
```

## Usage

```bash
vendor/bin/ci-upgrade /path/to/your/ci3/project
```

## What Gets Upgraded

The upgrader handles the following aspects of the migration:

1. Project Structure
   - Downloads latest CI4 installation
   - Creates proper directory structure
   - Creates a backup of your original project

2. Controllers
   - Adds namespaces
   - Updates class extensions
   - Updates method visibility
   - Converts CI3 syntax to CI4

3. Models
   - Adds namespaces
   - Updates class extensions
   - Updates method visibility
   - Adds CI4 model properties

4. Views
   - Migrates to the new location
   - Updates echo syntax
   - Updates form helper syntax
   - Updates URL helper syntax

5. Configuration
   - Converts config arrays to classes
   - Updates config file structure
   - Migrates database configuration
   - Sets up environment file

6. Routes
   - Updates routing syntax
   - Migrates to the new routing system

7. Helpers and Libraries
   - Adds namespaces
   - Updates file locations
   - Updates syntax to CI4 standards

8. Composer Configuration
   - Creates/updates composer.json
   - Sets up autoloading
   - Preserves existing dependencies

## Important Notes

1. Always backup your project before running the upgrader (the tool creates a backup automatically)
2. Review the upgraded code manually to ensure everything works as expected
3. Some manual adjustments might be needed after the upgrade
4. Test your application thoroughly after the upgrade

## Manual Steps After Upgrade

1. Review and update any third-party libraries
2. Update any custom helpers or libraries that might need adjustments
3. Test all forms and file uploads
4. Update any custom database queries
5. Review and update any session handling
6. Test all AJAX calls and responses

## Known Limitations

- Custom libraries might need manual adjustment
- Complex routing configurations might need review
- Database queries might need updates for compatibility
- Session handling might need manual updates
- Custom hooks will need manual migration

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Author

George Chitechi <georgechitechi@gmail.com>

## License

MIT License 