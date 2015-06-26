<?php
/**
 * Created by PhpStorm.
 * User: thomas
 * Date: 23/06/15
 * Time: 16:17
 */

namespace BitWasp\Bitcoin\Network\P2P;

use BitWasp\Bitcoin\Network\BlockLocator;
use BitWasp\Bitcoin\Network\MessageFactory;
use BitWasp\Bitcoin\Chain\Headerchain;
use BitWasp\Bitcoin\Chain\Blockchain;
use BitWasp\Bitcoin\Network\Messages\Headers;
use BitWasp\Bitcoin\Network\Messages\Inv;
use BitWasp\Bitcoin\Network\Messages\Ping;
use BitWasp\Bitcoin\Network\Structure\NetworkAddress;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\SocketClient\Connector;

class Node
{
    /**
     * @var Headerchain|Blockchain
     */
    private $chain;

    /**
     * @var Connector
     */
    private $connector;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var MessageFactory
     */
    private $msgs;

    /**
     * @var Peer[]
     */
    private $peers;

    /**
     * @var BlockLocator
     */
    private $locator;

    /**
     * @param NetworkAddress $local
     * @param $chain
     * @param Connector $connector
     * @param MessageFactory $msgs
     * @param LoopInterface $loop
     */
    public function __construct(NetworkAddress $local, $chain, Connector $connector, MessageFactory $msgs, LoopInterface $loop)
    {
        $this->version = 70002;
        $this->local = $local;
        $this->chain = $chain;
        $this->connector = $connector;
        $this->loop = $loop;
        $this->msgs = $msgs;
        $this->locator = new BlockLocator();
    }

    /**
     * @return Blockchain|Headerchain
     */
    public function chain()
    {
        return $this->chain;
    }

    /**
     * @param bool|false $all
     * @return array
     */
    public function locator($all = false)
    {
        return $this->locator->hashes($this->chain->currentHeight(), $this->chain->index(), $all);
    }

    /**
     * @param NetworkAddress $remote
     * @return \React\Promise\Promise
     */
    public function connect(NetworkAddress $remote)
    {
        $deferred = new Deferred();

        $peer = new Peer($remote, $this->local, $this->connector, $this->msgs, $this->loop);
        $peer->on('ready', function (Peer $peer) use ($deferred) {
            $deferred->resolve($peer);
        });

        $loop = $this->loop;
        $peer->connect()->then(function (Peer $peer) use ($loop) {
            echo "peer connected\n";
            $this->peers[] = $peer;
            $peer->getaddr();

            $peer->on('inv', function (Peer $peer, Inv $vInv) {
                $this->processInv($peer, $vInv);
            });
        });

        return $deferred->promise();
    }

    /**
     * @param Peer $peer
     * @param Inv $vInv
     */
    public function processInv(Peer $peer, Inv $vInv)
    {
        $vDontHave = [];
        foreach ($vInv->getItems() as $vector) {
            $key = $vector->getHash()->getHex();
            if ($vector->isBlock()) {
                if (!$this->chain->index()->height()->contains($key)) {
                    $vDontHave[] = $vector;
                }
            } elseif ($vector->isTx()) {
            } elseif ($vector->isFilteredBlock()) {
                if (!$this->chain->index()->height()->contains($key)) {
                    $vDontHave[] = $vector;
                }
            }
        }

        if (count($vDontHave) > 0) {
            $peer->getdata($vDontHave);
        }
    }
}