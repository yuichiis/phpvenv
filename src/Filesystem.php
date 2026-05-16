<?php

declare(strict_types=1);

namespace Rindow\PhpVenv;

class Filesystem
{
    public function mkdir(string $path, int $mode = 0777, bool $recursive = true): bool
    {
        return is_dir($path) || mkdir($path, $mode, $recursive);
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function put(string $path, string $content): int|false
    {
        return file_put_contents($path, $content);
    }

    public function copy(string $from, string $to): bool
    {
        return copy($from, $to);
    }

    public function chmod(string $path, int $mode): bool
    {
        return chmod($path, $mode);
    }
}