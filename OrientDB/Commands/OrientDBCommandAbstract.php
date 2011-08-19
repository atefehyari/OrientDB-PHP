<?php

/**
 * @author Anton Terekhov <anton@netmonsters.ru>
 * @copyright Copyright Anton Terekhov, NetMonsters LLC, 2011
 * @license https://github.com/AntonTerekhov/OrientDB-PHP/blob/master/LICENSE
 * @link https://github.com/AntonTerekhov/OrientDB-PHP
 * @package OrientDB-PHP
 */

/**
 * Main class for OrientDB-PHP commands
 *
 * @author Anton Terekhov <anton@netmonsters.ru>
 * @package OrientDB-PHP
 * @subpackage Command
 */
abstract class OrientDBCommandAbstract
{

    const SHUTDOWN = 1;

    const CONNECT = 2;

    const DB_OPEN = 3;

    const DB_CREATE = 4;

    const DB_CLOSE = 5;

    const DB_EXIST = 6;

    const DB_DELETE = 7;

    // const DB_SIZE = 8;

    // const DB_COUNTRECORDS = 9;

    const DATACLUSTER_ADD = 10;

    const DATACLUSTER_REMOVE = 11;

    const DATACLUSTER_COUNT = 12;

    const DATACLUSTER_DATARANGE = 13;

    // const DATASEGMENT_ADD = 20;

    // const DATASEGMENT_REMOVE = 21;

    const RECORD_LOAD = 30;

    const RECORD_CREATE = 31;

    const RECORD_UPDATE = 32;

    const RECORD_DELETE = 33;

    const COUNT = 40;

    const COMMAND = 41;

    const INDEX_LOOKUP = 50;

    const INDEX_PUT = 51;

    const INDEX_REMOVE = 52;

    const INDEX_SIZE = 53;

    const INDEX_KEYS = 54;

    // const INDEX_QUERY = 55;

    const TX_COMMIT = 60;

    const CONFIG_GET = 70;

    const CONFIG_SET = 71;

    const CONFIG_LIST = 72;

    const STATUS_SUCCESS = 0x00;

    const STATUS_ERROR = 0x01;

    /**
     * Inscanse of OrientDBSocket
     * @var OrientDBSocket
     */
    private $socket;

    /**
     * Command type
     * @var integer
     */
    public $opType;

    /**
     * Attributes
     * @var array
     */
    protected $attribs;

    /**
     * TransactionID to identify DB_OPEN and CONNECT queries
     * @var integer
     */
    protected static $transactionID = 0;

    /**
     * Current transaction id
     * @var integer
     */
    protected $currentTransactionID;

    /**
     * Request Status, Success or Error
     * @var unknown_type
     */
    protected $requestStatus;

    /**
     * Request bytes tranferred to server
     * @var string
     */
    protected $requestBytes;

    /**
     * Print debug messages
     * @var boolean
     */
    protected $debug;

    /**
     * Link to OrientDB instanse
     * @var OrientDB
     */
    protected $parent;

    /**
     * Consturct new instance
     * @param OrientDB $parent
     */
    public function __construct($parent)
    {
        $this->socket = $parent->socket;
        $this->debug = $parent->isDebug();
        $this->parent = $parent;
    }

    /**
     * Prepare command execution
     * @throws OrientDBWrongCommandException
     */
    public function prepare()
    {
        $this->addByte(chr($this->opType));
        if ($this->opType === self::DB_OPEN || $this->opType === self::CONNECT) {
            $this->currentTransactionID = -(++self::$transactionID);
        } else {
            if (in_array($this->opType, $this->parent->getCommandsRequiresConnect())) {
                $this->currentTransactionID = $this->parent->getSessionIDServer();
            } elseif (in_array($this->opType, $this->parent->getCommandsRequiresDBOpen())) {
                $this->currentTransactionID = $this->parent->getSessionIDDB();
            } else {
                throw new OrientDBWrongCommandException('Unknown command');
            }
        }
        $this->addInt($this->currentTransactionID);
    }

    /**
     * Execute command by sending data to server, receive initial reply
     */
    public function execute()
    {
        $this->socket->debug = $this->debug;
        $this->socket->send($this->requestBytes);

        if (is_null($this->parent->protocolVersion)) {
            $this->debugCommand('protocol_version');
            $serverProtocolVersion = $this->readShort();
            $this->parent->setProtocolVersion($serverProtocolVersion);
        }
        if ($this->opType == self::DB_CLOSE) {
            // No incoming bytes
            return;
        }

        $this->debugCommand('request_status');
        $this->requestStatus = $this->readByte();

        $this->debugCommand('TransactionID');
        $requestTransactionID = $this->readInt();
        if ($requestTransactionID !== $this->currentTransactionID) {
            throw new OrientDBException('Transaction ID mismatch');
        }

        if ($this->requestStatus === chr(OrientDBCommandAbstract::STATUS_SUCCESS)) {
            $data = $this->parse();
            if ($this->opType === self::DB_OPEN) {
                $this->parent->setSessionIDDB($this->sessionID);
            } elseif ($this->opType === self::CONNECT) {
                $this->parent->setSessionIDServer($this->sessionID);
            }
            return $data;
        } elseif ($this->requestStatus === chr(OrientDBCommandAbstract::STATUS_ERROR)) {
            $exception = null;
            while ($this->readByte() === chr(OrientDBCommandAbstract::STATUS_ERROR)) {
                $this->debugCommand('exception_javaclass');
                $javaException = $this->readString();
                $this->debugCommand('exception_message');
                $javaExceptionDescr = $this->readString();
                $exception = new OrientDBException($javaException . ': ' . $javaExceptionDescr, 0, is_null($exception) ? null : $exception);
            }
            throw $exception;
        } else {
            throw new OrientDBException('Unknown error');
        }
    }

