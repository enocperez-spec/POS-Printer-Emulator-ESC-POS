<?php
declare(strict_types=1);

function activation_tier_value(string $licenseTier): int
{
    return match ($licenseTier) {
        'Pro' => 1,
        'Enterprise' => 2,
        'Lite' => 3,
        default => throw new InvalidArgumentException('Invalid license level.'),
    };
}

function issue_activation_key(
    string $customerName,
    string $emailAddress,
    string $licenseTier = 'Pro',
    ?string $maintenanceExpiresAt = null,
    ?string $privateKeyOverride = null
): array
{
    $privateKeyPem = $privateKeyOverride ?? @file_get_contents(BUY_ROOT . '/private/vendor-private-key.pem');
    $privateKey = $privateKeyPem ? openssl_pkey_get_private($privateKeyPem) : false;
    if ($privateKey === false) throw new RuntimeException('The protected signing key is unavailable.');
    $guidBytes = random_bytes(16); $timestamp = time();
    $tierValue = activation_tier_value($licenseTier);
    $maintenanceExpiration = normalize_maintenance_expiration($maintenanceExpiresAt, $timestamp, false);
    $payload = chr(3) . $guidBytes . pack_unix_seconds($timestamp)
        . activation_registration_hash($customerName) . activation_email_hash($emailAddress)
        . chr($tierValue) . pack_unix_seconds($maintenanceExpiration->getTimestamp());
    if (!openssl_sign($payload, $der, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('The activation key could not be signed.');
    $offset = 0;
    $readLength = static function (string $data, int &$at): int { $first = ord($data[$at++]); if (($first & 0x80) === 0) return $first; $n=$first&0x7f; $v=0; for($i=0;$i<$n;$i++)$v=($v<<8)|ord($data[$at++]); return $v; };
    if (ord($der[$offset++]) !== 0x30) throw new RuntimeException('Invalid signature.');
    $readLength($der, $offset);
    $readInt = static function (string $data, int &$at) use ($readLength): string { if(ord($data[$at++])!==2)throw new RuntimeException('Invalid signature.'); $n=$readLength($data,$at); $v=substr($data,$at,$n); $at+=$n; return str_pad(ltrim($v,"\0"),32,"\0",STR_PAD_LEFT); };
    $signature = $readInt($der,$offset) . $readInt($der,$offset);
    $hex = bin2hex($guidBytes);
    $licenseId = substr($hex,6,2).substr($hex,4,2).substr($hex,2,2).substr($hex,0,2).'-'.substr($hex,10,2).substr($hex,8,2).'-'.substr($hex,14,2).substr($hex,12,2).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    return [
        'license_id'=>$licenseId,
        'license_tier'=>$licenseTier,
        'activation_key'=>'PPE1-'.rtrim(strtr(base64_encode($payload.$signature),'+/','-_'),'='),
        'maintenance_expires_at'=>$maintenanceExpiration->format('Y-m-d H:i:s'),
        'maintenance_token'=>$maintenanceExpiration->getTimestamp()>$timestamp
            ? issue_maintenance_token($licenseId,$licenseTier,$maintenanceExpiration->format('Y-m-d H:i:s'),$timestamp,$privateKeyPem)
            : null,
    ];
}

function activation_registration_hash(string $value): string
{
    $normalized=strtoupper(trim((string)preg_replace('/[ \t\r\n\f\v]+/',' ',$value)," \t\r\n\f\v"));
    return substr(hash('sha256',$normalized,true),0,16);
}

function activation_email_hash(string $value): string
{
    return substr(hash('sha256',strtolower(trim($value," \t\r\n\f\v")),true),0,16);
}

function issue_maintenance_token(string $licenseId, string $licenseTier, string $maintenanceExpiresAt, ?int $issuedTimestamp = null, ?string $privateKeyOverride = null): string
{
    $privateKeyPem = $privateKeyOverride ?? @file_get_contents(BUY_ROOT . '/private/vendor-private-key.pem');
    $privateKey = $privateKeyPem ? openssl_pkey_get_private($privateKeyPem) : false;
    if ($privateKey === false) throw new RuntimeException('The protected signing key is unavailable.');
    $issuedTimestamp ??= time();
    $expiration = normalize_maintenance_expiration($maintenanceExpiresAt,$issuedTimestamp);
    $payload = chr(1).dotnet_guid_bytes($licenseId).pack_unix_seconds($issuedTimestamp)
        .pack_unix_seconds($expiration->getTimestamp()).chr(activation_tier_value($licenseTier));
    if (!openssl_sign($payload,$der,$privateKey,OPENSSL_ALGO_SHA256)) throw new RuntimeException('The maintenance entitlement could not be signed.');
    $offset=0;
    $readLength=static function(string $data,int &$at):int{$first=ord($data[$at++]);if(($first&0x80)===0)return $first;$n=$first&0x7f;$v=0;for($i=0;$i<$n;$i++)$v=($v<<8)|ord($data[$at++]);return $v;};
    if(ord($der[$offset++])!==0x30)throw new RuntimeException('Invalid signature.');
    $readLength($der,$offset);
    $readInt=static function(string $data,int &$at)use($readLength):string{if(ord($data[$at++])!==2)throw new RuntimeException('Invalid signature.');$n=$readLength($data,$at);$v=substr($data,$at,$n);$at+=$n;return str_pad(ltrim($v,"\0"),32,"\0",STR_PAD_LEFT);};
    $signature=$readInt($der,$offset).$readInt($der,$offset);
    return 'PPEM1-'.rtrim(strtr(base64_encode($payload.$signature),'+/','-_'),'=');
}

function normalize_maintenance_expiration(?string $value, int $issuedTimestamp, bool $requireFuture = true): DateTimeImmutable
{
    $issuedAt=(new DateTimeImmutable('@'.$issuedTimestamp))->setTimezone(new DateTimeZone('UTC'));
    if($value===null||trim($value)==='')return $issuedAt->modify('+1 year');
    try{$expiration=(new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}
    catch(Throwable){throw new InvalidArgumentException('The maintenance expiration is invalid.');}
    if($requireFuture&&$expiration<=$issuedAt)throw new InvalidArgumentException('The maintenance expiration must be later than the issue time.');
    return $expiration;
}

function pack_unix_seconds(int $timestamp): string
{
    if($timestamp<0)throw new InvalidArgumentException('The entitlement timestamp is invalid.');
    return pack('N2',intdiv($timestamp,4294967296),$timestamp%4294967296);
}

function dotnet_guid_bytes(string $licenseId): string
{
    $licenseId=strtolower(trim($licenseId));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$licenseId))throw new InvalidArgumentException('The selected license ID is invalid.');
    $hex=str_replace('-','',$licenseId);
    return hex2bin(substr($hex,6,2).substr($hex,4,2).substr($hex,2,2).substr($hex,0,2)
        .substr($hex,10,2).substr($hex,8,2).substr($hex,14,2).substr($hex,12,2).substr($hex,16))
        ?: throw new InvalidArgumentException('The selected license ID is invalid.');
}

function maintenance_token_matches(
    string $token,
    string $expectedLicenseId,
    string $expectedTier,
    string $expectedExpiration,
    string $publicKeyPem
): bool {
    try {
        if(!maintenance_token_claims_match($token,$expectedLicenseId,$expectedTier,$expectedExpiration))return false;
        $encoded=substr($token,6);$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
        $decoded=base64_decode(strtr($encoded,'-_','+/'),true);
        $payload=substr($decoded,0,34);$signature=substr($decoded,34);
        $publicKey=openssl_pkey_get_public($publicKeyPem);
        return $publicKey!==false&&openssl_verify($payload,p1363_to_der($signature),$publicKey,OPENSSL_ALGO_SHA256)===1;
    }catch(Throwable){return false;}
}

function maintenance_token_claims_match(string $token,string $expectedLicenseId,string $expectedTier,string $expectedExpiration): bool
{
    try{
        if(!str_starts_with($token,'PPEM1-'))return false;
        $encoded=substr($token,6);$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
        $decoded=base64_decode(strtr($encoded,'-_','+/'),true);
        if($decoded===false||strlen($decoded)!==98)return false;
        $payload=substr($decoded,0,34);
        if(ord($payload[0])!==1||!hash_equals(dotnet_guid_bytes($expectedLicenseId),substr($payload,1,16))||ord($payload[33])!==activation_tier_value($expectedTier))return false;
        $parts=unpack('Nhigh/Nlow',substr($payload,25,8));$expires=((int)$parts['high']*4294967296)+(int)$parts['low'];
        return (new DateTimeImmutable($expectedExpiration,new DateTimeZone('UTC')))->getTimestamp()===$expires;
    }catch(Throwable){return false;}
}

function p1363_to_der(string $signature): string
{
    if(strlen($signature)!==64)throw new InvalidArgumentException('The maintenance signature length is invalid.');
    $integer=static function(string $value):string{
        $value=ltrim($value,"\0");$value=$value===''?"\0":$value;
        if((ord($value[0])&0x80)!==0)$value="\0".$value;
        return "\x02".chr(strlen($value)).$value;
    };
    $body=$integer(substr($signature,0,32)).$integer(substr($signature,32,32));
    return "\x30".chr(strlen($body)).$body;
}
