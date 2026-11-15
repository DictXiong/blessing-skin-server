<?php

// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.

namespace Tests;

use App\Models\Player;
use App\Models\User;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Ramsey\Uuid\Uuid;
use Yggdrasil\Models\Token;

class YggdrasilFlowTest extends TestCase
{
    use DatabaseTransactions;

    private ClassLoader $pluginLoader;

    protected function setUp(): void
    {
        parent::setUp();
        $fixture = base_path('tests/fixtures/yggdrasil-api');
        $this->pluginLoader = new ClassLoader();
        $this->pluginLoader->addPsr4('Yggdrasil\\', $fixture.'/src');
        $this->pluginLoader->register();
        require_once $fixture.'/src/Utils/helpers.php';
        Route::namespace('Yggdrasil\\Controllers')->prefix('api/yggdrasil')->group($fixture.'/routes.php');
        Schema::create('uuid', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('uuid');
        });
        config(['logging.channels.ygg' => ['driver' => 'null']]);
        option(['ygg_uuid_algorithm' => 'v3', 'ygg_token_expire_1' => 3600,
            'ygg_token_expire_2' => 7200, 'ygg_tokens_limit' => 10, 'ygg_rate_limit' => 1000]);
    }

    protected function tearDown(): void
    {
        $this->pluginLoader->unregister();
        parent::tearDown();
    }

    public function testLegacyBuilderKeepsClaimsWithoutAssigningReturnValues(): void
    {
        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('x', 64)));
        $builder = $jwt->builder();
        $builder->relatedTo('owner')->withClaim('yggt', 'token-id');
        $builder->withClaim('spr', 'selected-profile');
        $token = $builder->issuedBy('Yggdrasil-Auth')->getToken($jwt->signer(), $jwt->signingKey());
        $this->assertSame('owner', $token->claims()->get('sub'));
        $this->assertSame('token-id', $token->claims()->get('yggt'));
        $this->assertSame('selected-profile', $token->claims()->get('spr'));
    }

    public function testPublicPluginAuthenticateValidateRefreshJoinAndInvalidate(): void
    {
        $user = User::factory()->create(['email' => 'synthetic-ygg@example.test']);
        $user->changePassword('synthetic-password');
        $player = Player::factory()->create(['uid' => $user->uid, 'name' => 'SyntheticPlayer']);
        $response = $this->postJson('/api/yggdrasil/authserver/authenticate', [
            'username' => $user->email, 'password' => 'synthetic-password',
            'clientToken' => 'synthetic-client', 'requestUser' => true,
        ])->assertOk();
        $accessToken = $response->json('accessToken');
        $profile = $response->json('selectedProfile');
        $this->assertSame($player->name, $profile['name']);
        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(config('jwt.secret')));
        $claims = $jwt->parser()->parse($accessToken)->claims();
        $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_DNS, $user->email)->getHex()->toString(), $claims->get('sub'));
        $this->assertNotEmpty($claims->get('yggt'));
        $this->assertSame($profile['id'], $claims->get('spr'));
        $this->assertTrue(Token::find($accessToken)->isValid());
        $wrongOwner = clone Token::find($accessToken);
        $wrongOwner->owner = 'other-owner@example.test';
        $this->assertFalse($wrongOwner->isValid());
        $this->postJson('/api/yggdrasil/authserver/validate', [
            'accessToken' => $accessToken, 'clientToken' => 'synthetic-client',
        ])->assertNoContent();

        $refreshed = $this->postJson('/api/yggdrasil/authserver/refresh', [
            'accessToken' => $accessToken, 'clientToken' => 'synthetic-client',
        ])->assertOk();
        $newToken = $refreshed->json('accessToken');
        $this->assertNotSame($accessToken, $newToken);
        $this->assertNull(Token::find($accessToken));
        $this->assertTrue(Token::find($newToken)->isValid());
        $this->assertSame($profile['id'], $jwt->parser()->parse($newToken)->claims()->get('spr'));
        $this->postJson('/api/yggdrasil/authserver/validate', [
            'accessToken' => $newToken, 'clientToken' => 'synthetic-client',
        ])->assertNoContent();
        $this->postJson('/api/yggdrasil/sessionserver/session/minecraft/join', [
            'accessToken' => $newToken, 'selectedProfile' => $profile['id'], 'serverId' => 'synthetic-server',
        ])->assertNoContent();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        option(['ygg_private_key' => $privateKey]);
        $joined = $this->getJson('/api/yggdrasil/sessionserver/session/minecraft/hasJoined?username=SyntheticPlayer&serverId=synthetic-server')
            ->assertOk()->assertJson(['id' => $profile['id'], 'name' => $player->name]);
        $property = $joined->json('properties.0');
        $this->assertSame(1, openssl_verify($property['value'], base64_decode($property['signature']), openssl_pkey_get_details($key)['key']));
        $this->getJson('/api/yggdrasil/sessionserver/session/minecraft/hasJoined?username=OtherPlayer&serverId=synthetic-server')->assertNoContent();
        // Unknown server IDs are a non-error response and do not exercise the plugin's exit() error handler.
        $this->getJson('/api/yggdrasil/sessionserver/session/minecraft/hasJoined?username=SyntheticPlayer&serverId=unknown-server')->assertNoContent();
        $this->postJson('/api/yggdrasil/authserver/invalidate', [
            'accessToken' => $newToken, 'clientToken' => 'synthetic-client',
        ])->assertNoContent();
        $this->assertNull(Token::find($newToken));
    }

    public function testRefreshCanSelectOneOfMultipleOwnedProfiles(): void
    {
        $user = User::factory()->create(['email' => 'multiple-profiles@example.test']);
        $user->changePassword('synthetic-password');
        Player::factory()->create(['uid' => $user->uid, 'name' => 'SyntheticOne']);
        Player::factory()->create(['uid' => $user->uid, 'name' => 'SyntheticTwo']);
        $authenticated = $this->postJson('/api/yggdrasil/authserver/authenticate', [
            'username' => $user->email, 'password' => 'synthetic-password', 'clientToken' => 'profile-client',
        ])->assertOk();
        $this->assertNull($authenticated->json('selectedProfile'));
        $profile = $authenticated->json('availableProfiles.1');
        $refreshed = $this->postJson('/api/yggdrasil/authserver/refresh', [
            'accessToken' => $authenticated->json('accessToken'), 'clientToken' => 'profile-client',
            'selectedProfile' => $profile,
        ])->assertOk()->assertJson(['selectedProfile' => $profile]);
        $token = Token::find($refreshed->json('accessToken'));
        $this->assertTrue($token->isValid());
        $this->assertSame($profile['id'], $token->profileId);
    }

    public function testPublicPluginFixtureMatchesThePinnedSource(): void
    {
        $fixture = base_path('tests/fixtures/yggdrasil-api');
        $source = json_decode(file_get_contents($fixture.'/SOURCE.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('de016f4cbb43df7bd6d46193d5625693f8c79b3f', $source['commit']);
        foreach ($source['files'] as $file) {
            $this->assertSame($file['sha256'], hash_file('sha256', $fixture.'/'.$file['path']), $file['path']);
        }
    }
}
