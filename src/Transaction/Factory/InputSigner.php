<?php

namespace BitWasp\Bitcoin\Transaction\Factory;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcSerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PublicKeyInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Key\PublicKeySerializerInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Signature\DerSignatureSerializerInterface;
use BitWasp\Bitcoin\Crypto\Random\Rfc6979;
use BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\Classifier\OutputData;
use BitWasp\Bitcoin\Script\Interpreter\BitcoinCashChecker;
use BitWasp\Bitcoin\Script\Interpreter\Checker;
use BitWasp\Bitcoin\Script\Interpreter\Interpreter;
use BitWasp\Bitcoin\Script\Interpreter\Stack;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\Parser\Operation;
use BitWasp\Bitcoin\Script\Path\BranchInterpreter;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInfo\Multisig;
use BitWasp\Bitcoin\Script\ScriptInfo\PayToPubkey;
use BitWasp\Bitcoin\Script\ScriptInfo\PayToPubkeyHash;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Script\ScriptType;
use BitWasp\Bitcoin\Script\ScriptWitness;
use BitWasp\Bitcoin\Script\ScriptWitnessInterface;
use BitWasp\Bitcoin\Serializer\Signature\TransactionSignatureSerializer;
use BitWasp\Bitcoin\Signature\TransactionSignature;
use BitWasp\Bitcoin\Signature\TransactionSignatureInterface;
use BitWasp\Bitcoin\Transaction\SignatureHash\SigHash;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;

class InputSigner implements InputSignerInterface
{
    /**
     * @var array
     */
    protected static $canSign = [
        ScriptType::P2PKH,
        ScriptType::P2PK,
        ScriptType::MULTISIG
    ];

    /**
     * @var array
     */
    protected static $validP2sh = [
        ScriptType::P2WKH,
        ScriptType::P2WSH,
        ScriptType::P2PKH,
        ScriptType::P2PK,
        ScriptType::MULTISIG
    ];

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var OutputData $scriptPubKey
     */
    private $scriptPubKey;

    /**
     * @var OutputData $redeemScript
     */
    private $redeemScript;

    /**
     * @var OutputData $witnessScript
     */
    private $witnessScript;

    /**
     * @var OutputData
     */
    private $signScript;

    /**
     * @var bool
     */
    private $tolerateInvalidPublicKey = false;

    /**
     * @var bool
     */
    private $redeemBitcoinCash = false;

    /**
     * @var bool
     */
    private $allowComplexScripts = false;

    /**
     * @var SignData
     */
    private $signData;

    /**
     * @var int
     */
    private $sigVersion;

    /**
     * @var int
     */
    private $flags;

    /**
     * @var OutputData $witnessKeyHash
     */
    private $witnessKeyHash;

    /**
     * @var TransactionInterface
     */
    private $tx;

    /**
     * @var int
     */
    private $nInput;

    /**
     * @var TransactionOutputInterface
     */
    private $txOut;

    /**
     * @var Interpreter
     */
    private $interpreter;

    /**
     * @var Checker
     */
    private $signatureChecker;

    /**
     * @var TransactionSignatureSerializer
     */
    private $txSigSerializer;

    /**
     * @var PublicKeySerializerInterface
     */
    private $pubKeySerializer;

    /**
     * @var Conditional[]|Checksig[]
     */
    private $steps = [];

    /**
     * InputSigner constructor.
     *
     * Note, the implementation of this class is considered internal
     * and only the methods exposed on InputSignerInterface should
     * be depended on to avoid BC breaks.
     *
     * The only recommended way to produce this class is using Signer::input()
     *
     * @param EcAdapterInterface $ecAdapter
     * @param TransactionInterface $tx
     * @param int $nInput
     * @param TransactionOutputInterface $txOut
     * @param SignData $signData
     * @param TransactionSignatureSerializer|null $sigSerializer
     * @param PublicKeySerializerInterface|null $pubKeySerializer
     */
    public function __construct(EcAdapterInterface $ecAdapter, TransactionInterface $tx, $nInput, TransactionOutputInterface $txOut, SignData $signData, TransactionSignatureSerializer $sigSerializer = null, PublicKeySerializerInterface $pubKeySerializer = null)
    {
        $this->ecAdapter = $ecAdapter;
        $this->tx = $tx;
        $this->nInput = $nInput;
        $this->txOut = $txOut;
        $this->signData = $signData;

        $this->txSigSerializer = $sigSerializer ?: new TransactionSignatureSerializer(EcSerializer::getSerializer(DerSignatureSerializerInterface::class, true, $ecAdapter));
        $this->pubKeySerializer = $pubKeySerializer ?: EcSerializer::getSerializer(PublicKeySerializerInterface::class, true, $ecAdapter);
        $this->interpreter = new Interpreter($this->ecAdapter);
    }

