<?php

namespace App\Services\Connexion;

/**
 * Un refus de TikTok, avec son code. Le code compte plus que le message :
 * c'est lui qui dit si l'on peut retenter.
 */
class TiktokErreur extends \RuntimeException
{
    public function __construct(public readonly string $codeTiktok, string $message)
    {
        parent::__construct($message);
    }
}
