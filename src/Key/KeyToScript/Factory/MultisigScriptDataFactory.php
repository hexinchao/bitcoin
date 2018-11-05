<?php

declare(strict_types=1);

namespace BitWasp\Bitcoin\Key\KeyToScript\Factory;

use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Key\PublicKeySerializerInterface;
use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptType;
use BitWasp\Bitcoin\Transaction\Factory\SignData;

class MultisigScriptDataFactory extends KeyToScriptDataFactory
{
    /**
     * @var int
     */
    private $numSigners;

    /**
     * @var int
     */
    private $numKeys;

    /**
     * @var bool
     */
    private $sortKeys;

    public function __construct(int $numSigners, int $numKeys, bool $sortKeys, PublicKeySerializerInterface $pubKeySerializer = null)
    {
        $this->numSigners = $numSigners;
        $this->numKeys = $numKeys;
        $this->sortKeys = $sortKeys;
        parent::__construct($pubKeySerializer);
    }

    /**
     * @return string
     */
    public function getScriptType(): string
    {
        return ScriptType::MULTISIG;
    }

    /**
     * @param PublicKeyInterface ...$publicKeys
     * @return ScriptAndSignData
     */
    protected function convertKeyToScriptData(PublicKeyInterface... $publicKeys): ScriptAndSignData
    {
        if (count($publicKeys) !== $this->numKeys) {
            throw new \InvalidArgumentException("Incorrect number of keys");
        }

        return new ScriptAndSignData(
            ScriptFactory::scriptPubKey()->multisig($this->numSigners, $publicKeys, $this->sortKeys),
            new SignData()
        );
    }
}
