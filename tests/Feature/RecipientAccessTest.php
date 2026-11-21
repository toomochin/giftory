<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_is_private_and_registration_creates_a_session(): void
    {
        $this->getJson('/api/recipients')->assertUnauthorized();
        $this->postJson('/api/register', [
            'name' => 'テスト会員',
            'email' => 'giftory@example.test',
            'password' => 'test-password',
            'password_confirmation' => 'test-password',
        ])->assertCreated();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', 'giftory@example.test');
        $id = $this->postJson('/api/recipients', ['name' => '家族A', 'relationship' => '家族'])
            ->assertCreated()->json('id');

        $this->getJson('/api/recipients')->assertJsonCount(1);
        $this->getJson("/api/recipients/{$id}")->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/recipients')->assertJsonCount(0);
        $this->getJson("/api/recipients/{$id}")->assertNotFound();
        $this->deleteJson("/api/recipients/{$id}")->assertNotFound();
    }
}
