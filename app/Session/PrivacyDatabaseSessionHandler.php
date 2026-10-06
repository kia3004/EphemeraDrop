<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

class PrivacyDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);

        unset($payload['ip_address'], $payload['user_agent'], $payload['user_id']);

        return $payload;
    }
}
