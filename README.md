# phpvenv

A lightweight tool to create isolated PHP virtual environments, inspired by Python's `venv`. 

It allows you to create a local environment with its own configuration (`php.ini`) and local Composer home, without affecting the system-wide PHP installation.

## Features

- Isolated `php.ini` and configuration directory (`conf.d`).
- Environment-specific `COMPOSER_HOME`.
- Support for Bash, Zsh, Command Prompt (CMD), and PowerShell.
- Automatic wrapper scripts to ensure the correct configuration is used.
- Supports multiple installed PHP versions. The virtual environment automatically uses the same PHP interpreter that was used when running `phpvenv`.

## Installation

You can install this tool globally using Composer:

```bash
composer global require rindow/phpvenv
```

Ensure your global composer bin directory is in your `PATH`.

## Usage

### 1. Create a new environment

```bash
phpvenv myenv
```

### 2. Activate the environment

**On Unix (Bash/Zsh):**
```bash
source myenv/bin/activate
```

**On Windows (Command Prompt):**
```cmd
myenv\Scripts\activate.bat
```

**On Windows (PowerShell):**
```powershell
. \myenv\Scripts\Activate.ps1
```

### 3. Deactivate

Simply run:
```bash
deactivate
```

### 4. Multiple PHP Versions

If your system has multiple PHP versions installed, `phpvenv` can create environments tied to a specific PHP interpreter.

The generated virtual environment stores the path to the PHP executable used during creation (PHP_BINARY) and always uses that interpreter inside the environment.

**Example:**

```bash
php8.2 /installed/dir/phpvenv env82
php8.4 /installed/dir/phpvenv env84
```

**After activation:**
```bash
source env82/bin/activate
php -v
# PHP 8.2.x

source env84/bin/activate
php -v
# PHP 8.4.x
```

## Note

The environment does not bundle PHP itself.
Instead, it creates wrapper scripts that forward execution to the PHP binary used at environment creation time.

## License
[BSD-3-Clause](https://opensource.org/licenses/BSD-3-Clause)

