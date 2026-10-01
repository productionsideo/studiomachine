<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** Le tableau de bord est privé : sans session, on est renvoyé à la connexion. */
    public function test_le_tableau_de_bord_exige_une_connexion(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
