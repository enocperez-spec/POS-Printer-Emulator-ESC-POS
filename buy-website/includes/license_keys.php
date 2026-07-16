<?php
declare(strict_types=1);

function issue_activation_key(string $customerName, string $emailAddress, string $licenseTier = 'Pro'): array
{
    $privateKeyPem = @file_get_contents(BUY_ROOT . '/private/vendor-private-key.pem');
    $privateKey = $privateKeyPem ? openssl_pkey_get_private($privateKeyPem) : false;
    if ($privateKey === false) throw new RuntimeException('The protected signing key is unavailable.');
    $guidBytes = random_bytes(16); $timestamp = time();
    $hash = static function (string $value): string {
        $normalized = strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));
        return substr(hash('sha256', $normalized, true), 0, 16);
    };
    $tierValue = match ($licenseTier) { 'Pro' => 1, 'Enterprise' => 2, default => throw new InvalidArgumentException('Invalid license level.') };
    $payload = chr(2) . $guidBytes . pack('N2', 0, $timestamp) . $hash($customerName) . $hash($emailAddress) . chr($tierValue);
    if (!openssl_sign($payload, $der, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('The activation key could not be signed.');
    $offset = 0;
    $readLength = static function (string $data, int &$at): int { $first = ord($data[$at++]); if (($first & 0x80) === 0) return $first; $n=$first&0x7f; $v=0; for($i=0;$i<$n;$i++)$v=($v<<8)|ord($data[$at++]); return $v; };
    if (ord($der[$offset++]) !== 0x30) throw new RuntimeException('Invalid signature.');
    $readLength($der, $offset);
    $readInt = static function (string $data, int &$at) use ($readLength): string { if(ord($data[$at++])!==2)throw new RuntimeException('Invalid signature.'); $n=$readLength($data,$at); $v=substr($data,$at,$n); $at+=$n; return str_pad(ltrim($v,"\0"),32,"\0",STR_PAD_LEFT); };
    $signature = $readInt($der,$offset) . $readInt($der,$offset);
    $hex = bin2hex($guidBytes);
    $licenseId = substr($hex,6,2).substr($hex,4,2).substr($hex,2,2).substr($hex,0,2).'-'.substr($hex,10,2).substr($hex,8,2).'-'.substr($hex,14,2).substr($hex,12,2).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    return ['license_id'=>$licenseId, 'license_tier'=>$licenseTier, 'activation_key'=>'PPE1-'.rtrim(strtr(base64_encode($payload.$signature),'+/','-_'),'=')];
}
