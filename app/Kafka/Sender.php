<?php

namespace App\Kafka;

interface Sender
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws DeliveryFailed
     */
    public function send(string $topic, string $key, string $payload, array $headers = []): void;
}
