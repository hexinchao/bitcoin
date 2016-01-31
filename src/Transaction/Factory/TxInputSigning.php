<?php

namespace BitWasp\Bitcoin\Transaction\Factory;


use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Crypto\Random\Rfc6979;
use BitWasp\Bitcoin\Key\PublicKeyFactory;
use BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\Parser\Operation;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInfo\Multisig;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Signature\SignatureSort;
use BitWasp\Bitcoin\Signature\TransactionSignature;
use BitWasp\Bitcoin\Signature\TransactionSignatureFactory;
use BitWasp\Bitcoin\Signature\TransactionSignatureInterface;
use BitWasp\Bitcoin\Transaction\SignatureHash\Hasher;
use BitWasp\Bitcoin\Transaction\SignatureHash\SigHash;
use BitWasp\Bitcoin\Transaction\SignatureHash\V1Hasher;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\BufferInterface;

class TxInputSigning
{
    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var TransactionInterface
     */
    private $tx;

    /**
     * @var int
     */
    private $nInput;

    /**
     * @var PublicKeyInterface[]
     */
    private $publicKeys = [];

    /**
     * @var int
     */
    private $sigHashType;

    /**
     * @var TransactionSignatureInterface[]
     */
    private $signatures = [];

    /**
     * @var int
     */
    private $requiredSigs = 0;

    /**
     * TxInputSigning constructor.
     * @param EcAdapterInterface $ecAdapter
     * @param TransactionInterface $tx
     * @param int $nInput
     * @param TransactionOutputInterface $txOut
     * @param int $sigHashType
     */
    public function __construct(EcAdapterInterface $ecAdapter, TransactionInterface $tx, $nInput, TransactionOutputInterface $txOut, $sigHashType = SigHash::ALL)
    {
        $this->ecAdapter = $ecAdapter;
        $this->tx = $tx;
        $this->nInput = $nInput;
        $this->txOut = $txOut;
        $this->sigHashType = $sigHashType;
        $this->publicKeys = [];
        $this->signatures = [];
    }

    /**
     * @param $type
     * @param ScriptInterface $scriptCode
     * @param array $stack
     * @return mixed
     */
    public function extractWitnessSignature($type, ScriptInterface $scriptCode, array $stack)
    {
        $size = count($stack);

        if ($type === OutputClassifier::PAYTOPUBKEYHASH) {
            // Supply signature and public key in scriptSig
            if ($size === 2) {
                $this->signatures = [TransactionSignatureFactory::fromHex($stack[0]->getData(), $this->ecAdapter)->getBuffer()];
                $this->publicKeys = [PublicKeyFactory::fromHex($stack[1]->getData(), $this->ecAdapter)];
            }
        }

        if ($type === OutputClassifier::PAYTOPUBKEY) {
            // Only has a signature in the scriptSig
            if ($size === 1) {
                $this->signatures = [TransactionSignatureFactory::fromHex($stack[0]->getData(), $this->ecAdapter)->getBuffer()];
            }
        }

        if ($type === OutputClassifier::MULTISIG) {

            $info = new Multisig($scriptCode);
            $keyCount = $info->getKeyCount();
            $this->requiredSigs = $info->getRequiredSigCount();
            $this->publicKeys = $info->getKeys();

            if ($size > 2 && $size <= $keyCount + 2) {
                $hasher = new V1Hasher($this->tx, $this->txOut->getValue());
                $sigSort = new SignatureSort($this->ecAdapter);
                $sigs = new \SplObjectStorage;

                foreach (array_slice($stack, 1, -1) as $item) {
                    $txSig = TransactionSignatureFactory::fromHex($item, $this->ecAdapter);
                    $hash = $hasher->calculate($scriptCode, $this->nInput, $txSig->getHashType());
                    $linked = $sigSort->link([$txSig->getSignature()], $this->publicKeys, $hash);

                    foreach ($this->publicKeys as $key) {
                        if ($linked->contains($key)) {
                            $sigs[$key] = $txSig->getBuffer();
                        }
                    }
                }

                // We have all the signatures from the input now. array_shift the sigs for a public key, as it's encountered.
                foreach ($this->publicKeys as $idx => $key) {
                    $this->signatures[$idx] = isset($sigs[$key]) ? $sigs[$key]->getBuffer() : null;
                }
            }
        }

        return $type;
    }

