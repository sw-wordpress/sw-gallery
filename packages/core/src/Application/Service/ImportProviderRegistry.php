<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\ImportProviderRegistryInterface;
use SWGallery\Core\Contract\MediaImportProviderInterface;

final class ImportProviderRegistry implements ImportProviderRegistryInterface
{
    /** Provider ids are lowercase slugs: they appear in routes, option names and logs. */
    public const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /** @var array<string, MediaImportProviderInterface> */
    private array $providers = [];

    public function register(MediaImportProviderInterface $provider): void
    {
        $id = $provider->getProviderId();
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('The import provider id is invalid.');
        }
        if (isset($this->providers[$id])) {
            throw new \InvalidArgumentException('An import provider with this id is already registered.');
        }
        $this->providers[$id] = $provider;
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    public function get(string $providerId): MediaImportProviderInterface
    {
        return $this->providers[$providerId] ?? throw new \OutOfBoundsException('Unknown import provider.');
    }

    public function all(): array
    {
        return array_values($this->providers);
    }
}
