<?php

namespace App\Service;

use App\Entity\Device;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HikvisionClientFactory
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function forDevice(Device $device): HikvisionDigestClient
    {
        return new HikvisionDigestClient($this->httpClient, $device);
    }
}
