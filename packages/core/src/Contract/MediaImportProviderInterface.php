<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Import\FolderPage;
use SWGallery\Core\Domain\Import\ImportCapabilities;
use SWGallery\Core\Domain\Import\ImportConnection;
use SWGallery\Core\Domain\Import\RemoteMediaObject;
use SWGallery\Core\Domain\Import\RemoteMediaPage;
use SWGallery\Core\Exception\ImportProviderException;

/**
 * Provider-neutral import contract (Media Library, later Google Drive, Dropbox, ...). No provider SDK objects,
 * no credentials in signatures. Imported files become ordinary WordPress attachments.
 *
 * @throws ImportProviderException On any provider failure (flagged transient or permanent).
 */
interface MediaImportProviderInterface
{
    public const MAX_PAGE_SIZE = 100;

    public function getProviderId(): string;

    public function getCapabilities(): ImportCapabilities;

    /** @param int<1, 100> $limit */
    public function listFolders(
        ImportConnection $connection,
        ?string $parentFolderId,
        ?string $cursor,
        int $limit,
    ): FolderPage;

    /** @param int<1, 100> $limit */
    public function listMedia(
        ImportConnection $connection,
        ?string $folderId,
        ?string $cursor,
        int $limit,
    ): RemoteMediaPage;

    public function getMediaMetadata(ImportConnection $connection, string $remoteObjectId): RemoteMediaObject;

    public function openDownloadStream(ImportConnection $connection, string $remoteObjectId): RemoteStreamInterface;
}
