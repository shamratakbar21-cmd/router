<?php

require dirname(__DIR__).'/vendor/autoload.php';

$testPrivateKey = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
putenv('NUR_LICENSE_SIGNING_PRIVATE_KEY='.$testPrivateKey);
$_ENV['NUR_LICENSE_SIGNING_PRIVATE_KEY'] = $testPrivateKey;
$_SERVER['NUR_LICENSE_SIGNING_PRIVATE_KEY'] = $testPrivateKey;
