<?php
// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.

// The build invokes this against a fresh stage, with its disposable SQLite file.
$stage = $argv[1];
if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $stage.'.sqlite') {
    throw new RuntimeException('This probe requires the disposable build database.');
}
require $stage.'/vendor/autoload.php';
$app = require $stage.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = [];
$check = function ($name, $actual, $expected) use (&$checks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($name.': '.json_encode([$actual, $expected]));
    }
    $checks[] = $name;
};
$user = App\Models\User::forceCreate([
    'email' => 'synthetic-build@example.test', 'nickname' => '100% literal_name',
    'password' => password_hash('synthetic-build-password', PASSWORD_BCRYPT),
    'score' => 0, 'avatar' => 0, 'ip' => '127.0.0.1', 'permission' => 0,
    'last_sign_at' => '2020-01-01 00:00:00', 'register_at' => '2020-01-01 00:00:00',
]);
foreach (['100%', 'literal_name'] as $term) {
    $check('search literal '.$term, App\Models\User::usingSearchString($term)->pluck('uid')->all(), [$user->uid]);
}
$reports = [];
foreach (['2020-01-01', '2020-01-02'] as $date) {
    $report = App\Models\Report::forceCreate([
        'tid' => 1, 'uploader' => $user->uid, 'reporter' => $user->uid,
        'reason' => 'synthetic', 'status' => 0, 'report_at' => $date.' 00:00:00',
    ]);
    $reports[] = $report;
}
$check('report default query', App\Models\Report::usingSearchString('status:0 sort:-report_at')->pluck('id')->all(), [$reports[1]->id, $reports[0]->id]);
$jwt = Lcobucci\JWT\Configuration::forSymmetricSigner(new Lcobucci\JWT\Signer\Hmac\Sha256(), Lcobucci\JWT\Signer\Key\InMemory::plainText(str_repeat('x', 64)));
$builder = $jwt->builder();
$builder->relatedTo('synthetic-owner')->withClaim('yggt', 'synthetic-token');
$builder->withClaim('spr', 'synthetic-profile');
$claims = $builder->getToken($jwt->signer(), $jwt->signingKey())->claims();
foreach (['sub' => 'synthetic-owner', 'yggt' => 'synthetic-token', 'spr' => 'synthetic-profile'] as $claim => $value) {
    $check('legacy JWT claim '.$claim, $claims->get($claim), $value);
}
echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT), "\n";