    /**
     * @param string $type
     * @param ScriptInterface $scriptCode
     * @param ScriptInterface $scriptSig
     * @param int $sigVersion
     * @return mixed
     */
    public function extractScriptSig($type, ScriptInterface $scriptCode, ScriptInterface $scriptSig, $sigVersion)
    {
        $parsed = $scriptSig->getScriptParser()->decode();
        $size = count($parsed);

        if ($type === OutputClassifier::PAYTOPUBKEYHASH) {
            // Supply signature and public key in scriptSig
            if ($size === 2) {
                $this->signatures = [TransactionSignatureFactory::fromHex($parsed[0]->getData(), $this->ecAdapter)->getBuffer()];
                $this->publicKeys = [PublicKeyFactory::fromHex($parsed[1]->getData(), $this->ecAdapter)];
            }
        }

        if ($type === OutputClassifier::PAYTOPUBKEY) {
            // Only has a signature in the scriptSig
            if ($size === 1) {
                $this->signatures = [TransactionSignatureFactory::fromHex($parsed[0]->getData(), $this->ecAdapter)->getBuffer()];
            }
        }

        if ($type === OutputClassifier::MULTISIG) {

            $info = new Multisig($scriptCode);
            $keyCount = $info->getKeyCount();
            $this->requiredSigs = $info->getRequiredSigCount();
            $this->publicKeys = $info->getKeys();

            if ($size > 2 && $size <= $keyCount + 2) {

                $sigSort = new SignatureSort($this->ecAdapter);
                $sigs = new \SplObjectStorage;

                foreach (array_slice($parsed, 1, -1) as $item) {
                    /** @var \BitWasp\Bitcoin\Script\Parser\Operation $item */
                    if ($item->isPush()) {
                        $txSig = TransactionSignatureFactory::fromHex($item->getData(), $this->ecAdapter);
                        if ($sigVersion == 1) {
                            $hasher = new V1Hasher($this->tx, $this->txOut->getValue());
                        } else {
                            $hasher = new Hasher($this->tx);
                        }

                        $hash = $hasher->calculate($scriptCode, $this->nInput, $txSig->getHashType());
                        $linked = $sigSort->link([$txSig->getSignature()], $this->publicKeys, $hash);

                        foreach ($this->publicKeys as $key) {
                            if ($linked->contains($key)) {
                                $sigs[$key] = $txSig->getBuffer();
                            }
                        }
                    }
                }

                // We have all the signatures from the input now. array_shift the sigs for a public key, as it's encountered.
                foreach ($this->publicKeys as $idx => $key) {
                    $this->signatures[$idx] = isset($sigs[$key]) ? $sigs[$key]->getBuffer() : null;
                }
            }
        }

        return $type;
    }

    /**
     * @return $this
     */
    public function extractSignatures()
    {
        $type = (new OutputClassifier($this->txOut->getScript()))->classify();

        $scriptPubKey = $this->txOut->getScript();
        $scriptSig = $this->tx->getInput($this->nInput)->getScript();

        if ($type === OutputClassifier::PAYTOPUBKEYHASH || $type === OutputClassifier::PAYTOPUBKEY || $type === OutputClassifier::MULTISIG){
            $this->extractScriptSig($type, $scriptPubKey, $scriptSig, 0);
        }

        if ($type === OutputClassifier::PAYTOSCRIPTHASH) {
            $decodeSig = $scriptSig->getScriptParser()->decode();
            if (count($decodeSig) > 1) {
                $final = end($decodeSig)->getData();
                $redeemScript = new Script($final);
                $p2shType = (new OutputClassifier($redeemScript))->classify();
                $internalSig = [];
                array_walk($redeemScript->getScriptParser()->decode(), function (Operation $operation) use (&$internalSig) {
                    if ($operation->isPush()) {
                        $internalSig[] = $operation->getData();
                    } else {
                        $internalSig[] = $operation->getOp();
                    }
                });

                $this->extractScriptSig($p2shType, $redeemScript, ScriptFactory::sequence($internalSig), 0);
                $type = $p2shType;
            }
        }

        $witnesses = $this->tx->getWitnesses();
        if ($type === OutputClassifier::WITNESS_V0_KEYHASH) {
            if (isset($witnesses[$this->nInput])) {
                $witness = $witnesses[$this->nInput];
                $this->signatures = [TransactionSignatureFactory::fromHex($witness[0]->getBuffer(), $this->ecAdapter)->getBuffer()];
                $this->publicKeys = [PublicKeyFactory::fromHex($witness[1]->getBuffer(), $this->ecAdapter)];
            }

        } else if ($type === OutputClassifier::WITNESS_V0_SCRIPTHASH) {
            if (isset($witnesses[$this->nInput])) {
                $witness = $witnesses[$this->nInput];
                $witCount = count($witness);
                if ($witCount > 1) {
                    $witnessScript = new Script($witness[$witCount - 1]);
                    $stack = array_slice($witness->all(), 0, -1);
                    $witnessType = (new OutputClassifier($witnessScript))->classify();
                    $this->extractWitnessSignature($witnessType, $witnessScript, $stack);
                }
            }
        }

        return $this;
    }

