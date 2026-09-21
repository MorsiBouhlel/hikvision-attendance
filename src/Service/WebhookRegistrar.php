<?php

namespace App\Service;

use App\Entity\Device;

class WebhookRegistrar
{
    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly string $appBaseUrl, // injecté via services.yaml, ex: %env(APP_BASE_URL)%
    ) {
    }

    public function register(Device $device, ?string $baseUrlOverride = null): string
    {
        $baseUrl = rtrim($baseUrlOverride ?? $this->appBaseUrl, '/');
        $webhookUrl = "{$baseUrl}/api/hikvision/webhook/{$device->getWebhookToken()}";

        $this->clientFactory->forDevice($device)->registerWebhook($webhookUrl);

        return $webhookUrl;
    }
}
