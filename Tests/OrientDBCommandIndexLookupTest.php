<?php

require_once 'OrientDB/OrientDB.php';
require_once 'OrientDBBaseTest.php';

class OrientDBIndexLookupTest extends OrientDBBaseTesting
{

    protected function setUp() {
        $this->db = new OrientDB('localhost', 2424);
    }

    protected function tearDown() {
        $this->db = null;
    }

    public function testIndexLookupOnNotConnectedDB() {
        $this->setExpectedException('OrientDBWrongCommandException');
        $list = $this->db->indexLookup();
    }

    public function testIndexLookupOnConnectedDB() {
        $this->db->connect('root', $this->root_password);
        $this->setExpectedException('OrientDBWrongCommandException');
        $list = $this->db->indexLookup();
    }

    public function testIndexLookupOnNotOpenDB() {
        $this->setExpectedException('OrientDBWrongCommandException');
        $list = $this->db->indexLookup();
    }

    public function testIndexLookupOnOpenDB() {
        $this->db->DBOpen('demo', 'writer', 'writer');
        $record = $this->db->indexLookup('key-1');
        $this->assertInstanceOf('OrientDBRecord', $record);
    }

    public function testIndexLookupWithWrongOptionCount() {
        $this->db->DBOpen('demo', 'writer', 'writer');
        $this->setExpectedException('OrientDBWrongParamsException');
        $record = $this->db->indexLookup();
    }

    public function testIndexLookupWithIncorrectKey() {
        $this->db->DBOpen('demo', 'writer', 'writer');
        $record = $this->db->indexLookup('NONEXIST');
        $this->assertFalse($record);
    }
}