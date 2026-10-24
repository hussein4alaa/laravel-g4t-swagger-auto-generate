<?php

namespace G4T\Swagger\Tests\Feature;

use G4T\Swagger\Tests\TestCase;

class DashboardDisabledTest extends TestCase
{
    public function test_the_dashboard_does_not_exist_without_credentials(): void
    {
        $this->get('/swagger/dashboard')->assertNotFound();
        $this->post('/swagger/dashboard/login', ['username' => 'admin', 'password' => ''])->assertNotFound();
        $this->getJson('/swagger/dashboard/api/settings')->assertNotFound();
    }
}
