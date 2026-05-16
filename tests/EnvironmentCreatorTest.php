<?php

declare(strict_types=1);

namespace Rindow\PhpVenv\Tests;

use PHPUnit\Framework\TestCase;
use Rindow\PhpVenv\EnvironmentCreator;
use Rindow\PhpVenv\Filesystem;

class EnvironmentCreatorTest extends TestCase
{
    private string $tempDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpvenv_env_test_' . uniqid();
        mkdir($this->tempDir);
        $this->fs = new Filesystem();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testCreateThrowsIfDirectoryExists(): void
    {
        $envName = 'myenv';
        $envDir = $this->tempDir . DIRECTORY_SEPARATOR . $envName;
        mkdir($envDir);

        $creator = new EnvironmentCreator($this->fs);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Directory already exists: {$envDir}");
        
        $creator->create($envName, $this->tempDir);
    }

    public function testCreateUnix(): void
    {
        $creator = new EnvironmentCreator($this->fs, false);
        $envName = 'unixenv';
        
        ob_start();
        $creator->create($envName, $this->tempDir);
        $output = ob_get_clean();

        $envDir = $this->tempDir . DIRECTORY_SEPARATOR . $envName;
        
        $this->assertDirectoryExists($envDir);
        $this->assertDirectoryExists($envDir . '/bin');
        $this->assertDirectoryExists($envDir . '/cli/conf.d');
        
        // Scripts
        $this->assertFileExists($envDir . '/bin/activate');
        $this->assertFileExists($envDir . '/bin/php');
        
        $this->assertStringContainsString('Activate:', $output);
    }

    public function testCreateWindows(): void
    {
        $creator = new EnvironmentCreator($this->fs, true);
        $envName = 'winenv';
        
        ob_start();
        $creator->create($envName, $this->tempDir);
        $output = ob_get_clean();

        $envDir = $this->tempDir . DIRECTORY_SEPARATOR . $envName;
        
        $this->assertDirectoryExists($envDir);
        $this->assertDirectoryExists($envDir . DIRECTORY_SEPARATOR . 'Scripts');
        $this->assertDirectoryExists($envDir . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'conf.d');
        
        // Scripts
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'activate.bat');
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'deactivate.bat');
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'php.bat');
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'Activate.ps1');
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'php.ps1');
        
        // Bash should also be generated for mingw/wsl on windows
        $this->assertFileExists($envDir . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'activate');
        
        $this->assertStringContainsString('Activate:', $output);
    }
}
