<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

interface ImportProviderRegistryInterface
{
    /** @throws \InvalidArgumentException When the provider id is already registered. */
    public function register(MediaImportProviderInterface $provider): void;

    public function has(string $providerId): bool;

    /** @throws \OutOfBoundsException When the provider is unknown. */
    public function get(string $providerId): MediaImportProviderInterface;

    /** @return list<MediaImportProviderInterface> In registration order. */
    public function all(): array;
}
