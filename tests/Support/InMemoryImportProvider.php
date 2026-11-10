<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Core\Contract\RemoteStreamInterface;
use SWGallery\Core\Domain\Import\FolderPage;
use SWGallery\Core\Domain\Import\ImportCapabilities;
use SWGallery\Core\Domain\Import\ImportConnection;
use SWGallery\Core\Domain\Import\RemoteMediaObject;
use SWGallery\Core\Domain\Import\RemoteMediaPage;
use SWGallery\Core\Exception\ImportProviderException;

/** Flat in-memory provider (cursor = last returned id) used to verify the provider contract suite itself. */
final class InMemoryImportProvider implements MediaImportProviderInterface
{
    /** @var array<string, string> remote id => bytes, in listing order */
    private array $files = [];

    public function __construct(private readonly string $id = 'memory')
    {
    }

    public function add(string $remoteId, string $bytes): void
    {
        $this->files[$remoteId] = $bytes;
    }

    public function getProviderId(): string
    {
        return $this->id;
    }

    public function getCapabilities(): ImportCapabilities
    {
        return new ImportCapabilities();
    }

    public function listFolders(
        ImportConnection $connection,
        ?string $parentFolderId,
        ?string $cursor,
        int $limit,
    ): FolderPage {
        return new FolderPage([]);
    }

    public function listMedia(
        ImportConnection $connection,
        ?string $folderId,
        ?string $cursor,
        int $limit,
    ): RemoteMediaPage {
        if ($folderId !== null) {
            throw new ImportProviderException('swg_import_folders_unsupported', false);
        }
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $ids = array_map('strval', array_keys($this->files));
        $start = 0;
        if ($cursor !== null) {
            $position = array_search($cursor, $ids, true);
            if ($position === false) {
                throw new ImportProviderException('swg_import_invalid_cursor', false);
            }
            $start = $position + 1;
        }
        $slice = array_slice($ids, $start, $limit);
        $objects = array_map(fn (string $id): RemoteMediaObject => $this->describe($id), $slice);
        $more = $start + $limit < count($ids);

        return new RemoteMediaPage($objects, $more ? $slice[count($slice) - 1] : null);
    }

    public function getMediaMetadata(ImportConnection $connection, string $remoteObjectId): RemoteMediaObject
    {
        return $this->describe($remoteObjectId);
    }

    public function openDownloadStream(ImportConnection $connection, string $remoteObjectId): RemoteStreamInterface
    {
        $this->describe($remoteObjectId);

        return new class ($this->files[$remoteObjectId]) implements RemoteStreamInterface {
            private int $offset = 0;
            private bool $closed = false;

            public function __construct(private readonly string $bytes)
            {
            }

            public function read(int $length): string
            {
                if ($this->closed || $length < 1) {
                    return '';
                }
                $chunk = substr($this->bytes, $this->offset, min($length, 8192));
                $this->offset += strlen($chunk);

                return $chunk;
            }

            public function isEof(): bool
            {
                return $this->closed || $this->offset >= strlen($this->bytes);
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }

    private function describe(string $remoteId): RemoteMediaObject
    {
        if (!isset($this->files[$remoteId])) {
            throw new ImportProviderException('swg_import_not_found', false);
        }

        return new RemoteMediaObject($remoteId, 'file-' . $remoteId, 'image/png', strlen($this->files[$remoteId]));
    }
}
