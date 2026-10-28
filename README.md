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

### Run a command in the environment

Use the generated `run` wrapper to execute any command with the virtual environment configured. Its `bin`/`Scripts` directory and Composer's `vendor/bin` directory are prepended to `PATH` for the command.

```bash
run phpunit -c tests --filter SOMETHING
```

### Custom environment variables and PATH

Add a shell-specific file to the environment's `bin` (Unix) or `Scripts` (Windows) directory. The file is loaded by activation, `run`, and the PHP wrapper:

| Shell | File |
| --- | --- |
| Bash/Zsh | `bin/custom-env.sh` |
| Command Prompt | `Scripts/custom-env.bat` |
| PowerShell | `Scripts/custom-env.ps1` |

For example, `Scripts/custom-env.bat` can contain:

```bat
set "MY_APP_MODE=development"
set "PATH=C:\my-tools;%PATH%"
```

The corresponding PowerShell file uses `$env:MY_APP_MODE = "development"` and `$env:PATH = "C:\my-tools;" + $env:PATH`. Bash/Zsh uses `export MY_APP_MODE=development` and `export PATH="/my-tools:$PATH"`.

The PHP wrapper detects when the current shell already loaded the custom file through activation or `run`, so it does not apply PATH additions twice.

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

