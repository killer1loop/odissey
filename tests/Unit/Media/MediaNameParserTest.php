<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaNameParser;
use PHPUnit\Framework\TestCase;

class MediaNameParserTest extends TestCase
{
    public function test_it_parses_movies_and_episodes_without_exposing_paths(): void
    {
        $parser = new MediaNameParser;

        $this->assertSame([
            'kind' => 'movie', 'title' => 'Arrival', 'year' => 2016,
        ], $parser->parse('/private/library/Arrival.2016.1080p.mkv'));

        $this->assertSame([
            'kind' => 'episode', 'title' => 'The Work Outing',
            'series_title' => 'The IT Crowd', 'season_number' => 2, 'episode_number' => 1,
        ], $parser->parse('The.IT.Crowd.S02E01.The.Work.Outing.mkv'));
    }
}
