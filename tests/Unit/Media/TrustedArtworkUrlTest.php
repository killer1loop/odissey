<?php

namespace Tests\Unit\Media;

use App\Services\Media\TrustedArtworkUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedArtworkUrlTest extends TestCase
{
    #[DataProvider('trustedUrls')]
    public function test_trusted_artwork_urls_are_normalized(
        string $input,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            (new TrustedArtworkUrl)->normalize($input),
        );
    }

    public static function trustedUrls(): array
    {
        return [
            'tmdb cdn' => [
                'http://image.tmdb.org/t/p/w500/poster.jpg',
                'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
            'tmdb page image' => [
                'https://www.themoviedb.org/t/p/w600_and_h900_bestv2/poster.jpg',
                'https://image.tmdb.org/t/p/w600_and_h900_bestv2/poster.jpg',
            ],
            'tvmaze image' => [
                'https://static.tvmaze.com/uploads/images/original_untouched/1/2.jpg',
                'https://static.tvmaze.com/uploads/images/original_untouched/1/2.jpg',
            ],
        ];
    }

    #[DataProvider('untrustedUrls')]
    public function test_untrusted_artwork_urls_are_rejected(mixed $input): void
    {
        $this->assertNull((new TrustedArtworkUrl)->normalize($input));
    }

    public static function untrustedUrls(): array
    {
        return [
            'provider ip' => ['http://103.176.90.118/poster.jpg'],
            'private host' => ['https://127.0.0.1/poster.jpg'],
            'tmdb page' => ['https://www.themoviedb.org/movie/123'],
            'credentials' => ['https://user:pass@image.tmdb.org/poster.jpg'],
            'port' => ['https://image.tmdb.org:8443/poster.jpg'],
            'javascript' => ['javascript:alert(1)'],
            'array' => [['https://image.tmdb.org/poster.jpg']],
        ];
    }

    public function test_first_returns_the_first_trusted_array_entry(): void
    {
        $this->assertSame(
            'https://image.tmdb.org/t/p/w1280/backdrop.jpg',
            (new TrustedArtworkUrl)->first([
                'http://127.0.0.1/private.jpg',
                'https://image.tmdb.org/t/p/w1280/backdrop.jpg',
            ]),
        );
    }
}
