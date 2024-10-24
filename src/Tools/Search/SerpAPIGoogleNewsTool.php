<?php

declare(strict_types=1);

namespace UseTheFork\Synapse\Tools\Search;

use Illuminate\Support\Arr;
use UseTheFork\Synapse\Contracts\Tool;
use UseTheFork\Synapse\Contracts\Tool\SearchTool;
use UseTheFork\Synapse\Exceptions\MissingApiKeyException;
use UseTheFork\Synapse\Services\SerpApi\Requests\SerpApiSearchRequest;
use UseTheFork\Synapse\Services\SerpApi\SerpApiConnector;
use UseTheFork\Synapse\Tools\BaseTool;

final class SerpAPIGoogleNewsTool extends BaseTool implements Tool, SearchTool
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('synapse.services.serp_api.key');

        if (empty($this->apiKey)) {
            throw new MissingApiKeyException('API (SERPAPI_API_KEY) key is required.');
        }
    }

    /**
     * Search Google News using a query.
     *
     * @param  string  $query  Parameter defines the query you want to search.
     */
    public function handle(
        string $query,
        ?string $searchType = 'search',
        ?int $numberOfResults = 10
    ): string {

        $serpApiConnector = new SerpApiConnector($this->apiKey);
        $serpApiSearchRequest = new SerpApiSearchRequest($query, 0, 'google_news');

        $results = $serpApiConnector->send($serpApiSearchRequest)->array();
        return $this->parseResults($results);
    }

    private function parseResults(array $results): string
    {

        $snippets = collect();

        if (! empty($results['news_results'])) {
            $newsResults = Arr::get($results, 'news_results');

            foreach ($newsResults as $newResult) {
                $result = collect();
                $result->push("```text\n### Title: {$newResult['title']}");

                if (! empty($newResult['stories'])) {
                    foreach (Arr::get($newResult, 'stories', []) as $story) {

                        $result->push("#### Story: {$story['title']}");
                        $result->push("- Date: {$story['date']}");
                        $result->push("- Link: {$story['link']}");
                    }
                }
                if (! empty($newResult['source'])) {
                    $result->push("- Date: {$newResult['date']}");
                    $result->push("- Link: {$newResult['link']}");
                }
                $result->push("```\n");

                $snippets->push($result->implode("\n"));
            }
        }

        if ($snippets->isEmpty()) {
            return 'No good Google News Result found';
        }

        return $snippets->implode("\n");
    }
}
