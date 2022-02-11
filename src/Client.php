<?php

namespace Sebdesign\ArtisanCloudflare;

use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class Client
{
    /**
     * Base URI.
     */
    public const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    protected GuzzleClient $client;

    protected LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param GuzzleClient    $client
     * @param LoggerInterface $logger
     */
    public function __construct(GuzzleClient $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Delete all the given zones with their parameters.
     *
     * All the requests are asynchronous and sent concurrently.
     *
     * The promise waits until all the promises have been resolved or rejected
     * and returns the results of each request.
     *
     * @param Collection $zones
     *
     * @return Collection
     */
    public function purge(Collection $zones): Collection
    {
        return $zones->map(function (Zone $zone, $identifier) {
            return $this->delete($identifier, $zone);
        })->pipe(function ($promises) {
            return $this->settle($promises);
        })->wait();
    }

    /**
     * @param string $identifier
     * @param Zone   $zone
     *
     * @return PromiseInterface
     */
    protected function delete(string $identifier, Zone $zone): PromiseInterface
    {
        return $this->client->deleteAsync("zones/{$identifier}/purge_cache", [
            \GuzzleHttp\RequestOptions::JSON => $zone,
        ]);
    }

    /**
     * Returns a promise that is fulfilled when all of the provided promises have
     * been fulfilled or rejected.
     *
     * The returned promise is fulfilled with a collection of results.
     *
     * @param Collection $promises
     *
     * @return PromiseInterface
     */
    protected function settle(Collection $promises): PromiseInterface
    {
        $results = new Collection();

        return Promise\Each::of(
            $promises->getIterator(),
            $this->onFulfilled($results),
            $this->onRejected($results)
        )->then(function () use ($results) {
            return $results;
        });
    }

    /**
     * Put the body of the fulfilled promise into the results.
     *
     * @param Collection $results
     *
     * @return Closure
     */
    protected function onFulfilled(Collection $results): Closure
    {
        /*
         * @param  \Psr\Http\Message\ResponseInterface $response
         * @param  string                              $identifier
         * @return \Illuminate\Support\Collection<string,\Sebdesign\ArtisanCloudflare\Zone>
         */
        return function ($response, $identifier) use ($results) {
            return $results->put($identifier, $this->getBody($response));
        };
    }

    /**
     * Handle the rejected promise and put the errors into the results.
     *
     * @param Collection $results
     *
     * @return Closure
     */
    protected function onRejected(Collection $results): Closure
    {
        /*
         * @param  \GuzzleHttp\Exception\RequestException $reason
         * @param  string                                 $identifier
         * @return \Illuminate\Support\Collection<string,\Sebdesign\ArtisanCloudflare\Zone>
         */
        return function ($reason, $identifier) use ($results) {
            $this->logger->error($reason->getMessage(), [
                'zone' => $identifier,
                'exception' => $reason,
            ]);

            return $results->put($identifier, $this->handleException($reason));
        };
    }

    /**
     * Transform a request exception into a result object.
     *
     * @param RequestException $e
     *
     * @return Zone
     */
    protected function handleException(RequestException $e): Zone
    {
        if ($e->hasResponse()) {
            /** @var ResponseInterface $response */
            $response = $e->getResponse();

            if ($e instanceof ClientException) {
                return $this->getBody($response);
            }

            $message = (string) $response->getBody();
        } else {
            $message = $e->getMessage();
        }

        return new Zone([
            'success' => false,
            'errors' => [
                [
                    'code' => $e->getCode(),
                    'message' => $message,
                ],
            ],
        ]);
    }

    /**
     * Transform the response body into a result object.
     *
     * @param ResponseInterface $response
     *
     * @return Zone
     */
    protected function getBody(ResponseInterface $response): Zone
    {
        return new Zone(json_decode($response->getBody(), true));
    }

    public function getClient(): GuzzleClient|ClientInterface
    {
        return $this->client;
    }
}
