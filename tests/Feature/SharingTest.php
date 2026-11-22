<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_reissue_and_access_changes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->actingAs($owner);
        $id = $this->postJson('/api/recipients', ['name' => '家族A', 'relationship' => '家族'])
            ->assertCreated()->json('id');
        $recipient = "/api/recipients/{$id}";
        $invitation = $recipient.'/invitation';
        $this->getJson($invitation)->assertNoContent();
        $discarded = $this->postJson($invitation)->assertCreated()->json('token');
        $token = $this->postJson($invitation)->assertCreated()->json('token');
        $this->getJson($invitation)->assertOk()->assertJsonMissingPath('token');
        $this->postJson('/api/invitations/preview', ['token' => $discarded])->assertNotFound();
        $this->postJson('/api/invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('recipient_name', '家族A');

        $this->actingAs($member);
        $this->getJson($recipient)->assertNotFound();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertCreated();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertNotFound();
        $this->getJson($recipient)->assertOk();
        $this->getJson('/api/recipients')->assertJsonCount(1);
        $this->postJson($invitation)->assertForbidden();
        $this->getJson($recipient.'/members')->assertForbidden();

        $input = ['name' => '本', 'gifted_on' => '2025-12-01', 'giver' => '友人', 'event' => '誕生日'];
        $gift = $this->post($recipient.'/gifts', $input + ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('direction', 'given')->assertJsonPath('has_photo', true)->assertJsonMissingPath('photo_path')->json('id');
        $path = "/api/gifts/{$gift}";
        $photo = $path.'/photo';
        $this->getJson($photo)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($photo, ['_method' => 'PUT', 'photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertNoContent();

        $this->actingAs($owner);
        $this->getJson($recipient.'/gifts')->assertOk()->assertJsonPath('data.0.id', $gift)
            ->assertJsonPath('data.0.event', '誕生日')
            ->assertJsonPath('data.0.direction', 'given')
            ->assertJsonPath('data.0.has_photo', true)
            ->assertJsonMissingPath('data.0.photo_path');
        $this->getJson($photo)->assertOk();
        $this->patchJson($path, $input)->assertForbidden();
        $this->deleteJson($recipient)->assertConflict();
        $this->deleteJson($recipient.'/members/'.$member->id)->assertNoContent();
        $this->actingAs($member);
        $this->getJson($path)->assertNotFound();
        $this->getJson($photo)->assertNotFound();
        $this->getJson('/api/gifts')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($path, $input)->assertNotFound();

        $this->actingAs($owner);
        $this->getJson($path)->assertOk();
        $newToken = $this->postJson($invitation)->assertCreated()->json('token');
        $this->actingAs($member);
        $this->postJson('/api/invitations/accept', ['token' => $newToken])->assertCreated();
        $this->patchJson($path, $input + ['reaction' => '喜んでいた', 'direction' => 'received'])->assertOk()
            ->assertJsonPath('direction', 'received');
        $this->getJson($path)->assertOk()->assertJsonPath('direction', 'received');
        $this->getJson('/api/gifts?direction=received')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient.name', '家族A');
        $this->getJson('/api/gifts?direction=given')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($recipient.'/gifts?direction=received')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/gifts?direction=invalid')->assertUnprocessable();
        $this->deleteJson($recipient.'/membership')->assertNoContent();
        $this->getJson($photo)->assertNotFound();
    }

    public function test_expired_and_revoked_invitations_cannot_be_used(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/recipients', ['name' => '友人A', 'relationship' => '友人'])
            ->assertCreated()->json('id');
        $invitation = "/api/recipients/{$id}/invitation";
        $expired = $this->postJson($invitation)->assertCreated()->json('token');
        $this->travel(7)->days();
        $this->postJson('/api/invitations/preview', ['token' => $expired])->assertNotFound();
        $revoked = $this->postJson($invitation)->assertCreated()->json('token');
        $this->deleteJson($invitation)->assertNoContent();
        $this->getJson($invitation)->assertNoContent();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/invitations/accept', ['token' => $expired])->assertNotFound();
        $this->postJson('/api/invitations/accept', ['token' => $revoked])->assertNotFound();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aSnkAAAAASUVORK5CYII='
        ));
    }
}