    /**
     * @return InputSigner
     */
    public function extract()
    {
        $defaultFlags = Interpreter::VERIFY_DERSIG | Interpreter::VERIFY_P2SH | Interpreter::VERIFY_CHECKLOCKTIMEVERIFY | Interpreter::VERIFY_CHECKSEQUENCEVERIFY | Interpreter::VERIFY_WITNESS;
        $checker = new Checker($this->ecAdapter, $this->tx, $this->nInput, $this->txOut->getValue(), $this->txSigSerializer, $this->pubKeySerializer);

        if ($this->redeemBitcoinCash) {
            // unset VERIFY_WITNESS default
            $defaultFlags = $defaultFlags & (~Interpreter::VERIFY_WITNESS);

            if ($this->signData->hasSignaturePolicy()) {
                if ($this->signData->getSignaturePolicy() & Interpreter::VERIFY_WITNESS) {
                    throw new \RuntimeException("VERIFY_WITNESS is not possible for bitcoin cash");
                }
            }

            $checker = new BitcoinCashChecker($this->ecAdapter, $this->tx, $this->nInput, $this->txOut->getValue(), $this->txSigSerializer, $this->pubKeySerializer);
        }

        $this->flags = $this->signData->hasSignaturePolicy() ? $this->signData->getSignaturePolicy() : $defaultFlags;
        $this->signatureChecker = $checker;

        $witnesses = $this->tx->getWitnesses();
        $witness = array_key_exists($this->nInput, $witnesses) ? $witnesses[$this->nInput]->all() : [];

        return $this->solve(
            $this->signData,
            $this->txOut->getScript(),
            $this->tx->getInput($this->nInput)->getScript(),
            $witness
        );
    }

    /**
     * @param bool $setting
     * @return $this
     */
    public function tolerateInvalidPublicKey($setting)
    {
        $this->tolerateInvalidPublicKey = (bool) $setting;
        return $this;
    }

    /**
     * @param bool $setting
     * @return $this
     */
    public function redeemBitcoinCash($setting)
    {
        $this->redeemBitcoinCash = (bool) $setting;
        return $this;
    }

    /**
     * @param bool $setting
     * @return $this
     */
    public function allowComplexScripts($setting)
    {
        $this->allowComplexScripts = (bool) $setting;
        return $this;
    }

