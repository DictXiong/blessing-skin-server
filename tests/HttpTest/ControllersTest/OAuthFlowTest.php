<?php
// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;

class OAuthFlowTest extends TestCase
{
    public function testAuthorizationCodeAndRefreshKeepScopeRestrictions()
    {
        $user = User::factory()->create();
        $client = app(ClientRepository::class)->create(
            $user->uid, 'Local compatibility test', 'http://localhost/callback'
        );
        $parameters = [
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect,
            'response_type' => 'code',
            'scope' => 'User.Read',
            'state' => 'local-compatibility-state',
        ];

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($parameters))
            ->assertOk()->assertSee('name="auth_token"', false);
        $response = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
            'client_id' => $client->id,
            'state' => $parameters['state'],
        ])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $callback);
        $this->assertSame($parameters['state'], $callback['state']);
        $this->assertNotEmpty($callback['code']);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->secret,
            'redirect_uri' => $client->redirect,
            'code' => $callback['code'],
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();

        Auth::forgetGuards();
        $this->withToken($token['access_token'])->getJson('/api/user')
            ->assertOk()->assertJson(['uid' => $user->uid]);
        $this->postJson('/api/players', ['name' => 'LocalPlayer'])
            ->assertOk()->assertJson(['code' => 403]);
        $this->assertDatabaseCount('players', 0);

        $refreshed = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'client_secret' => $client->secret,
            'refresh_token' => $token['refresh_token'],
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
        Auth::forgetGuards();
        $this->withToken($refreshed['access_token'])->getJson('/api/user')
            ->assertOk()->assertJson(['uid' => $user->uid]);
        $this->postJson('/api/players', ['name' => 'LocalPlayer'])
            ->assertOk()->assertJson(['code' => 403]);
        $this->assertDatabaseCount('players', 0);
    }
}
