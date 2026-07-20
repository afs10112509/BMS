<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_redirects_root_to_spa(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/app/');
    }

    public function test_api_root_is_available(): void
    {
        $response = $this->getJson('/api/');

        $response->assertOk()
            ->assertJsonStructure(['message', 'versi', 'ui', 'endpoint', 'auth_hint']);
    }
}
