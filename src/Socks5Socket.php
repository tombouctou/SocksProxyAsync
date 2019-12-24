<?php

namespace SocksProxyAsync;

/**
 * Class which manages native socket as socks5-connected socket
 * This class works only with SOCKS v5, supports only basic
 * authorization - without login:password
 * */
class Socks5Socket
{
    /**
     * Native socket
     * @var resource
     * */
    protected $socksSocket = null;
    /**
     * Domain name, not IP address
     * @var string
     * */
    protected $host;
    /**
     * @var int
     * */
    protected $port;
    /**
     * @var Proxy
     * */
    protected $proxy;
    /**
     * @var int
     * */
    protected $timeoutSeconds;


    /**
     * @param Proxy $proxy
     * @param int $timeOutSeconds
     */
    public function __construct(Proxy $proxy, int $timeOutSeconds)
    {
        $this->proxy = $proxy;
        $this->timeoutSeconds = $timeOutSeconds;
    }


    /***
     * Closes active connection
     * @return void
     * */
    public function disconnect()
    {
        if (is_resource($this->socksSocket)) {
            @socket_shutdown($this->socksSocket, 2);
            @socket_close($this->socksSocket);
        }
        $this->socksSocket = null;
    }

    /**
     * @param string $host containing domain name
     * @param int $port
     * @return resource|boolean
     * @throws SocksException
     * */
    public function createConnected($host, $port)
    {
        $this->host = $host;
        $this->port = $port;

        $this->createSocket();
        $this->connectSocket();
        $this->writeSocksGreeting();
        $socksGreetingConfig = $this->readSocksGreeting();
        $this->checkServerGreetedClient($socksGreetingConfig);
        if($this->checkGreetngWithAuth($socksGreetingConfig)){
            $this->writeSocksAuth();
            $this->readSocksAuthStatus();
        }
        $this->connectSocksSocket();
        $this->readSocksConnectStatus();

        return $this->socksSocket;
    }

    protected function createSocket()
    {
        $this->socksSocket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socksSocket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => Constants::SOCKET_CONNECT_TIMEOUT_SEC,
            'usec' => 0
        ]);
        socket_set_option($this->socksSocket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => Constants::SOCKET_CONNECT_TIMEOUT_SEC,
            'usec' => 0
        ]);
    }

    protected function connectSocket()
    {
        if ($this->socksSocket !== false) {
            $result = @socket_connect($this->socksSocket, $this->proxy->getServer(), $this->proxy->getPort());
            if (!$result)
                throw new SocksException(SocksException::UNREACHABLE_PROXY, 'on connect: ');
        }

        return false;
    }

    protected function writeSocksGreeting()
    {
        $helloMsg = "\x05\x02\x00\x02";
        $this->write($helloMsg);
    }

    protected function readSocksGreeting() : string
    {
        $socksGreetingConfig = $this->read(2);
        if (!$socksGreetingConfig)
            return FALSE;
        return $socksGreetingConfig;
    }

    /**
     * @param string $socksGreetingConfig
     * @throws SocksException
     */
    protected function checkServerGreetedClient($socksGreetingConfig)
    {
        if(!$socksGreetingConfig)
            throw new SocksException(SocksException::CONNECTION_NOT_ESTABLISHED);

        $socksVersion = ord($socksGreetingConfig[0]);
        $socksAuthType = ord($socksGreetingConfig[1]);

        if($socksVersion != 0x05)
            throw new SocksException(SocksException::UNEXPECTED_PROTOCOL_VERSION, $socksVersion);
        if($socksAuthType != 0x00 && $socksAuthType != 0x02)
            throw new SocksException(SocksException::UNSUPPORTED_AUTH_TYPE, $socksAuthType);
    }

    protected function checkGreetngWithAuth(string $socksGreetingConfig)
    {
        $socksAuthType = ord($socksGreetingConfig[1]);
        return $socksAuthType == 0x02;
    }


    protected function writeSocksAuth()
    {
        $userName = $this->proxy->getLogin();
        $ulength = chr(strlen($userName));

        $password = $this->proxy->getPassword();
        $plength = chr(strlen($password));

        $this->write("\x01".$ulength.$userName.$plength.$password);
    }


    /**
     * @throws SocksException
     */
    protected function readSocksAuthStatus() : string
    {
        $socksAuthStatus = $this->read(2);

        if(!$socksAuthStatus)
            return FALSE;

        if($socksAuthStatus[0] != "\x01" || $socksAuthStatus[1] != "\x00")
            throw new SocksException(SocksException::AUTH_FAILED);

        return TRUE;
    }


    /**
     * SOCKS protocol: https://ru.wikipedia.org/wiki/SOCKS
     * Client`s second request and server`s second response
     * @throws SocksException
     */
    protected function connectSocksSocket()
    {
        $host = $this->host;
        $port = $this->port;
        $hostnameLenBinary = chr(strlen($host));
        $portBinary = unpack("C*", pack("L", $port));
        $portBinary = chr($portBinary[2]).chr($portBinary[1]);

        // client connection request
        $establishmentMsg = "\x05\x01\x00\x03".$hostnameLenBinary.$host.$portBinary;
        $this->write($establishmentMsg);
    }


    protected function readSocksConnectStatus()
    {
        // server connection response
        $connectionStatus = $this->read(1024);
        if(!$connectionStatus)
            return FALSE;

        $this->checkConnectionEstablished($connectionStatus);
        return TRUE;
    }


    /**
     * @param string $serverConnectionResponse
     * @throws SocksException
     */
    protected function checkConnectionEstablished($serverConnectionResponse)
    {
        $socksVersion = ord($serverConnectionResponse[0]);
        $responseCode = ord($serverConnectionResponse[1]);

        if($socksVersion != 0x05)
            throw new SocksException(SocksException::UNEXPECTED_PROTOCOL_VERSION, $socksVersion);
        if($responseCode != 0x00)
            throw new SocksException(SocksException::CONNECTION_NOT_ESTABLISHED, $responseCode);
    }


    /**
     * @param string $data binary to write
     * @return int bytes actually written
     * */
    public function write($data)
    {
        return @socket_write($this->socksSocket, $data);
    }


    /**
     * @param int $bytesCount bytes count to read
     * @return string binary
     * */
    public function read($bytesCount)
    {
        return @socket_read($this->socksSocket, $bytesCount);
    }

    public function getHost(): string
    {
        return $this->host;
    }
}