    /**
     * @param PrivateKeyInterface $key
     * @param ScriptInterface $scriptCode
     * @param int $sigVersion
     * @return TransactionSignature
     */
    public function calculateSignature(PrivateKeyInterface $key, ScriptInterface $scriptCode, $sigVersion)
    {
        if ($sigVersion == 1) {
            $hasher = new V1Hasher($this->tx, $this->txOut->getValue());
        } else {
            $hasher = new Hasher($this->tx);
        }

        $hash = $hasher->calculate($scriptCode, $this->nInput, $this->sigHashType);

        return new TransactionSignature(
            $this->ecAdapter,
            $this->ecAdapter->sign(
                $hash,
                $key,
                new Rfc6979(
                    $this->ecAdapter,
                    $key,
                    $hash,
                    'sha256'
                )
            ),
            $this->sigHashType
        );
    }

    /**
     * @return int
     */
    public function isFullySigned()
    {
        return $this->requiredSigs !== 0 && $this->requiredSigs === count($this->signatures);
    }

    /**
     * The function only returns true when $scriptPubKey could be classified
     *
     * @param PrivateKeyInterface $key
     * @param ScriptInterface $scriptPubKey
     * @param int $outputType
     * @param BufferInterface[] $results
     * @param int $sigVersion
     * @return bool
     */
    private function doSignature(PrivateKeyInterface $key, ScriptInterface $scriptPubKey, &$outputType, array &$results, $sigVersion = 0)
    {
        /** @var BufferInterface[] $return */
        $return = [];
        $outputType = (new OutputClassifier($scriptPubKey))->classify($return);

        if ($outputType === OutputClassifier::UNKNOWN) {
            throw new \RuntimeException('Cannot sign unknown script type');
        }

        if ($outputType === OutputClassifier::PAYTOPUBKEY) {
            $publicKeyBuffer = $return[0];
            $results[] = $publicKeyBuffer;
            $this->requiredSigs = 1;
            $publicKey = PublicKeyFactory::fromHex($publicKeyBuffer);

            if ($publicKey->getBinary() === $key->getPublicKey()->getBinary()) {
                $this->signatures[0] = $this->calculateSignature($key, $scriptPubKey, $sigVersion);
            }

            return true;
        }

        if ($outputType === OutputClassifier::PAYTOPUBKEYHASH) {
            $pubKeyHash = $return[0];
            $results[] = $pubKeyHash;
            $this->requiredSigs = 1;

            if ($pubKeyHash->getBinary() === $key->getPublicKey()->getBinary()) {
                $this->signatures[0] = $this->calculateSignature($key, $scriptPubKey, $sigVersion);
            }

            return true;
        }

        if ($outputType === OutputClassifier::MULTISIG) {

            array_walk($this->publicKeys, function (PublicKeyInterface $publicKey) use (&$results) {
                $results[] = $publicKey->getBuffer();
            });
            $this->requiredSigs = count($this->publicKeys);

            foreach ($this->publicKeys as $keyIdx => $publicKey) {
                if ($publicKey->getBinary() == $key->getPublicKey()->getBinary()) {
                    $this->signatures[$keyIdx] = $this->calculateSignature($key, $scriptPubKey, $sigVersion);
                } else {
                    return false;
                }
            }

            return true;
        }

        if ($outputType === OutputClassifier::WITNESS_V0_KEYHASH) {
            $pubKeyHash = $return[0];
            $results[] = $pubKeyHash;
            $this->requiredSigs = 1;

            if ($pubKeyHash->getBinary() === $key->getPublicKey()->getBinary()) {
                $script = ScriptFactory::sequence([Opcodes::OP_DUP, Opcodes::OP_HASH160, $pubKeyHash, Opcodes::OP_EQUALVERIFY, Opcodes::OP_CHECKSIG]);
                $this->signatures[0] = $this->calculateSignature($key, $script, 1);
            }

            return true;
        }

        if ($outputType === OutputClassifier::WITNESS_V0_SCRIPTHASH) {
            $scriptHash = $return[0];
            $results[] = $scriptHash;

            return true;
        }

        return false;
    }

