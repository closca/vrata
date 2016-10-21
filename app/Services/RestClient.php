<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;

/**
 * Class RestClient
 * @package App\Services
 */
class RestClient
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $guzzleParams = [
        'headers' => []
    ];

    /**
     * RestClient constructor.
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array $headers
     */
    public function setHeaders(array $headers)
    {
        $this->guzzleParams['headers'] = $headers;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->guzzleParams['headers'];
    }

    /**
     * @param string $body
     */
    public function setBody($body)
    {
        $this->guzzleParams['body'] = $body;
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function post($url)
    {
        return $this->client->post($url, $this->guzzleParams);
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function put($url)
    {
        return $this->client->put($url, $this->guzzleParams);
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function delete($url)
    {
        return $this->client->delete($url, $this->guzzleParams);
    }

    /**
     * @param Collection $batch
     * @param array $parametersJar
     * @return RestBatchResponse
     */
    public function asyncGet(Collection $batch, $parametersJar)
    {
        $wrapper = new RestBatchResponse();
        $wrapper->setCritical($batch->filter(function($action) { return $action->isCritical(); })->count());

        $promises = $batch->reduce(function($carry, $action) use ($parametersJar) {
            $url = $this->injectParams($action->getUrl(), $parametersJar);
            $carry[$action->getAlias()] = $this->client->getAsync($url, $this->guzzleParams);
            return $carry;
        }, []);

        $responses = collect(Promise\settle($promises)->wait());

        // Process successful responses
        $responses->filter(function ($response) {
            return $response['state'] == 'fulfilled';
        })->each(function ($response, $alias) use ($wrapper) {
            $wrapper->addSuccessfulAction($alias, $response['value']);
        });

        // Process failures
        $responses->filter(function ($response) {
            return $response['state'] != 'fulfilled';
        })->each(function ($response, $alias) use ($wrapper) {
            $response = $response['reason']->getResponse();

            // Do we have an error response from the service?
            if (! $response) $response = new Response(502, []);

            $wrapper->addFailedAction($alias, $response);
        });

        return $wrapper;
    }

    /**
     * @param string $url
     * @param array $params
     * @return string
     */
    private function injectParams($url, array $params)
    {
        foreach ($params as $key => $value) {
            $url = str_replace("{" . $key . "}", $value, $url);
        }

        return $url;
    }
}