<?php

namespace BitWasp\Bitcoin\Serializer\Transaction;

use BitWasp\Bitcoin\Serializer\Types;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Buffertools\Buffertools;
use BitWasp\Buffertools\Parser;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Transaction\TransactionInput;
use BitWasp\Bitcoin\Transaction\TransactionInputInterface;
use BitWasp\Buffertools\Template;

class TransactionInputSerializer
{
    /**
     * @var OutPointSerializer
     */
    private $outpointSerializer;

    /**
     * @var \BitWasp\Buffertools\Template
     */
    private $template;

    /**
     * TransactionInputSerializer constructor.
     * @param OutPointSerializerInterface $outPointSerializer
     */
    public function __construct(OutPointSerializerInterface $outPointSerializer)
    {
        $this->outpointSerializer = $outPointSerializer;
        $this->template = new Template([
            Types::varstring(),
            Types::uint32le()
        ]);
    }

    /**
     * @param TransactionInputInterface $input
     * @return BufferInterface
     */
    public function serialize(TransactionInputInterface $input)
    {
        return Buffertools::concat(
            $this->outpointSerializer->serialize($input->getOutPoint()),
            $this->template->write([
                $input->getScript()->getBuffer(),
                $input->getSequence()
            ])
        );
    }

    /**
     * @param Parser $parser
     * @return TransactionInput
     * @throws \BitWasp\Buffertools\Exceptions\ParserOutOfRange
     */
    public function fromParser(Parser $parser)
    {
        $outpoint = $this->outpointSerializer->fromParser($parser);
        list ($scriptBuf, $sequence) = $this->template->parse($parser);

        return new TransactionInput($outpoint, new Script($scriptBuf), $sequence);
    }

    /**
     * @param BufferInterface|string $string
     * @return TransactionInput
     * @throws \BitWasp\Buffertools\Exceptions\ParserOutOfRange
     */
    public function parse($string)
    {
        return $this->fromParser(new Parser($string));
    }
}
