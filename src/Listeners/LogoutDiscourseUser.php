<?php

namespace CochOrg\Discourse\Listeners;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Class LogoutDiscourseUser
 *
 * Send a Logout request to Discourse for the corresponding Laravel User.
 */
class LogoutDiscourseUser implements ShouldQueue
{
    /**
     * The client instance
     */
    public Client $client;

    /**
     * @The repository instance
     */
    public Repository $config_repository;

    /**
     * The logger instance
     */
    public Logger $logger;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(Client $client, Repository $config_repository, Logger $logger)
    {
        $this->client = $client;
        $this->config_repository = $config_repository;
        $this->logger = $logger;
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle($event)
    {
        if (! $event->user) {
            return;
        }

        $discourses_urls = explode(',', $this->config_repository->get('services.discourse.url'));
        $discourses_api_keys = explode(',', $this->config_repository->get('services.discourse.api.key'));
        $discourses_api_usernames = explode(',', $this->config_repository->get('services.discourse.api.user'));

        foreach($discourses_urls as $key => $url) {

            if(!isset($discourses_api_keys[$key]) || !isset($discourses_api_usernames[$key])) continue;

            $configs = [
                'base_uri' => $url,
                'headers' => [
                    'Api-Key' => $discourses_api_keys[$key],
                    'Api-Username' => $discourses_api_usernames[$key],
                ],
            ];

            try {
                // Get Discourse user to match this one, and send a Logout request to Discourse and get the response
                $response = $this->client->get("users/by-external/{$event->user->id}.json", $configs);
            } catch (BadResponseException $e) {
                $response = $e->getResponse();
            }

            if ($response->getStatusCode() !== 200) {
                $this->logger->error(
                    "When getting user {$event->user->id} Discourse returned status code {$response->getStatusCode()}",
                    ['reason' => $response->getReasonPhrase()]
                );

                continue;
            }

            $user = json_decode($response->getBody())->user;
            $response = $this->client->post("admin/users/{$user->id}/log_out", $configs);

            if ($response->getStatusCode() !== 200) {
                $this->logger->notice(
                    "When logging out user {$event->user->id} Discourse returned status code {$response->getStatusCode()}:",
                    ['reason' => $response->getReasonPhrase()]
                );
            }
        }
    }
}
