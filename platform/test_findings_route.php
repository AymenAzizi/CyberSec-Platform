<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$user = App\Models\User::first();
auth()->login($user);

$req = Illuminate\Http\Request::create('/findings', 'GET');
$resp = $kernel->handle($req);

echo "STATUS: " . $resp->getStatusCode() . "\n";
if ($resp->getStatusCode() !== 200) {
    echo "CONTENT: " . substr($resp->getContent(), 0, 500) . "\n";
}
