<?php

namespace App\Kafka;

interface Sender
{
    /** @throws DeliveryFailed */
    public function send(string $topic, string $key, string $payload): void;
}
