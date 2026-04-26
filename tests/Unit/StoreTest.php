<?php

declare(strict_types=1);

use Brain\Monkey;
use sqrd\Cache\Store;
use sqrd\Cache\Paths;

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/var/www/html/wp-content');
}

// Each test gets an isolated temp directory as the cache root.
// We override apply_filters('sqrd_cache_root', ...) to return it.

function sqrd_temp_root(): string
{
    return sys_get_temp_dir() . '/sqrd-cache-test-' . getmypid();
}

// ── write_body ────────────────────────────────────────────────────────────────

describe('Store::write_body', function (): void {
    beforeEach(function (): void {
        $this->tmp = sqrd_temp_root();

        Monkey\Functions\when('apply_filters')->alias(function (string $tag, mixed $value): mixed {
            return $tag === 'sqrd_cache_root' ? $this->tmp : $value;
        });
    });

    afterEach(function (): void {
        // Clean up the temp tree.
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    });

    it('writes the body to disk', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_body($path, '<h1>Hello</h1>', false);
        expect(file_get_contents($path))->toBe('<h1>Hello</h1>');
    });

    it('creates intermediate directories', function (): void {
        $path = $this->tmp . '/example.com/blog/post/index.html';
        Store::write_body($path, 'body', false);
        expect(is_file($path))->toBeTrue();
    });

    it('writes a .gz sibling when compress=true', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_body($path, 'compressed body', true);
        expect(is_file($path . '.gz'))->toBeTrue();
        // Verify it is valid gzip.
        $decoded = gzdecode((string) file_get_contents($path . '.gz'));
        expect($decoded)->toBe('compressed body');
    });

    it('does not write .gz when compress=false', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_body($path, 'body', false);
        expect(is_file($path . '.gz'))->toBeFalse();
    });

    it('overwrites an existing file atomically', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_body($path, 'first', false);
        Store::write_body($path, 'second', false);
        expect(file_get_contents($path))->toBe('second');
    });
});

// ── write_headers ─────────────────────────────────────────────────────────────

describe('Store::write_headers', function (): void {
    beforeEach(function (): void {
        $this->tmp = sqrd_temp_root();
        Monkey\Functions\when('apply_filters')->alias(function (string $tag, mixed $value): mixed {
            return $tag === 'sqrd_cache_root' ? $this->tmp : $value;
        });
    });

    afterEach(function (): void {
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    });

    it('writes a .headers sidecar in plaintext format', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_headers($path, [
            ['Content-Type', 'text/html; charset=utf-8'],
            ['Cache-Control', 'public, max-age=3600'],
        ]);
        $sidecar = file_get_contents($path . '.headers');
        expect($sidecar)->toBe(
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Cache-Control: public, max-age=3600\r\n"
        );
    });

    it('writes an empty sidecar for empty pairs', function (): void {
        $path = $this->tmp . '/example.com/index.html';
        Store::write_headers($path, []);
        expect(file_get_contents($path . '.headers'))->toBe('');
    });
});

// ── purge_url ─────────────────────────────────────────────────────────────────

describe('Store::purge_url', function (): void {
    beforeEach(function (): void {
        $this->tmp = sqrd_temp_root();
        Monkey\Functions\when('apply_filters')->alias(function (string $tag, mixed $value): mixed {
            return $tag === 'sqrd_cache_root' ? $this->tmp : $value;
        });
        // Create a full set of cache files to verify purge deletes all variants.
        $dir = $this->tmp . '/example.com/about/';
        mkdir($dir, 0755, true);
        foreach (['index.html', 'index.html.gz', 'index.html.headers', 'index.md', 'index.md.gz', 'index.md.headers'] as $f) {
            file_put_contents($dir . $f, 'data');
        }
    });

    afterEach(function (): void {
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    });

    it('deletes all html variant files', function (): void {
        Store::purge_url('https://example.com/about/');
        expect(is_file($this->tmp . '/example.com/about/index.html'))->toBeFalse();
        expect(is_file($this->tmp . '/example.com/about/index.html.gz'))->toBeFalse();
        expect(is_file($this->tmp . '/example.com/about/index.html.headers'))->toBeFalse();
    });

    it('deletes all md variant files', function (): void {
        Store::purge_url('https://example.com/about/');
        expect(is_file($this->tmp . '/example.com/about/index.md'))->toBeFalse();
        expect(is_file($this->tmp . '/example.com/about/index.md.gz'))->toBeFalse();
        expect(is_file($this->tmp . '/example.com/about/index.md.headers'))->toBeFalse();
    });

    it('does not throw when files do not exist', function (): void {
        expect(fn() => Store::purge_url('https://example.com/nonexistent/'))->not->toThrow(Throwable::class);
    });
});

// ── flush_all ─────────────────────────────────────────────────────────────────

describe('Store::flush_all', function (): void {
    beforeEach(function (): void {
        $this->tmp = sqrd_temp_root();
        Monkey\Functions\when('apply_filters')->alias(function (string $tag, mixed $value): mixed {
            return $tag === 'sqrd_cache_root' ? $this->tmp : $value;
        });
        // Seed some files.
        mkdir($this->tmp . '/example.com/about', 0755, true);
        file_put_contents($this->tmp . '/example.com/about/index.html', 'x');
        file_put_contents($this->tmp . '/example.com/about/index.md', 'x');
    });

    afterEach(function (): void {
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    });

    it('removes all cached files', function (): void {
        Store::flush_all();
        $files = [];
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile()) $files[] = $f->getPathname();
            }
        }
        expect($files)->toBeEmpty();
    });

    it('recreates the cache root directory after flush', function (): void {
        Store::flush_all();
        expect(is_dir($this->tmp))->toBeTrue();
    });
});

// ── stats ─────────────────────────────────────────────────────────────────────

describe('Store::stats', function (): void {
    beforeEach(function (): void {
        $this->tmp = sqrd_temp_root();
        Monkey\Functions\when('apply_filters')->alias(function (string $tag, mixed $value): mixed {
            return $tag === 'sqrd_cache_root' ? $this->tmp : $value;
        });
    });

    afterEach(function (): void {
        if (is_dir($this->tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    });

    it('returns zero counts when cache root does not exist', function (): void {
        $stats = Store::stats();
        expect($stats['files'])->toBe(0);
        expect($stats['bytes'])->toBe(0);
        expect($stats['oldest'])->toBeNull();
        expect($stats['newest'])->toBeNull();
    });

    it('counts files and bytes', function (): void {
        mkdir($this->tmp, 0755, true);
        file_put_contents($this->tmp . '/a.html', str_repeat('x', 100));
        file_put_contents($this->tmp . '/b.md', str_repeat('y', 200));
        $stats = Store::stats();
        expect($stats['files'])->toBe(2);
        expect($stats['bytes'])->toBe(300);
    });

    it('tracks oldest and newest mtime', function (): void {
        mkdir($this->tmp, 0755, true);
        file_put_contents($this->tmp . '/a.html', 'a');
        sleep(1);
        file_put_contents($this->tmp . '/b.html', 'b');
        $stats = Store::stats();
        expect($stats['oldest'])->toBeLessThan($stats['newest']);
    });
});
