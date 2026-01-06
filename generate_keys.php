<?php
// Create generate_keys.php file
$config = [
    "digest_alg" => "sha256",
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

// Generate private key
$privateKey = openssl_pkey_new($config);
if (!$privateKey) {
    
    die("Failed to generate private key\n");
}

// Extract private key

openssl_pkey_export($privateKey, $privateKeyPem);

// Extract public key

$publicKeyDetails = openssl_pkey_get_details($privateKey);
$publicKeyPem = $publicKeyDetails["key"];

// Save keys

file_put_contents('admin_private.pem', $privateKeyPem);
file_put_contents('admin_public.pem', $publicKeyPem);

echo "Keys generated successfully!\n";
echo "Private key: admin_private.pem (KEEP SECURE)\n";
echo "Public key: admin_public.pem (Deploy with application)\n";
?>