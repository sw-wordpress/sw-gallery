<?php

/**
 * PENDING: move to tests/wp once the WordPress integration suite (stream B) is merged; adjust the namespace and
 * bootstrap to the suite's conventions. Not run by any suite yet. The contract class is a plain PHPUnit TestCase,
 * so fixtures are created and removed by hand (no WP_UnitTestCase transaction rollback).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\Tests\Wp;

use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Core\Exception\ImportProviderException;
use SWGallery\Tests\Support\MediaImportProviderContract;
use SWGallery\WordPress\Import\MediaLibraryProvider;

final class MediaLibraryProviderTest extends MediaImportProviderContract
{
    /** @var array<int, string> attachment id => bytes */
    private array $bytes = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->purge();
        wp_set_current_user($this->createUser('administrator'));
    }

    protected function tearDown(): void
    {
        $this->purge();
        wp_set_current_user(0);
        parent::tearDown();
    }

    protected function createProvider(): MediaImportProviderInterface
    {
        return new MediaLibraryProvider();
    }

    protected function seed(int $count): array
    {
        $ids = array();
        for ($i = 1; $i <= $count; ++$i) {
            $ids[] = (string) $this->createAttachment('image/png', 'img' . $i . '.png', str_repeat('p', $i));
        }
        return $ids;
    }

    protected function unknownRemoteId(): string
    {
        return '99999999';
    }

    protected function expectedBytes(string $remoteId): string
    {
        return $this->bytes[ (int) $remoteId ];
    }

    public function testNonImageAttachmentsAreNotListedAndNotFound(): void
    {
        $this->seed(1);
        $pdf = $this->createAttachment('application/pdf', 'doc.pdf', '%PDF');
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $ids = array_map(static fn ($o) => $o->remoteObjectId, $provider->listMedia($connection, null, null, 100)->objects);
        self::assertNotContains((string) $pdf, $ids);
        $this->expectPermanent(fn () => $provider->getMediaMetadata($connection, (string) $pdf), 'swg_import_not_found');
    }

    public function testMalformedIdsAndTraversalAttemptsAreRejected(): void
    {
        $provider = $this->createProvider();
        $connection = $this->connection($provider);
        foreach (array( '', '0', '-1', '1.5', '../../wp-config.php', '1; DROP', "1\0", str_repeat('9', 40) ) as $bad) {
            $this->expectPermanent(fn () => $provider->openDownloadStream($connection, $bad), 'swg_import_not_found');
        }
    }

    public function testFileOutsideUploadsIsRefusedWithoutLeakingPaths(): void
    {
        $id   = $this->createAttachment('image/png', 'x.png', 'data');
        $evil = tempnam(sys_get_temp_dir(), 'swg');
        self::assertIsString($evil);
        file_put_contents($evil, 'secret');
        update_attached_file($id, $evil);
        $provider = $this->createProvider();
        try {
            $provider->openDownloadStream($this->connection($provider), (string) $id);
            self::fail('Expected an ImportProviderException.');
        } catch (ImportProviderException $e) {
            self::assertSame('swg_import_file_unavailable', $e->errorCode());
            self::assertFalse($e->isTransient());
            self::assertStringNotContainsString($evil, $e->getMessage());
            self::assertStringNotContainsString(dirname($evil), $e->errorCode());
        } finally {
            unlink($evil);
        }
    }

    public function testDotDotInAttachedPathIsRefused(): void
    {
        $id = $this->createAttachment('image/png', 'y.png', 'data');
        $uploads = wp_upload_dir(null, false);
        update_attached_file($id, $uploads['basedir'] . '/../wp-config.php');
        $provider = $this->createProvider();
        $this->expectPermanent(fn () => $provider->openDownloadStream($this->connection($provider), (string) $id), 'swg_import_file_unavailable');
    }

    public function testUserWithoutUploadCapabilityCannotList(): void
    {
        $this->seed(1);
        wp_set_current_user($this->createUser('subscriber'));
        $provider = $this->createProvider();
        $this->expectPermanent(fn () => $provider->listMedia($this->connection($provider), null, null, 10), 'swg_import_forbidden');
    }

    public function testAuthorSeesOnlyOwnUploadsAndCannotReadOthers(): void
    {
        $other = $this->seed(1)[0];
        $author = $this->createUser('author');
        wp_set_current_user($author);
        $own = (string) $this->createAttachment('image/png', 'own.png', 'mine');
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $ids = array_map(static fn ($o) => $o->remoteObjectId, $provider->listMedia($connection, null, null, 100)->objects);
        self::assertSame(array( $own ), $ids);
        $this->expectPermanent(fn () => $provider->getMediaMetadata($connection, $other), 'swg_import_not_found');
    }

    public function testFolderScopedListingAndForeignConnectionAreRejected(): void
    {
        $provider = $this->createProvider();
        $this->expectPermanent(fn () => $provider->listMedia($this->connection($provider), '5', null, 10), 'swg_import_folders_unsupported');
        $foreign = new \SWGallery\Core\Domain\Import\ImportConnection(1, 'other', 'a', 's');
        $this->expectPermanent(fn () => $provider->listMedia($foreign, null, null, 10), 'swg_import_wrong_provider');
        self::assertFalse($provider->getCapabilities()->browseFolders);
    }

    public function testImportResolvesToExistingAttachmentWithoutTouchingFiles(): void
    {
        $id = $this->createAttachment('image/png', 'keep.png', 'keep');
        $file = (string) get_attached_file($id);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);
        $meta = $provider->getMediaMetadata($connection, (string) $id);
        $stream = $provider->openDownloadStream($connection, (string) $id);
        $stream->close();

        self::assertSame((string) $id, $meta->remoteObjectId);
        self::assertFileExists($file);
        self::assertSame('keep', file_get_contents($file));
        self::assertSame(1, count(get_posts(array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ))));
    }

    private function createUser(string $role): int
    {
        $id = wp_insert_user(
            array(
                'user_login' => 'swg_' . wp_generate_password(8, false),
                'user_pass'  => wp_generate_password(),
                'role'       => $role,
            )
        );
        self::assertIsInt($id);
        return $id;
    }

    private function createAttachment(string $mime, string $name, string $content): int
    {
        $uploads = wp_upload_dir(null, true);
        $path    = $uploads['path'] . '/' . wp_unique_filename($uploads['path'], $name);
        file_put_contents($path, $content);
        $id = wp_insert_attachment(
            array(
                'post_mime_type' => $mime,
                'post_title'     => $name,
                'post_status'    => 'inherit',
            ),
            $path
        );
        self::assertIsInt($id);
        $this->bytes[ $id ] = $content;
        return $id;
    }

    private function purge(): void
    {
        $ids = get_posts(array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ));
        foreach ($ids as $id) {
            wp_delete_attachment((int) $id, true);
        }
        $this->bytes = array();
    }

    /** @param callable(): mixed $call */
    private function expectPermanent(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected an ImportProviderException.');
        } catch (ImportProviderException $e) {
            self::assertSame($code, $e->errorCode());
            self::assertFalse($e->isTransient());
        }
    }
}
