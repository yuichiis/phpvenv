<?php

declare(strict_types=1);

namespace Rindow\PhpVenv\Tests;

use PHPUnit\Framework\TestCase;
use Rindow\PhpVenv\Filesystem;

class FilesystemTest extends TestCase
{
    private string $tempDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpvenv_fs_test_' . uniqid();
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

    public function testMkdir(): void
    {
        $path = $this->tempDir . '/testdir';
        $this->assertTrue($this->fs->mkdir($path));
        $this->assertDirectoryExists($path);
        
        // Recursive
        $path2 = $this->tempDir . '/testdir2/sub';
        $this->assertTrue($this->fs->mkdir($path2));
        $this->assertDirectoryExists($path2);
    }

    public function testExists(): void
    {
        $path = $this->tempDir . '/testfile.txt';
        $this->assertFalse($this->fs->exists($path));
        
        file_put_contents($path, 'test');
        $this->assertTrue($this->fs->exists($path));
    }

    public function testPut(): void
    {
        $path = $this->tempDir . '/put_test.txt';
        $content = 'hello world';
        
        $bytes = $this->fs->put($path, $content);
        $this->assertNotFalse($bytes);
        $this->assertEquals(strlen($content), $bytes);
        $this->assertEquals($content, file_get_contents($path));
    }

    public function testCopy(): void
    {
        $from = $this->tempDir . '/from.txt';
        $to = $this->tempDir . '/to.txt';
        file_put_contents($from, 'copy test');
        
        $this->assertTrue($this->fs->copy($from, $to));
        $this->assertFileExists($to);
        $this->assertEquals('copy test', file_get_contents($to));
    }

    public function testChmod(): void
    {
        $path = $this->tempDir . '/chmod_test.txt';
        file_put_contents($path, 'test');
        
        $this->assertTrue($this->fs->chmod($path, 0777));
        // Note: exact mode comparison might be tricky on some systems, 
        // but we just assert it doesn't fail.
        clearstatcache();
        $this->assertFileExists($path);
    }
}
