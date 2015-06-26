<?php

namespace BitWasp\Bitcoin\Test\Network\Messages;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Network\MessageFactory;
use BitWasp\Bitcoin\Tests\AbstractTestCase;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\Parser;

class GetHeadersTest extends AbstractTestCase
{
    public function testNetworkSerializer()
    {
        $net = Bitcoin::getDefaultNetwork();
        $factory = new MessageFactory($net, new Random());

        $getheaders = $factory->getheaders(
            '1',
            [
                Buffer::hex('4141414141414141414141414141414141414141414141414141414141414141')
            ]
        );

        $serialized = $getheaders->getNetworkMessage()->getBuffer();
        $parsed = $factory->parse(new Parser($serialized))->getPayload();

        $this->assertEquals($getheaders, $parsed);
    }
}