    /**
     * Parse server reply
     * @return mixed
     */
    protected abstract function parse();

    /**
     * Get command attributes
     */
    public function setAttribs()
    {
        $this->attribs = func_get_args();
    }

    /**
     * Read raw data from socket
     * @param int $length
     * @return string
     */
    protected function readRaw($length)
    {
        $data = '';
        $dataLeft = $length;
        do {
            $data .= $this->socket->read($dataLeft);
            $dataLeft = $length - strlen($data);
        } while ($dataLeft > 0);
        return $data;
    }

    /**
     * Read 1 byte from socket
     * @return string
     */
    protected function readByte()
    {
        return $this->readRaw(1);
    }

    /**
     * Read short from server
     * @return int
     */
    protected function readShort()
    {
        $data = unpack('n', $this->readRaw(2));
        return reset($data);
    }

    /**
     * Read int from socket. Using custom function to convert twos-complement integer
     * @return int
     */
    protected function readInt()
    {
        $data = unpack('N', $this->readRaw(4));
        $data = reset($data);
        return self::convertComplement($data);
    }

    /**
     * Read long from socket. Returns int
     * @throws OrientDBException
     * @return int
     */
    protected function readLong()
    {
        $data = unpack('N', $this->readRaw(4));
        /**
         * @TODO Java sends long as 64-bit
         */
        if (reset($data) > 0) {
            throw new OrientDBException('64-bit long detected!');
        }
        $data = unpack('N', $this->readRaw(4));
        return reset($data);
    }

    /**
     * Read string from socket, including its length
     * @return string
     */
    protected function readString()
    {
        $size = $this->readInt();
        if ($size === -1) {
            return null;
        }
        if ($size === 0) {
            return '';
        }
        return $this->readRaw($size);
    }

    /**
     * Read bytes stream from socket, including int length
     * @return string
     */
    protected function readBytes()
    {
        $size = $this->readInt();
        if ($size === -1) {
            return null;
        }
        if ($size === 0) {
            return '';
        }
        return $this->readRaw($size);
    }

    /**
     * Read entire record from socket
     * @return bool|OrientDBTypeLink|OrientDBRecord
     */
    protected function readRecord()
    {

        $this->debugCommand('record_marker');
        $marker = $this->readShort();
        /**
         * @TODO sinse PHP lack to support signed short with big endian byte
         * order unpack we need to see it that way
         * as seen at enterprise/src/main/java/com/orientechnologies/orient/enterprise/channel/binary/OChannelBinaryProtocol.java
         */

        // -2=no record
        if ($marker == 0xFFFE) {
            // no record
            return false;
        }

        // -3=Only recordID
        if ($marker == 0xFFFD) {
            // only recordID
            $this->debugCommand('record_clusterID');
            $clusterID = $this->readShort();
            $this->debugCommand('record_position');
            $recordPos = $this->readLong();
            return new OrientDBTypeLink($clusterID, $recordPos);
        }

        $record = new OrientDBRecord();
        $this->debugCommand('record_type');
        $record->type = $this->readByte();
        $this->debugCommand('record_clusterID');
        $record->clusterID = $this->readShort();
        $this->debugCommand('record_position');
        $record->recordPos = $this->readLong();
        $this->debugCommand('record_version');
        $record->version = $this->readInt();
        $this->debugCommand('record_content');
        $record->content = $this->readBytes();

        return $record;
    }

    /**
     * Add byte to outgoing data
     * @param string $byte
     */
    protected function addByte($byte)
    {
        $this->requestBytes .= $byte;
    }

    /**
     * Add short to outgoing data. Short is represented by int
     * @param int $short
     */
    protected function addShort($short)
    {
        $this->requestBytes .= pack('n', $short);
    }

    /**
     * Add int to outgoing data
     * @param int $int
     */
    protected function addInt($int)
    {
        $this->requestBytes .= pack('N', $int);
    }

    /**
     * Add long to outgoing data. Long is represented by int
     * @param int $long
     */
    protected function addLong($long)
    {
        /**
         * @TODO support 64-bit
         */
        $this->requestBytes .= str_repeat(chr(0), 4) . pack('N', $long);
    }

    /**
     * Add string to outgoing data. Length is calulated automatically
     * @param string $string
     */
    protected function addString($string)
    {
        $this->addInt(strlen($string));
        $this->requestBytes .= $string;
    }

    /**
     * Add byte stream to outgoing data. Length is calculated automatically
     * @param string $string
     */
    protected function addBytes($string)
    {
        $this->addInt(strlen($string));
        $this->requestBytes .= $string;
    }

    /**
     * If debug is enabled, output $commandName to stdout
     * @param string $commandName
     */
    protected function debugCommand($commandName)
    {
        if ($this->debug) {
            echo '>' . $commandName . PHP_EOL;
        }
    }

    /**
     * Convert twos-complement integer after unpack() on x64 systems
     * @param int $int
     * @return int
     */
    public static function convertComplement($int)
    {
        /*
         *  Valid 32-bit signed integer is -2147483648 < x < 2147483647
         *  -2^(n-1) < x < 2^(n-1) -1 where n = 32
         */
        if ($int > 2147483647) {
            return -(($int ^ 0xFFFFFFFF) + 1);
        }
        return $int;
    }
}