    /**
     * @param PrivateKeyInterface $key
     * @param ScriptInterface $scriptPubKey
     * @param ScriptInterface|null $redeemScript
     * @param ScriptInterface|null $witnessScript
     * @return bool
     */
    public function sign(PrivateKeyInterface $key, ScriptInterface $scriptPubKey, ScriptInterface $redeemScript = null, ScriptInterface $witnessScript = null)
    {
        /** @var BufferInterface[] $return */
        $type = null;
        $return = [];
        $solved = $this->doSignature($key, $scriptPubKey, $type, $return, 0);

        if ($solved && $type === OutputClassifier::PAYTOSCRIPTHASH) {
            $redeemScriptBuffer = $return[0];

            if (!$redeemScript instanceof ScriptInterface) {
                throw new \InvalidArgumentException('Must provide redeem script for P2SH');
            }

            if ($redeemScript->getScriptHash()->getBinary() === $redeemScriptBuffer->getBinary()) {
                throw new \InvalidArgumentException("Incorrect redeem script - hash doesn't match");
            }

            $results = []; // ???
            $solved = $solved && $this->doSignature($key, $redeemScript, $type, $results, 0) && $type !== OutputClassifier::PAYTOSCRIPTHASH;
        }

        if ($solved && $type === OutputClassifier::WITNESS_V0_KEYHASH) {
            $pubKeyHash = $return[0];
            $witnessScript = ScriptFactory::sequence([Opcodes::OP_DUP, Opcodes::OP_HASH160, $pubKeyHash, Opcodes::OP_EQUALVERIFY, Opcodes::OP_CHECKSIG]);
            $subType = null;
            $subResults = [];
            $solved = $solved && $this->doSignature($key, $witnessScript, $subType, $subResults, 1);

        } else if ($solved && $type === OutputClassifier::WITNESS_V0_SCRIPTHASH) {
            $scriptHash = $return[0];

            if (!$witnessScript instanceof ScriptInterface) {
                throw new \InvalidArgumentException('Must provide witness script for witness v0 scripthash');
            }

            if (Hash::sha256($witnessScript->getBuffer())->getBinary() === $scriptHash->getBinary()) {
                throw new \InvalidArgumentException("Incorrect witness script - hash doesn't match");
            }

            $subType = null;
            $subResults = [];
            $solved = $solved && $this->doSignature($key, $witnessScript, $subType, $subResults, 1)
                && $subType !== OutputClassifier::PAYTOSCRIPTHASH
                && $subType !== OutputClassifier::WITNESS_V0_SCRIPTHASH
                && $subType !== OutputClassifier::WITNESS_V0_KEYHASH;

        }

        return $solved;
    }

    public function serializeSignatures()
    {
        /** @var BufferInterface[] $return */
        $return = [];
        $outputType = (new OutputClassifier($this->txOut->getScript()))->classify($return);

        if ($outputType === OutputClassifier::UNKNOWN) {
            throw new \RuntimeException('Cannot sign unknown script type');
        }

        if ($outputType === OutputClassifier::PAYTOPUBKEY && $this->isFullySigned()) {
            $scriptSig = ScriptFactory::sequence([$this->signatures[0]]);
            $witness = null;

            return true;
        }

        if ($outputType === OutputClassifier::PAYTOPUBKEYHASH && $this->isFullySigned()) {
            $scriptSig = ScriptFactory::sequence([$this->signatures[0], $this->publicKeys[0]]);
            $witness = null;
            return true;
        }

        if ($outputType === OutputClassifier::MULTISIG) {

            foreach ($this->publicKeys as $keyIdx => $publicKey) {
                if ($publicKey->getBinary() == $key->getPublicKey()->getBinary()) {
                    $this->signatures[$keyIdx] = $this->calculateSignature($key, $scriptPubKey, $sigVersion);
                } else {
                    return false;
                }
            }

            return true;
        }

        if ($outputType === OutputClassifier::WITNESS_V0_KEYHASH) {
            $pubKeyHash = $return[0];
            $results[] = $pubKeyHash;
            $this->requiredSigs = 1;

            if ($pubKeyHash->getBinary() === $key->getPublicKey()->getBinary()) {
                $script = ScriptFactory::sequence([Opcodes::OP_DUP, Opcodes::OP_HASH160, $pubKeyHash, Opcodes::OP_EQUALVERIFY, Opcodes::OP_CHECKSIG]);
                $this->signatures[0] = $this->calculateSignature($key, $script, 1);
            }

            return true;
        }

        if ($outputType === OutputClassifier::WITNESS_V0_SCRIPTHASH) {
            $scriptHash = $return[0];
            $results[] = $scriptHash;

            return true;
        }

        return false;
    }
}