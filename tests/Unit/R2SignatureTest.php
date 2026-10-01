<?php

namespace Tests\Unit;

use App\Services\R2;
use PHPUnit\Framework\TestCase;

/**
 * La signature AWS v4, vérifiée contre les exemples publiés par AWS
 * (« Authenticating Requests: Using Query Parameters » et « …Authorization
 * Header », clé AKIAIOSFODNN7EXAMPLE, 24 mai 2013).
 */
class R2SignatureTest extends TestCase
{
    private const CLE    = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const DATE   = '20130524T000000Z';

    public function test_adresse_presignee_conforme_a_l_exemple_aws(): void
    {
        $p = R2::parametresPresignes(
            'GET', 'examplebucket.s3.amazonaws.com', '/test.txt', [], 86400,
            self::DATE, self::CLE, self::SECRET, 'us-east-1',
        );

        $this->assertSame('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $p['X-Amz-Signature']);
    }

    public function test_entete_authorization_conforme_a_l_exemple_aws(): void
    {
        $a = R2::autorisation(
            'GET', '/test.txt', [],
            [
                'Host'                 => 'examplebucket.s3.amazonaws.com',
                'Range'                => 'bytes=0-9',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'x-amz-date'           => self::DATE,
            ],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            self::DATE, self::CLE, self::SECRET, 'us-east-1',
        );

        $this->assertStringEndsWith('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $a);
        $this->assertStringContainsString('SignedHeaders=host;range;x-amz-content-sha256;x-amz-date', $a);
    }
}
