<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * An appdata folder in memory. Like Nextcloud's, every write gives a file a
 * new etag, and a file handle taken before a delete is left dangling: what is
 * in the folder is only what $files holds now.
 */
final class FakeSimpleFolder implements ISimpleFolder
{
    /** @var array<string, string> name => content */
    public array $files = [];

    /** @var array<string, int> name => etag counter */
    public array $etags = [];

    /** Make every write fail, as a full disk or quota would. */
    public bool $failWrites = false;

    private int $writes = 0;

    public function write(string $name, string $content): void
    {
        if ($this->failWrites) {
            throw new \RuntimeException('disk full');
        }
        $this->files[$name] = $content;
        $this->etags[$name] = ++$this->writes;
    }

    public function getDirectoryListing(): array
    {
        return array_map(fn(string $name) => $this->handle($name), array_keys($this->files));
    }

    public function fileExists(string $name): bool
    {
        return isset($this->files[$name]);
    }

    public function getFile(string $name): ISimpleFile
    {
        if (!isset($this->files[$name])) {
            throw new NotFoundException($name);
        }
        return $this->handle($name);
    }

    public function newFile(string $name, $content = null): ISimpleFile
    {
        $this->write($name, (string) $content);
        return $this->handle($name);
    }

    public function delete(): void
    {
        $this->files = [];
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function getFolder(string $name): ISimpleFolder
    {
        throw new NotFoundException($name);
    }

    public function newFolder(string $path): ISimpleFolder
    {
        return new self();
    }

    private function handle(string $name): ISimpleFile
    {
        $folder = $this;
        return new class ($folder, $name) implements ISimpleFile {
            public function __construct(private FakeSimpleFolder $folder, private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getSize(): int|float
            {
                return strlen($this->folder->files[$this->name] ?? '');
            }

            public function getETag(): string
            {
                if (!isset($this->folder->files[$this->name])) {
                    throw new NotFoundException($this->name);
                }
                return 'etag-' . $this->folder->etags[$this->name];
            }

            public function getMTime(): int
            {
                // Whole seconds, and the same second throughout: two writes
                // inside one second are only told apart by their etag.
                return 1_790_000_000;
            }

            public function getContent(): string
            {
                return $this->folder->files[$this->name] ?? throw new NotFoundException($this->name);
            }

            public function putContent($data): void
            {
                $this->folder->write($this->name, (string) $data);
            }

            public function delete(): void
            {
                unset($this->folder->files[$this->name], $this->folder->etags[$this->name]);
            }

            public function getMimeType(): string
            {
                return 'image/jpeg';
            }

            public function getExtension(): string
            {
                return pathinfo($this->name, PATHINFO_EXTENSION);
            }

            public function read()
            {
                return false;
            }

            public function write()
            {
                return false;
            }
        };
    }
}
