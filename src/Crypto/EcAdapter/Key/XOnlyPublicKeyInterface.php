<?php declare(strict_types=1);

namespace BitWasp\Bitcoin\Crypto\EcAdapter\Key;

use BitWasp\Bitcoin\Crypto\EcAdapter\Signature\SchnorrSignatureInterface;
use BitWasp\Buffertools\BufferInterface;

interface XOnlyPublicKeyInterface
{
    public function isPositive(): bool;
    public function verifySchnorr(BufferInterface $msg32, SchnorrSignatureInterface $schnorrSig): bool;
    public function tweakAdd(BufferInterface $tweak32): XOnlyPublicKeyInterface;
    public function getBuffer(): BufferInterface;
}