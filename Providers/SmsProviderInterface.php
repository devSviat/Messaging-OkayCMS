<?php

namespace Okay\Modules\Sviat\Messaging\Providers;

/** Контракт SMS-провайдера: send + getName. */
interface SmsProviderInterface
{
    public function send(string $phone, string $message): array;

    public function getName(): string;
}
