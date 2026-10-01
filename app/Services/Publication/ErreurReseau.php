<?php

namespace App\Services\Publication;

/**
 * Un refus d'une plateforme, avec ce qu'il faut pour décider d'un nouvel
 * essai : `passagere` vaut true seulement quand la plateforme elle-même dit
 * que l'erreur est temporaire, ou qu'on n'a pas pu la joindre.
 */
class ErreurReseau extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $passagere = false,
        int $code = 0,
        public readonly int $sousCode = 0,
    ) {
        parent::__construct($message, $code);
    }
}
