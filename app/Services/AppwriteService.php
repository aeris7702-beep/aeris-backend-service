<?php

namespace App\Services;

use Appwrite\Client;
use Appwrite\Services\Databases;

class AppwriteService
{
    protected Client $client;
    protected Databases $database;

    public function __construct()
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint(env('APPWRITE_ENDPOINT'))
            ->setProject(env('APPWRITE_PROJECT_ID'))
            ->setKey(env('APPWRITE_API_KEY'))
            ->setSelfSigned(true);

        $this->database = new Databases($this->client);
    }

    public function database(): Databases
    {
        return $this->database;
    }
}
