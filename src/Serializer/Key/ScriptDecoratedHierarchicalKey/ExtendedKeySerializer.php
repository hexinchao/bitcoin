<?php


namespace BitWasp\Bitcoin\Serializer\Key\ScriptDecoratedHierarchicalKey;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyScriptDecorator;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Key\PublicKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\RawExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\RawKeyParams;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Buffertools\Exceptions\ParserOutOfRange;
use BitWasp\Buffertools\Parser;

class ExtendedKeySerializer
{

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var RawExtendedKeySerializer
     */
    private $rawSerializer;

    /**
     * @var GlobalPrefixConfig
     */
    private $config;

    /**
     * ExtendedKeyWithScriptSerializer constructor.
     * @param EcAdapterInterface $ecAdapter
     * @param GlobalPrefixConfig $hdPrefixConfig
     */
    public function __construct(EcAdapterInterface $ecAdapter, GlobalPrefixConfig $hdPrefixConfig)
    {
        $this->ecAdapter = $ecAdapter;
        $this->rawSerializer = new RawExtendedKeySerializer($ecAdapter);
        $this->config = $hdPrefixConfig;
    }

    /**
     * @param NetworkInterface $network
     * @param HierarchicalKeyScriptDecorator $key
     * @return BufferInterface
     * @throws \Exception
     */
    public function serialize(NetworkInterface $network, HierarchicalKeyScriptDecorator $key)
    {
        $scriptConfig = $this->config
            ->getNetworkConfig($network)
            ->getConfigForScriptType($key->getScriptDataFactory()->getScriptType())
        ;

        $hdKey = $key->getHdKey();
        if ($hdKey->isPrivate()) {
            $prefix = $scriptConfig->getPrivatePrefix();
            $keyData = new Buffer("\x00" . $hdKey->getPrivateKey()->getBinary());
        } else {
            $prefix = $scriptConfig->getPublicPrefix();
            $keyData = $hdKey->getPublicKey()->getBuffer();
        }

        return $this->rawSerializer->serialize(new RawKeyParams(
            $prefix,
            $hdKey->getDepth(),
            $hdKey->getFingerprint(),
            $hdKey->getSequence(),
            $hdKey->getChainCode(),
            $keyData
        ));
    }

    /**
     * @param NetworkInterface $network
     * @param Parser $parser
     * @return HierarchicalKeyScriptDecorator
     * @throws ParserOutOfRange
     * @throws \Exception
     */
    public function fromParser(NetworkInterface $network, Parser $parser)
    {
        $params = $this->rawSerializer->fromParser($parser);
        $scriptConfig = $this->config
            ->getNetworkConfig($network)
            ->getConfigForPrefix($params->getPrefix())
        ;

        if ($params->getPrefix() === $scriptConfig->getPrivatePrefix()) {
            $key = PrivateKeyFactory::fromHex($params->getKeyData()->slice(1), true, $this->ecAdapter);
        } else {
            $key = PublicKeyFactory::fromHex($params->getKeyData(), $this->ecAdapter);
        }

        return new HierarchicalKeyScriptDecorator(
            $scriptConfig->getScriptDataFactory(),
            new HierarchicalKey(
                $this->ecAdapter,
                $params->getDepth(),
                $params->getParentFingerprint(),
                $params->getSequence(),
                $params->getChainCode(),
                $key
            )
        );
    }

    /**
     * @param NetworkInterface $network
     * @param BufferInterface $buffer
     * @return HierarchicalKeyScriptDecorator
     * @throws ParserOutOfRange
     */
    public function parse(NetworkInterface $network, BufferInterface $buffer)
    {
        return $this->fromParser($network, new Parser($buffer));
    }
}
