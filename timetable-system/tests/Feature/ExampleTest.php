<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/imports');
    }

    public function test_subjects_page_redirects_guests_to_login(): void
    {
        $response = $this->get('/subjects');

        $response->assertRedirect('/login');
    }

    public function test_import_page_redirects_guests_to_login(): void
    {
        $response = $this->get('/imports');

        $response->assertRedirect('/login');
    }

    public function test_login_page_returns_a_successful_response(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_import_page_returns_success_for_authenticated_users(): void
    {
        $user = User::factory()->make();

        $response = $this->actingAs($user)->get('/imports');

        $response->assertStatus(200);
        $response->assertSee('Bảng thời khóa biểu');
    }
}
