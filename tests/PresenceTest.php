<?php
namespace tests;
use Ably\AblyRest;
use Ably\Exceptions\AblyRequestException;
use Ably\Models\CipherParams;

require_once __DIR__ . '/factories/TestApp.php';

class PresenceTest extends \PHPUnit_Framework_TestCase {

    protected static $testApp;
    protected static $defaultOptions;
    protected static $ably;

    protected static $presenceFixture;
    protected static $channel;

    public static function setUpBeforeClass() {

        self::$testApp = new TestApp();
        self::$defaultOptions = self::$testApp->getOptions();
        self::$ably = new AblyRest( array_merge( self::$defaultOptions, array(
            'key' => self::$testApp->getAppKeyDefault()->string,
        ) ) );

        $fixture = self::$testApp->getFixture();
        self::$presenceFixture = $fixture->post_apps->channels[0]->presence;

        $key = base64_decode( $fixture->cipher->key );
        $algorithm = $fixture->cipher->algorithm . '-' . $fixture->cipher->keylength . '-' . $fixture->cipher->mode;

        $options = array(
            'encrypted' => true,
            'cipherParams' => new CipherParams( $key, $algorithm )
        );

        self::$channel = self::$ably->channel('persisted:presence_fixtures', $options);
    }

    public static function tearDownAfterClass() {
        self::$testApp->release();
    }

    /**
     * Compare presence data with fixture
     */
    public function testComparePresenceDataWithFixture() {
        $presence = self::$channel->presence->get();

        // verify presence existence and count
        $this->assertNotNull( $presence, 'Expected non-null presence data' );
        $this->assertEquals( 6, count($presence->items), 'Expected 6 presence messages' );

        // verify presence contents
        $fixturePresenceMap = array();
        foreach (self::$presenceFixture as $entry) {
            $fixturePresenceMap[$entry->clientId] = $entry->data;
        }

        foreach ($presence->items as $entry) {
            $this->assertNotNull( $entry->clientId, 'Expected non-null client ID' );
            $this->assertTrue(
                array_key_exists($entry->clientId, $fixturePresenceMap) && $fixturePresenceMap[$entry->clientId] == $entry->originalData,
                'Expected presence contents to match'
            );
        }

        // verify limit / pagination
        $firstPage = self::$channel->presence->get( array( 'limit' => 3, 'direction' => 'forwards' ) );

        $this->assertEquals( 3, count($firstPage->items), 'Expected 3 presence entries on the 1st page' );

        $nextPage = $firstPage->next();
        $this->assertEquals( 3, count($nextPage->items), 'Expected 3 presence entries on the 2nd page' );
        $this->assertTrue( $nextPage->isLast(), 'Expected last page' );

        $this->markTestIncomplete(
          'Ignore `isFirst` for presence pagination as we have no proper way of determining this yet'
        );
        $this->assertTrue( $firstPage->isFirst(), 'Expected the page to be first' );
    }

    /**
     * Compare presence history with fixture
     */
    public function testComparePresenceHistoryWithFixture() {
        $history = self::$channel->presence->history();

        // verify history existence and count
        $this->assertNotNull( $history, 'Expected non-null history data' );
        $this->assertEquals( 6, count($history->items), 'Expected 6 history entries' );

        // verify history contents
        $fixtureHistoryMap = array();
        foreach (self::$presenceFixture as $entry) {
            $fixtureHistoryMap[$entry->clientId] = $entry->data;
        }

        foreach ($history->items as $entry) {
            $this->assertNotNull( $entry->clientId, 'Expected non-null client ID' );
            $this->assertTrue(
                isset($fixtureHistoryMap[$entry->clientId]) && $fixtureHistoryMap[$entry->clientId] == $entry->originalData,
                'Expected presence contents to match'
            );
        }

        // verify limit / pagination - forwards
        $firstPage = self::$channel->presence->history( array( 'limit' => 3, 'direction' => 'forwards' ) );

        $this->assertTrue( $firstPage->isFirst(), 'Expected the page to be first' );
        $this->assertEquals( 3, count($firstPage->items), 'Expected 3 presence entries' );

        $nextPage = $firstPage->next();

        $this->assertEquals( self::$presenceFixture[0]->clientId, $firstPage->items[0]->clientId, 'Expected least recent presence activity to be the first' );
        $this->assertEquals( self::$presenceFixture[5]->clientId, $nextPage->items[2]->clientId, 'Expected most recent presence activity to be the last' );

        // verify limit / pagination - backwards (default)
        $firstPage = self::$channel->presence->history( array( 'limit' => 3 ) );

        $this->assertTrue( $firstPage->isFirst(), 'Expected the page to be first' );
        $this->assertEquals( 3, count($firstPage->items), 'Expected 3 presence entries' );

        $nextPage = $firstPage->next();

        $this->assertEquals( self::$presenceFixture[5]->clientId, $firstPage->items[0]->clientId, 'Expected most recent presence activity to be the first' );
        $this->assertEquals( self::$presenceFixture[0]->clientId, $nextPage->items[2]->clientId, 'Expected least recent presence activity to be the last' );
    }

    /*
     * Check whether time range queries work properly
     */
    public function testPresenceHistoryTimeRange() {
        // ensure some time has passed since mock presence data was sent
        $delay = 1000; // sleep for 1000ms
        usleep($delay * 1000); // in microseconds

        $timeOffset = self::$ably->time() - self::$ably->systemTime();
        $now = $timeOffset + self::$ably->systemTime();

        // test with start parameter
        try {
            $history = self::$channel->presence->history( array( 'start' => $now ) );
            $this->assertEquals( 0, count($history->items), 'Expected 0 presence messages' );
        } catch (AblyRequestException $e) {
            $this->fail( 'Start parameter - ' . $e->getMessage() . ', HTTP code: ' . $e->getCode() );
        }

        // test with end parameter
        try {
            $history = self::$channel->presence->history( array( 'end' => $now ) );
            $this->assertEquals( 6, count($history->items), 'Expected 6 presence messages' );
        } catch (AblyRequestException $e) {
            $this->fail( 'End parameter - ' . $e->getMessage() . ', HTTP code: ' . $e->getCode() );
        }

        // test with both start and end parameters - time range: ($now - 500ms) ... $now
        try {
            $history = self::$channel->presence->history( array( 'start' => $now - ($delay / 2), 'end' => $now ) );
            $this->assertEquals( 0, count($history->items), 'Expected 0 presence messages' );
        } catch (AblyRequestException $e) {
            $this->fail( 'Start + end parameter - ' . $e->getMessage() . ', HTTP code: ' . $e->getCode() );
        }

        // test ISO 8601 date format
        try {
            $history = self::$channel->presence->history( array( 'end' => gmdate('c', $now / 1000) ) );
            $this->assertEquals( 6, count($history->items), 'Expected 6 presence messages' );
        } catch (AblyRequestException $e) {
            $this->fail( 'ISO format: ' . $e->getMessage() . ', HTTP code: ' . $e->getCode() );
        }
    }

    /**
     * Compare presence data with fixture
     */
    public function testComparePresenceDataWithFixtureEncrypted() {
        $presence = self::$channel->presence->get();

        // verify presence existence and count
        $this->assertNotNull( $presence, 'Expected non-null presence data' );
        $this->assertEquals( 6, count($presence->items), 'Expected 6 presence messages' );

        // verify presence contents
        $messageMap = array();
        foreach ($presence->items as $entry) {
            $messageMap[$entry->clientId] = $entry->data;
        }

        $this->assertEquals( $messageMap['client_decoded'], $messageMap['client_encoded'], 'Expected decrypted and sample data to match' );
    }
}