    /**
     * @param BufferInterface $vchPubKey
     * @return PublicKeyInterface|null
     * @throws \Exception
     */
    protected function parseStepPublicKey(BufferInterface $vchPubKey)
    {
        try {
            return $this->pubKeySerializer->parse($vchPubKey);
        } catch (\Exception $e) {
            if ($this->tolerateInvalidPublicKey) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A snippet from OP_CHECKMULTISIG - links keys to signatures
     *
     * @param ScriptInterface $script
     * @param BufferInterface[] $signatures
     * @param BufferInterface[] $publicKeys
     * @param int $sigVersion
     * @return \SplObjectStorage
     */
    private function sortMultisigs(ScriptInterface $script, array $signatures, array $publicKeys, $sigVersion)
    {
        $sigCount = count($signatures);
        $keyCount = count($publicKeys);
        $ikey = $isig = 0;
        $fSuccess = true;
        $result = new \SplObjectStorage;

        while ($fSuccess && $sigCount > 0) {
            // Fetch the signature and public key
            $sig = $signatures[$isig];
            $pubkey = $publicKeys[$ikey];

            if ($this->signatureChecker->checkSig($script, $sig, $pubkey, $sigVersion, $this->flags)) {
                $result[$pubkey] = $sig;
                $isig++;
                $sigCount--;
            }

            $ikey++;
            $keyCount--;

            // If there are more signatures left than keys left,
            // then too many signatures have failed. Exit early,
            // without checking any further signatures.
            if ($sigCount > $keyCount) {
                $fSuccess = false;
            }
        }

        return $result;
    }

    /**
     * @param ScriptInterface $script
     * @return \BitWasp\Buffertools\BufferInterface[]
     */
    private function evalPushOnly(ScriptInterface $script)
    {
        $stack = new Stack();
        $this->interpreter->evaluate($script, $stack, SigHash::V0, $this->flags | Interpreter::VERIFY_SIGPUSHONLY, $this->signatureChecker);
        return $stack->all();
    }

    /**
     * Create a script consisting only of push-data operations.
     * Suitable for a scriptSig.
     *
     * @param BufferInterface[] $buffers
     * @return ScriptInterface
     */
    private function pushAll(array $buffers)
    {
        return ScriptFactory::sequence(array_map(function ($buffer) {
            if (!($buffer instanceof BufferInterface)) {
                throw new \RuntimeException('Script contained a non-push opcode');
            }

            $size = $buffer->getSize();
            if ($size === 0) {
                return Opcodes::OP_0;
            }

            $first = ord($buffer->getBinary());
            if ($size === 1 && $first >= 1 && $first <= 16) {
                return \BitWasp\Bitcoin\Script\encodeOpN($first);
            } else {
                return $buffer;
            }
        }, $buffers));
    }

    /**
     * Verify a scriptSig / scriptWitness against a scriptPubKey.
     * Useful for checking the outcome of certain things, like hash locks (p2sh)
     *
     * @param int $flags
     * @param ScriptInterface $scriptSig
     * @param ScriptInterface $scriptPubKey
     * @param ScriptWitnessInterface|null $scriptWitness
     * @return bool
     */
    private function verifySolution($flags, ScriptInterface $scriptSig, ScriptInterface $scriptPubKey, ScriptWitnessInterface $scriptWitness = null)
    {
        return $this->interpreter->verify($scriptSig, $scriptPubKey, $flags, $this->signatureChecker, $scriptWitness);
    }

    /**
     * Evaluates a scriptPubKey against the provided chunks.
     *
     * @param ScriptInterface $scriptPubKey
     * @param array $chunks
     * @param int $sigVersion
     * @return bool
     */
    private function evaluateSolution(ScriptInterface $scriptPubKey, array $chunks, $sigVersion)
    {
        $stack = new Stack($chunks);
        if (!$this->interpreter->evaluate($scriptPubKey, $stack, $sigVersion, $this->flags, $this->signatureChecker)) {
            return false;
        }

        if ($stack->isEmpty()) {
            return false;
        }

        if (false === $this->interpreter->castToBool($stack[-1])) {
            return false;
        }

        return true;
    }

    /**
     * @param array $decoded
     * @return null|Multisig|PayToPubkey|PayToPubkeyHash
     */
    private function classifySignStep(array $decoded, &$solution = null)
    {
        try {
            $details = Multisig::fromDecodedScript($decoded, $this->pubKeySerializer, true);
            $solution = $details->getKeyBuffers();
            return $details;
        } catch (\Exception $e) {

        }

        try {
            $details = PayToPubkey::fromDecodedScript($decoded, true);
            $solution = $details->getKeyBuffer();
            return $details;
        } catch (\Exception $e) {

        }

        try {
            $details = PayToPubkeyHash::fromDecodedScript($decoded, true);
            $solution = $details->getPubKeyHash();
            return $details;
        } catch (\Exception $e) {

        }

        return null;
    }

    /**
     * @param ScriptInterface $script
     * @return Checksig[]
     */
    public function parseSequence(ScriptInterface $script)
    {
        $decoded = $script->getScriptParser()->decode();

        $j = 0;
        $l = count($decoded);
        $result = [];
        while ($j < $l) {
            $step = null;
            $slice = null;

            // increment the $last, and break if it's valid
            for ($i = 0; $i < ($l - $j) + 1; $i++) {
                $slice = array_slice($decoded, $j, $i);
                $step = $this->classifySignStep($slice, $solution);
                if ($step !== null) {
                    break;
                }
            }

            if (null === $step) {
                throw new \RuntimeException("Invalid script");
            } else {
                $j += $i;
                $result[] = new Checksig($step);
            }
        }

        return $result;
    }

    /**
     * @param Operation $operation
     * @param Stack $mainStack
     * @param bool[] $pathData
     * @return Conditional
     */
    public function extractConditionalOp(Operation $operation, Stack $mainStack, array &$pathData)
    {
        $opValue = null;

        if (!$mainStack->isEmpty()) {
            if (count($pathData) === 0) {
                throw new \RuntimeException("Extracted conditional op (including mainstack) without corresponding element in path data");
            }

            $opValue = $this->interpreter->castToBool($mainStack->pop());
            $dataValue = array_shift($pathData);

            if ($opValue !== $dataValue) {
                throw new \RuntimeException("Current stack doesn't follow branch path");
            }

        } else {
            if (count($pathData) === 0) {
                throw new \RuntimeException("Extracted conditional op without corresponding element in path data");
            }

            $opValue = array_shift($pathData);
        }

        $conditional = new Conditional($operation->getOp());

        if ($opValue !== null) {
            if (!is_bool($opValue)) {
                throw new \RuntimeException("Sanity check, path value (likely from pathData) was not a bool");
            }

            $conditional->setValue($opValue);
        }

        return $conditional;
    }

    public function extractScript(OutputData $solution, array $sigChunks, SignData $signData)
    {
        $logicInterpreter = new BranchInterpreter();
        $tree = $logicInterpreter->getScriptTree($solution->getScript());

        if ($tree->hasMultipleBranches()) {
            $logicalPath = $signData->getLogicalPath();
            // we need a function like findWitnessScript to 'check'
            // partial signatures against _our_ path
        } else {
            $logicalPath = [];
        }

        $branch = $tree->getBranchByDesc($logicalPath);
        $segments = $branch->getSegments();

        $stack = new Stack();
        foreach (array_reverse($sigChunks) as $chunk) {
            $stack->push($chunk);
        }

        $pathCopy = $logicalPath;
        $steps = [];
        foreach ($segments as $segment) {
            if ($segment->isLoneLogicalOp()) {
                $op = $segment[0];
                switch ($op->getOp()) {
                    case Opcodes::OP_IF:
                    case Opcodes::OP_NOTIF:
                        $steps[] = $this->extractConditionalOp($op, $stack, $pathCopy);
                        break;
                    default:
                        throw new \RuntimeException("Coding error!");
                }
            } else {
                $segmentScript = $segment->makeScript();
                $templateTypes = $this->parseSequence($segmentScript);

                foreach ($templateTypes as $stepData) {
                    $this->extractFromValues($solution->getScript(), $stepData, $sigChunks, $this->sigVersion);
                    $steps[] = $stepData;
                }
            }
        }

        $this->steps = $steps;
    }

    /**
     * This function is strictly for $canSign types.
     * It will extract signatures/publicKeys when given $outputData, and $stack.
     * $stack is the result of decompiling a scriptSig, or taking the witness data.
     *
     * @param ScriptInterface $script
     * @param Checksig $checksig
     * @param array $stack
     * @param int $sigVersion
     * @return string
     */
    public function extractFromValues(ScriptInterface $script, Checksig $checksig, array $stack, $sigVersion)
    {
        $size = count($stack);

        if ($checksig->getType() === ScriptType::P2PKH) {
            if ($size === 2) {
                if (!$this->evaluateSolution($script, $stack, $sigVersion)) {
                    throw new \RuntimeException('Existing signatures are invalid!');
                }
                $checksig->setSignature(0, $this->txSigSerializer->parse($stack[0]));
                $checksig->setKey(0, $this->parseStepPublicKey($stack[1]));
            }
        } else if ($checksig->getType() === ScriptType::P2PK) {
            if ($size === 1) {
                if (!$this->evaluateSolution($script, $stack, $sigVersion)) {
                    throw new \RuntimeException('Existing signatures are invalid!');
                }
                $checksig->setSignature(0, $this->txSigSerializer->parse($stack[0]));
            }
            $checksig->setKey(0, $this->parseStepPublicKey($checksig->getSolution()));
        } else if (ScriptType::MULTISIG === $checksig->getType()) {
            /** @var Multisig $info */
            $info = $checksig->getInfo();
            $keyBuffers = $info->getKeyBuffers();
            foreach ($keyBuffers as $idx => $keyBuf) {
                $checksig->setKey($idx, $this->parseStepPublicKey($keyBuf));
            }

            if ($size > 1) {
                // Check signatures irrespective of scriptSig size, primes Checker cache, and need info
                $check = $this->evaluateSolution($script, $stack, $sigVersion);
                $sigBufs = array_slice($stack, 1, $size - 1);
                $sigBufCount = count($sigBufs);

                // If we seem to have all signatures but fail evaluation, abort
                if ($sigBufCount === $checksig->getRequiredSigs() && !$check) {
                    throw new \RuntimeException('Existing signatures are invalid!');
                }

                $keyToSigMap = $this->sortMultiSigs($script, $sigBufs, $keyBuffers, $sigVersion);

                // Here we learn if any signatures were invalid, it won't be in the map.
                if ($sigBufCount !== count($keyToSigMap)) {
                    throw new \RuntimeException('Existing signatures are invalid!');
                }

                foreach ($keyBuffers as $idx => $key) {
                    if (isset($keyToSigMap[$key])) {
                        $checksig->setSignature($idx, $this->txSigSerializer->parse($keyToSigMap[$key]));
                    }
                }
            }
        } else {
            throw new \RuntimeException('Unsupported output type passed to extractFromValues');
        }
    }

    /**
     * Checks $chunks (a decompiled scriptSig) for it's last element,
     * or defers to SignData. If both are provided, it checks the
     * value from $chunks against SignData.
     *
     * @param BufferInterface[] $chunks
     * @param SignData $signData
     * @return ScriptInterface
     */
    private function findRedeemScript(array $chunks, SignData $signData)
    {
        if (count($chunks) > 0) {
            $redeemScript = new Script($chunks[count($chunks) - 1]);
            if ($signData->hasRedeemScript()) {
                if (!$redeemScript->equals($signData->getRedeemScript())) {
                    throw new \RuntimeException('Extracted redeemScript did not match sign data');
                }
            }
        } else {
            if (!$signData->hasRedeemScript()) {
                throw new \RuntimeException('Redeem script not provided in sign data or scriptSig');
            }
            $redeemScript = $signData->getRedeemScript();
        }

        return $redeemScript;
    }

    /**
     * Checks $witness (a witness structure) for it's last element,
     * or defers to SignData. If both are provided, it checks the
     * value from $chunks against SignData.
     *
     * @param BufferInterface[] $witness
     * @param SignData $signData
     * @return ScriptInterface
     */
    private function findWitnessScript(array $witness, SignData $signData)
    {
        if (count($witness) > 0) {
            $witnessScript = new Script($witness[count($witness) - 1]);
            if ($signData->hasWitnessScript()) {
                if (!$witnessScript->equals($signData->getWitnessScript())) {
                    throw new \RuntimeException('Extracted witnessScript did not match sign data');
                }
            }
        } else {
            if (!$signData->hasWitnessScript()) {
                throw new \RuntimeException('Witness script not provided in sign data or witness');
            }
            $witnessScript = $signData->getWitnessScript();
        }

        return $witnessScript;
    }

    /**
     * Needs to be called before using the instance. By `extract`.
     *
     * It ensures that violating the following prevents instance creation
     *  - the scriptPubKey can be directly signed, or leads to P2SH/P2WSH/P2WKH
     *  - the P2SH script covers signable types and P2WSH/P2WKH
     *  - the witnessScript covers signable types only
     *
     * @param SignData $signData
     * @param ScriptInterface $scriptPubKey
     * @param ScriptInterface $scriptSig
     * @param BufferInterface[] $witness
     * @return $this
     */
    private function solve(SignData $signData, ScriptInterface $scriptPubKey, ScriptInterface $scriptSig, array $witness)
    {
        $classifier = new OutputClassifier();
        $sigVersion = SigHash::V0;
        $solution = $this->scriptPubKey = $classifier->decode($scriptPubKey);

        if (!$this->allowComplexScripts) {
            if ($solution->getType() !== ScriptType::P2SH && !in_array($solution->getType(), self::$validP2sh)) {
                throw new \RuntimeException('scriptPubKey not supported');
            }
        }

        $sigChunks = $this->evalPushOnly($scriptSig);

        if ($solution->getType() === ScriptType::P2SH) {
            $redeemScript = $this->findRedeemScript($sigChunks, $signData);
            if (!$this->verifySolution(Interpreter::VERIFY_SIGPUSHONLY, ScriptFactory::sequence([$redeemScript->getBuffer()]), $solution->getScript())) {
                throw new \RuntimeException('Redeem script fails to solve pay-to-script-hash');
            }

            $solution = $this->redeemScript = $classifier->decode($redeemScript);
            if (!$this->allowComplexScripts) {
                if (!in_array($solution->getType(), self::$validP2sh)) {
                    throw new \RuntimeException('Unsupported pay-to-script-hash script');
                }
            }

            $sigChunks = array_slice($sigChunks, 0, -1);
        }

        if ($solution->getType() === ScriptType::P2WKH) {
            $sigVersion = SigHash::V1;
            $solution = $this->witnessKeyHash = $classifier->decode(ScriptFactory::scriptPubKey()->payToPubKeyHash($solution->getSolution()));
            $sigChunks = $witness;
        } else if ($solution->getType() === ScriptType::P2WSH) {
            $sigVersion = SigHash::V1;
            $witnessScript = $this->findWitnessScript($witness, $signData);

            // Essentially all the reference implementation does
            if (!$witnessScript->getWitnessScriptHash()->equals($solution->getSolution())) {
                throw new \RuntimeException('Witness script fails to solve witness-script-hash');
            }

            $solution = $this->witnessScript = $classifier->decode($witnessScript);
            if (!$this->allowComplexScripts) {
                if (!in_array($this->witnessScript->getType(), self::$canSign)) {
                    throw new \RuntimeException('Unsupported witness-script-hash script');
                }
            }

            $sigChunks = array_slice($witness, 0, -1);
        }

        $this->sigVersion = $sigVersion;
        $this->signScript = $solution;

        $this->extractScript($solution, $sigChunks, $signData);

        return $this;
    }

    /**
     * Pure function to produce a signature hash for a given $scriptCode, $sigHashType, $sigVersion.
     *
     * @param ScriptInterface $scriptCode
     * @param int $sigHashType
     * @param int $sigVersion
     * @return BufferInterface
     */
    public function calculateSigHashUnsafe(ScriptInterface $scriptCode, $sigHashType, $sigVersion)
    {
        if (!$this->signatureChecker->isDefinedHashtype($sigHashType)) {
            throw new \RuntimeException('Invalid sigHashType requested');
        }

        return $this->signatureChecker->getSigHash($scriptCode, $sigHashType, $sigVersion);
    }

    /**
     * Calculates the signature hash for the input for the given $sigHashType.
     *
     * @param int $sigHashType
     * @return BufferInterface
     */
    public function getSigHash($sigHashType)
    {
        return $this->calculateSigHashUnsafe($this->signScript->getScript(), $sigHashType, $this->sigVersion);
    }

    /**
     * Pure function to produce a signature for a given $key, $scriptCode, $sigHashType, $sigVersion.
     *
     * @param PrivateKeyInterface $key
     * @param ScriptInterface $scriptCode
     * @param int $sigHashType
     * @param int $sigVersion
     * @return TransactionSignatureInterface
     */
    private function calculateSignature(PrivateKeyInterface $key, ScriptInterface $scriptCode, $sigHashType, $sigVersion)
    {
        $hash = $this->calculateSigHashUnsafe($scriptCode, $sigHashType, $sigVersion);
        $ecSignature = $this->ecAdapter->sign($hash, $key, new Rfc6979($this->ecAdapter, $key, $hash, 'sha256'));
        return new TransactionSignature($this->ecAdapter, $ecSignature, $sigHashType);
    }

    /**
     * Returns whether all required signatures have been provided.
     *
     * @return bool
     */
    public function isFullySigned()
    {
        foreach ($this->steps as $step) {
            if ($step instanceof Conditional) {
                if (!$step->hasValue()) {
                    return false;
                }
            } else if ($step instanceof Checksig) {
                if (!$step->isFullySigned()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns the required number of signatures for this input.
     *
     * @return int
     */
    public function getRequiredSigs()
    {
        $count = 0;
        foreach ($this->steps as $step) {
            if ($step instanceof Checksig) {
                $count += $step->getRequiredSigs();
            }
        }
        return $count;
    }

    /**
     * Returns an array where the values are either null,
     * or a TransactionSignatureInterface.
     *
     * @return TransactionSignatureInterface[]
     */
    public function getSignatures()
    {
        return $this->steps[0]->getSignatures();
    }

    /**
     * Returns an array where the values are either null,
     * or a PublicKeyInterface.
     *
     * @return PublicKeyInterface[]
     */
    public function getPublicKeys()
    {
        return $this->steps[0]->getKeys();
    }

    /**
     * OutputData for the script to be signed (will be
     * equal to getScriptPubKey, or getRedeemScript, or
     * getWitnessScript.
     *
     * @return OutputData
     */
    public function getSignScript()
    {
        return $this->signScript;
    }

    /**
     * OutputData for the txOut script.
     *
     * @return OutputData
     */
    public function getScriptPubKey()
    {
        return $this->scriptPubKey;
    }

    /**
     * Returns OutputData for the P2SH redeemScript.
     *
     * @return OutputData
     */
    public function getRedeemScript()
    {
        if (null === $this->redeemScript) {
            throw new \RuntimeException("Input has no redeemScript, cannot call getRedeemScript");
        }

        return $this->redeemScript;
    }

    /**
     * Returns OutputData for the P2WSH witnessScript.
     *
     * @return OutputData
     */
    public function getWitnessScript()
    {
        if (null === $this->witnessScript) {
            throw new \RuntimeException("Input has no witnessScript, cannot call getWitnessScript");
        }

        return $this->witnessScript;
    }

    /**
     * Returns whether the scriptPubKey is P2SH.
     *
     * @return bool
     */
    public function isP2SH()
    {
        if ($this->scriptPubKey->getType() === ScriptType::P2SH && ($this->redeemScript instanceof OutputData)) {
            return true;
        }

        return false;
    }

    /**
     * Returns whether the scriptPubKey or redeemScript is P2WSH.
     *
     * @return bool
     */
    public function isP2WSH()
    {
        if ($this->redeemScript instanceof OutputData) {
            if ($this->redeemScript->getType() === ScriptType::P2WSH && ($this->witnessScript instanceof OutputData)) {
                return true;
            }
        }

        if ($this->scriptPubKey->getType() === ScriptType::P2WSH && ($this->witnessScript instanceof OutputData)) {
            return true;
        }

        return false;
    }

    /**
     * @param Checksig $checksig
     * @param PrivateKeyInterface $privateKey
     * @param int $sigHashType
     * @return $this
     */
    public function signChecksig(Checksig $checksig, PrivateKeyInterface $privateKey, $sigHashType = SigHash::ALL)
    {
        if ($checksig->isFullySigned()) {
            return $this;
        }

        if (SigHash::V1 === $this->sigVersion && !$privateKey->isCompressed()) {
            throw new \RuntimeException('Uncompressed keys are disallowed in segwit scripts - refusing to sign');
        }

        if ($checksig->getType() === ScriptType::P2PK) {
            if (!$this->pubKeySerializer->serialize($privateKey->getPublicKey())->equals($this->signScript->getSolution())) {
                throw new \RuntimeException('Signing with the wrong private key');
            }

            if (!$checksig->hasSignature(0)) {
                $signature = $this->calculateSignature($privateKey, $this->signScript->getScript(), $sigHashType, $this->sigVersion);
                $checksig->setSignature(0, $signature);
            }

        } else if ($checksig->getType() === ScriptType::P2PKH) {
            $publicKey = $privateKey->getPublicKey();
            if (!$publicKey->getPubKeyHash()->equals($checksig->getSolution())) {
                throw new \RuntimeException('Signing with the wrong private key');
            }

            if (!$checksig->hasSignature(0)) {
                $signature = $this->calculateSignature($privateKey, $this->signScript->getScript(), $sigHashType, $this->sigVersion);
                $checksig->setSignature(0, $signature);
            }

            if (!$checksig->hasKey(0)) {
                $checksig->setKey(0, $publicKey);
            }

        } else if ($this->signScript->getType() === ScriptType::MULTISIG) {
            $signed = false;
            foreach ($checksig->getKeys() as $keyIdx => $publicKey) {
                if (!$checksig->hasSignature($keyIdx)) {
                    if ($publicKey instanceof PublicKeyInterface && $privateKey->getPublicKey()->equals($publicKey)) {
                        $signature = $this->calculateSignature($privateKey, $this->signScript->getScript(), $sigHashType, $this->sigVersion);
                        $checksig->setSignature($keyIdx, $signature);
                        $signed = true;
                    }
                }
            }

            if (!$signed) {
                throw new \RuntimeException('Signing with the wrong private key');
            }
        } else {
            throw new \RuntimeException('Unexpected error - sign script had an unexpected type');
        }

        return $this;
    }

    /**
     * Sign the input using $key and $sigHashTypes
     *
     * @param PrivateKeyInterface $privateKey
     * @param int $sigHashType
     * @return $this
     */
    public function sign(PrivateKeyInterface $privateKey, $sigHashType = SigHash::ALL)
    {
        $step = $this->steps[0];
        return $this->signChecksig($step, $privateKey, $sigHashType);
    }

    /**
     * Verifies the input using $flags for script verification
     *
     * @param int $flags
     * @return bool
     */
    public function verify($flags = null)
    {
        $consensus = ScriptFactory::consensus();

        if ($flags === null) {
            $flags = $this->flags;
        }

        $flags |= Interpreter::VERIFY_P2SH;
        if (SigHash::V1 === $this->sigVersion) {
            $flags |= Interpreter::VERIFY_WITNESS;
        }

        $sig = $this->serializeSignatures();

        // Take serialized signatures, and use mutator to add this inputs sig data
        $mutator = TransactionFactory::mutate($this->tx);
        $mutator->inputsMutator()[$this->nInput]->script($sig->getScriptSig());

        if (SigHash::V1 === $this->sigVersion) {
            $witness = [];
            for ($i = 0, $j = count($this->tx->getInputs()); $i < $j; $i++) {
                if ($i === $this->nInput) {
                    $witness[] = $sig->getScriptWitness();
                } else {
                    $witness[] = new ScriptWitness([]);
                }
            }

            $mutator->witness($witness);
        }

        return $consensus->verify($mutator->done(), $this->txOut->getScript(), $flags, $this->nInput, $this->txOut->getValue());
    }

    /**
     * Produces the script stack that solves the $outputType
     *
     * @param string $outputType
     * @return BufferInterface[]
     */
    private function serializeSolution(Checksig $checksig)
    {
        $outputType = $checksig->getType();
        $result = [];

        if (ScriptType::P2PK === $outputType) {
            if ($checksig->hasSignature(0)) {
                $result = [$this->txSigSerializer->serialize($checksig->getSignature(0))];
            }
        } else if (ScriptType::P2PKH === $outputType) {
            if ($checksig->hasSignature(0) && $checksig->hasKey(0)) {
                $result = [$this->txSigSerializer->serialize($checksig->getSignature(0)), $this->pubKeySerializer->serialize($checksig->getKey(0))];
            }
        } else if (ScriptType::MULTISIG === $outputType) {
            $result[] = new Buffer();
            for ($i = 0, $nPubKeys = count($checksig->getKeys()); $i < $nPubKeys; $i++) {
                if ($checksig->hasSignature($i)) {
                    $result[] = $this->txSigSerializer->serialize($checksig->getSignature($i));
                }
            }
        } else {
            throw new \RuntimeException('Parameter 0 for serializeSolution was a non-standard input type');
        }

        return $result;
    }

    /**
     * @return array
     */
    private function serializeSteps()
    {
        $results = [];
        for ($i = 0, $n = count($this->steps); $i < $n; $i++) {
            $step = $this->steps[$i];
            if ($step instanceof Conditional) {
                if (!$step->hasValue()) {
                    break;
                }
                $results[] = [$step->getValue() ? new Buffer("\x01") : new Buffer("", 0)];
            } else if ($step instanceof Checksig) {
                if (count($step->getSignatures()) === 0) {
                    break;
                }

                $results[] = $this->serializeSolution($step);

                if (!$step->isFullySigned()) {
                    break;
                }
            }
        }

        $values = [];
        foreach (array_reverse($results) as $v) {
            foreach ($v as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Produces a SigValues instance containing the scriptSig & script witness
     *
     * @return SigValues
     */
    public function serializeSignatures()
    {
        static $emptyScript = null;
        static $emptyWitness = null;
        if (is_null($emptyScript) || is_null($emptyWitness)) {
            $emptyScript = new Script();
            $emptyWitness = new ScriptWitness([]);
        }

        $scriptSigChunks = [];
        $witness = [];
        if ($this->scriptPubKey->canSign()) {
            $scriptSigChunks = $this->serializeSteps();
        }

        $solution = $this->scriptPubKey;
        $p2sh = false;
        if ($solution->getType() === ScriptType::P2SH) {
            $p2sh = true;
            if ($this->redeemScript->canSign()) {
                $scriptSigChunks = $this->serializeSteps();
            }
            $solution = $this->redeemScript;
        }

        if ($solution->getType() === ScriptType::P2WKH) {
            $witness = $this->serializeSteps();
        } else if ($solution->getType() === ScriptType::P2WSH) {
            if ($this->witnessScript->canSign()) {
                $witness = $this->serializeSteps();
                $witness[] = $this->witnessScript->getScript()->getBuffer();
            }
        }

        if ($p2sh) {
            $scriptSigChunks[] = $this->redeemScript->getScript()->getBuffer();
        }

        return new SigValues($this->pushAll($scriptSigChunks), new ScriptWitness($witness));
    }
}
