<?php

namespace MediaWiki\Extension\GlobalBlocking\Test\Integration\Services;

use InvalidArgumentException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlobalBlocking\GlobalBlockingServices;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup
 * @group Database
 */
class GlobalBlockLookupTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		// Set a fake time such that the expiry of all blocks is after this date (otherwise the lookup may
		// not return the expired blocks and cause failures).
		ConvertibleTimestamp::setFakeTime( '20240219050403' );
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
	}

	private function getGloballyBlockedTestUser(): User {
		// Get the username for the blocked user from the DB, as the DB holds the username (this
		// was generated by the ::addDBDataOnce method).
		$blockedUsername = $this->getDb()->newSelectQueryBuilder()
			->select( 'gb_address' )
			->from( 'globalblocks' )
			->where( [ 'gb_id' => 5 ] )
			->fetchField();
		return $this->getServiceContainer()->getUserFactory()->newFromName( $blockedUsername );
	}

	/** @dataProvider provideGetUserBlockForNamedWhenXFFHeaderIsNotBlocked */
	public function testGetUserBlockForNamedWhenXFFHeaderIsNotBlocked( $xffHeader ) {
		$this->overrideConfigValue( 'GlobalBlockingBlockXFF', true );
		$testUser = $this->getTestUser()->getUser();
		RequestContext::getMain()->setUser( $testUser );
		RequestContext::getMain()->getRequest()->setHeader( 'X-Forwarded-For', $xffHeader );
		$this->testGetUserBlockOnNoBlock(
			$testUser, null,
			'No matching global block row should have been found using the XFF header by ::getUserBlock.'
		);
	}

	public static function provideGetUserBlockForNamedWhenXFFHeaderIsNotBlocked() {
		return [
			'XFF header has only spaces' => [ '   ' ],
			'XFF header is invalid' => [ 'abdef' ],
			'XFF header is for an IP which is not blocked' => [ '1.2.3.4' ],
		];
	}

	/** @dataProvider provideGetUserBlockForNamedWhenXffBlocked */
	public function testGetUserBlockForNamedWhenXffBlocked( $xffHeader, $expectedGlobalBlockId ) {
		$this->overrideConfigValue( 'GlobalBlockingBlockXFF', true );
		$testUser = $this->getTestUser()->getUser();
		RequestContext::getMain()->setUser( $testUser );
		RequestContext::getMain()->getRequest()->setHeader( 'X-Forwarded-For', $xffHeader );
		$actualGlobalBlockObject = GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockLookup()
			->getUserBlock( $testUser, '1.2.3.4' );
		$this->assertNotNull(
			$actualGlobalBlockObject,
			'A matching global block row should have been found by ::getUserBlock.'
		);
		$this->assertSame(
			$expectedGlobalBlockId,
			$actualGlobalBlockObject->getId(),
			'The GlobalBlock object returned was not for the expected row.'
		);
	}

	public static function provideGetUserBlockForNamedWhenXffBlocked() {
		return [
			'One XFF header IP is blocked' => [ '1.2.3.5, 77.8.9.10', 3 ],
			'Two XFF header IPs are blocked but the first is anon only' => [ '127.0.0.1, 77.8.9.10', 3 ],
			'Three XFF header IPs are blocked with one disabling account creation' => [
				'127.0.0.1, 4.5.6.7, 77.8.9.10', 6
			],
		];
	}

	/** @dataProvider provideGetUserBlock */
	public function testGetUserBlockForNamedUser( $ip, $expectedGlobalBlockId, ?User $user = null ) {
		$actualGlobalBlockObject = GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockLookup()
			->getUserBlock( $user ?? $this->getTestUser()->getUser(), $ip );
		$this->assertNotNull(
			$actualGlobalBlockObject,
			'A matching global block row should have been found by ::getUserBlock.'
		);
		$this->assertSame(
			$expectedGlobalBlockId,
			$actualGlobalBlockObject->getId(),
			'The GlobalBlock object returned was not for the expected row.'
		);
		// Assert that the GlobalBlock returned has the correct properties for a selected fields.
		$rowFromTheDb = (array)$this->getDb()->newSelectQueryBuilder()
			->select( [ 'gb_by_central_id', 'gb_address', 'gb_reason', 'gb_timestamp' ] )
			->from( 'globalblocks' )
			->where( [ 'gb_id' => $expectedGlobalBlockId ] )
			->fetchRow();
		$this->assertSame(
			$this->getServiceContainer()
				->getCentralIdLookup()
				->nameFromCentralId( $rowFromTheDb['gb_by_central_id'] ),
			$actualGlobalBlockObject->getByName(),
			'The GlobalBlock object returned by ::getUserBlock does not have the expected blocker name.'
		);
		$this->assertSame(
			$rowFromTheDb['gb_address'],
			$actualGlobalBlockObject->getTargetName(),
			'The GlobalBlock object returned by ::getUserBlock does not have the expected target.'
		);
		$this->assertSame(
			ConvertibleTimestamp::convert( TS_MW, $rowFromTheDb['gb_timestamp'] ),
			$actualGlobalBlockObject->getTimestamp(),
			'The GlobalBlock object returned by ::getUserBlock does not have the expected timestamp.'
		);
	}

	public static function provideGetUserBlock() {
		return [
			'The IP used by the named user is blocked' => [ '77.8.9.10', 3 ],
			'The IP range used by the named user is blocked' => [ '88.8.9.5', 4 ],
		];
	}

	/** @dataProvider provideGetUserBlock */
	public function testGetUserBlockForBlockOnNamedUser( $ip ) {
		// Assert that the if the user is blocked, the block returned by ::getUserBlock will always
		// be the one for the user, not the IP (as the user one has a higher priority over all IP blocks).
		$this->testGetUserBlockForNamedUser( $ip, 5, $this->getGloballyBlockedTestUser() );
	}

	public function testGetUserBlockPrioritisesBlocksWithDisableAccountCreation() {
		// Assert that if two blocks match, that ::getUserBlock chooses the block which disables account creation
		// over the block which does not disable account creation.
		$this->testGetUserBlockForNamedUser( '4.5.6.7', 6, $this->getGloballyBlockedTestUser() );
	}

	public function testGetUserBlockGlobalBlockingAllowedRanges() {
		$this->overrideConfigValue( 'GlobalBlockingAllowedRanges', [ '1.2.3.4/30', '5.6.7.8/24' ] );
		$this->testGetUserBlockOnNoBlock(
			UserIdentityValue::newAnonymous( '5.6.7.8' ), null,
			'No matching global block row should have been found by ::getUserBlock because the IP is in ' .
			'a range that is exempt from global blocking.'
		);
	}

	/** @dataProvider provideExemptRights */
	public function testGetUserBlockWhenUserIsExempt( $exemptRight ) {
		$userMock = $this->createMock( User::class );
		$userMock->method( 'isAllowedAny' )
			->willReturnCallback( static function ( ...$rights ) use ( $exemptRight ) {
				return in_array( $exemptRight, $rights );
			} );
		$userMock->method( 'getName' )
			->willReturn( 'TestUser-' . $exemptRight );
		$globalBlockLookup = GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockLookup();
		$this->assertNull(
			$globalBlockLookup->getUserBlock( $userMock, '127.0.0.1' ),
			'A user exempt from the global block who is not a target of an account block should not be blocked.'
		);
	}

	public static function provideExemptRights() {
		return [
			'ipblock-exempt' => [ 'ipblock-exempt' ],
			'globalblock-exempt' => [ 'globalblock-exempt' ],
		];
	}

	/** @dataProvider provideGetUserBlockOnNoBlock */
	public function testGetUserBlockOnNoBlock( $userIdentity, $ip, $message = null ) {
		$this->assertNull(
			GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getUserBlock(
					$this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $userIdentity ),
					$ip
				),
			$message ?? 'No matching global block row should have been found by ::getUserBlock.'
		);
	}

	public static function provideGetUserBlockOnNoBlock() {
		return [
			'No block on logged-out user with IP as null' => [ UserIdentityValue::newAnonymous( '1.2.3.4' ), null ],
			'IP Block is locally disabled for logged-out user' => [
				UserIdentityValue::newAnonymous( '127.0.0.2' ),
				'127.0.0.2',
				'The matching global block has been locally disabled, so should not be returned by ::getUserBlock.'
			],
		];
	}

	/** @dataProvider provideGetUserBlockOnNoBlockForNamedUser */
	public function testGetUserBlockOnNoBlockForNamedUser( $ip ) {
		$this->testGetUserBlockOnNoBlock(
			$this->getTestUser()->getUser(),
			$ip,
			'No matching global block row should have been found by ::getUserBlock for an account ' .
			'which is not globally blocked.'
		);
	}

	public static function provideGetUserBlockOnNoBlockForNamedUser() {
		return [
			'IP is null provided' => [ null ],
			'IP is provided but is not blocked' => [ '1.2.3.4' ],
		];
	}

	/** @dataProvider provideGetGlobalBlockingBlockWhenNoRowsFound */
	public function testGetGlobalBlockingBlockWhenNoRowsFound( $ip, $flags ) {
		$this->assertNull(
			GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getGlobalBlockingBlock( $ip, 0, $flags ),
			'No matching global block row should have been found by ::getGlobalBlockingBlock.'
		);
	}

	public static function provideGetGlobalBlockingBlockWhenNoRowsFound() {
		return [
			'No global block on the given single IP target' => [
				// The $ip argument for provided to ::getGlobalBlockingBlock
				'1.2.3.4',
				// The $flags argument for provided to ::getGlobalBlockingBlock
				GlobalBlockLookup::SKIP_SOFT_IP_BLOCKS,
			],
			'No global block on the given range' => [ '1.2.3.4/20', GlobalBlockLookup::SKIP_SOFT_IP_BLOCKS ],
		];
	}

	/** @dataProvider provideGetGlobalBlockingBlock */
	public function testGetGlobalBlockingBlock( $ip, $centralId, $flags, $expectedRowId ) {
		$expectedRow = (array)$this->getDb()->newSelectQueryBuilder()
			->select( GlobalBlockLookup::selectFields() )
			->from( 'globalblocks' )
			->where( [ 'gb_id' => $expectedRowId ] )
			->fetchRow();
		$this->assertArrayEquals(
			$expectedRow,
			(array)GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getGlobalBlockingBlock( $ip, $centralId, $flags ),
			false,
			true,
			'The global block row returned by ::getGlobalBlockingBlock is not as expected.'
		);
	}

	public static function provideGetGlobalBlockingBlock() {
		return [
			'Single IP target is subject to two blocks, but $flags disable checking for soft blocks ' .
			'and local whitelist status' => [
				// The target IP or IP range provided as the $ip argument to ::getGlobalBlockingBlock
				'127.0.0.1',
				// The $centralId argument provided to ::getGlobalBlockingBlock
				0,
				// The $flags provided to ::getGlobalBlockingBlock
				GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK |
				GlobalBlockLookup::SKIP_SOFT_IP_BLOCKS,
				// The ID of the global block row from the globalblocks table that should be returned by
				// ::getGlobalBlockingBlock.
				2,
			],
			'Single IP target is subject to two blocks' => [
				'127.0.0.1', true, GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK, 1,
			],
			'Single IP target is subject to a range block' => [
				'127.0.0.2', true, GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK, 2,
			],
			'Range target is subject to a range block' => [
				'127.0.0.0/27', true, GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK, 2,
			],
		];
	}

	public function testGetGlobalBlockingBlockForGloballyBlockedAccount() {
		$this->testGetGlobalBlockingBlock(
			null,
			$this->getServiceContainer()
				->getCentralIdLookup()
				->centralIdFromLocalUser( $this->getGloballyBlockedTestUser() ),
			GlobalBlockLookup::SKIP_LOCAL_DISABLE_CHECK,
			5
		);
	}

	/** @dataProvider provideGetGlobalBlockId */
	public function testGetGlobalBlockId( $target, $queryFlags, $expectedResult ) {
		$this->assertSame(
			$expectedResult,
			GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getGlobalBlockId( $target, $queryFlags ),
			'The global block ID returned by the method under test is not as expected.'
		);
	}

	public static function provideGetGlobalBlockId() {
		return [
			'No global block on given IP target' => [ '1.2.3.4', DB_REPLICA, 0 ],
			'Global block on given IP target while reading from primary' => [ '127.0.0.1', DB_PRIMARY, 1 ],
			'Global block on given IP range target' => [ '127.0.0.0/24', DB_REPLICA, 2 ],
			'No global block on an non-existing account' => [ 'Test-does-not-exist', DB_REPLICA, 0 ],
		];
	}

	public function testGetGlobalBlockIdForGloballyBlockedAccount() {
		$this->testGetGlobalBlockId( $this->getGloballyBlockedTestUser()->getName(), DB_REPLICA, 5 );
	}

	public static function provideGetGlobalBlockLookupConditions() {
		// Modified copy of DatabaseBlockStoreTest::provideGetRangeCond
		return [
			'Single IPv4' => [
				// The IP address or range ($ip argument)
				'1.2.3.4',
				// The $centralId argument
				0,
				// The $flags argument
				0,
				// The expected WHERE conditions, but excluding the first part that is the gb_expiry check.
				// This is added by the test as the expiry value is different between DB types and we cannot
				// check the type of the DB here.
				" AND (gb_range_start LIKE '0102%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '1.2.3.4' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '1.2.3.4' ) . "'))",
			],
			'IPv4 /31' => [
				'1.2.3.4/31', 0, 0,
				" AND (gb_range_start LIKE '0102%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '1.2.3.4' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '1.2.3.5' ) . "'))",
			],
			'IPv4 /24' => [
				'1.2.3.4/24', 0, 0,
				" AND (gb_range_start LIKE '0102%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '1.2.3.0' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '1.2.3.255' ) . "'))",
			],
			'IPv4 /14' => [
				'1.2.3.4/14', 0, 0,
				" AND (gb_range_start LIKE '01%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '1.0.0.0' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '1.3.255.255' ) . "'))",
				10
			],
			'Single IPv6' => [
				'2000:DEAD:BEEF:A:0:0:0:0', 0, 0,
				" AND (gb_range_start LIKE 'v6-2000%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:0:0' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:0:0' ) . "'))",
			],
			'IPv6 /108' => [
				'2000:DEAD:BEEF:A:0:0:0:0/108', 0, 0,
				" AND (gb_range_start LIKE 'v6-2000%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:0:0' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:000F:FFFF' ) . "'))"
			],
			'IPv6 /108 with different limit' => [
				'2000:DEAD:BEEF:A:0:0:0:0/108', 0, 0,
				" AND (gb_range_start LIKE 'v6-20%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:0:0' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '2000:DEAD:BEEF:A:0:0:000F:FFFF' ) . "'))",
				16,
				10
			],
			'IPv4 with anon only' => [
				'1.2.3.4', 0, GlobalBlockLookup::SKIP_SOFT_IP_BLOCKS,
				" AND (gb_range_start LIKE '0102%' ESCAPE '`'"
				. " AND gb_range_start <= '" . IPUtils::toHex( '1.2.3.4' ) . "'"
				. " AND gb_range_end >= '" . IPUtils::toHex( '1.2.3.4' ) . "'"
				. " AND gb_anon_only != 1))",
			]
		];
	}

	/** @dataProvider provideGetGlobalBlockLookupConditions */
	public function testGetGlobalBlockLookupConditions(
		$ipOrRange, $centralId, $flags, $expected, $ipV4Limit = 16, $ipV6Limit = 19
	) {
		$this->overrideConfigValue( 'GlobalBlockingCIDRLimit', [
			'IPv4' => $ipV4Limit,
			'IPv6' => $ipV6Limit,
		] );
		ConvertibleTimestamp::setFakeTime( '20240219050403' );
		$this->assertSame(
			"(gb_expiry > '" . $this->getDb()->timestamp( '20240219050403' ) . "'" . $expected,
			GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getGlobalBlockLookupConditions( $ipOrRange, $centralId, $flags )
				->toSql( $this->getDb() ),
			'The IP range conditions returned by GlobalBlockLookup::getRangeCondition are not as expected.'
		);
	}

	public function testGetGlobalBlockLookupConditionsForUser() {
		$globallyBlockedTestUser = $this->getGloballyBlockedTestUser();
		$centralId = $this->getServiceContainer()->getCentralIdLookup()->centralIdFromLocalUser(
			$globallyBlockedTestUser
		);
		$this->testGetGlobalBlockLookupConditions(
			'1.2.3.4',
			$centralId,
			0,
			" AND (gb_target_central_id = " . $centralId .
			" OR (gb_range_start LIKE '0102%' ESCAPE '`'"
			. " AND gb_range_start <= '" . IPUtils::toHex( '1.2.3.4' ) . "'"
			. " AND gb_range_end >= '" . IPUtils::toHex( '1.2.3.4' ) . "')))",
		);
	}

	/** @dataProvider provideGetGlobalBlockLookupConditionsForNullResult */
	public function testGetGlobalBlockLookupConditionsForNullResult( $ip, $centralId, $flags ) {
		$this->assertNull(
			GlobalBlockingServices::wrap( $this->getServiceContainer() )
				->getGlobalBlockLookup()
				->getGlobalBlockLookupConditions( $ip, $centralId, $flags ),
			'The conditions returned by ::getGlobalBlockLookupConditions should be null.'
		);
	}

	public static function provideGetGlobalBlockLookupConditionsForNullResult() {
		return [
			'IP is null along with the central ID of 0 with no flags' => [ null, 0, 0, null ],
			'IP is not null with central ID of 0 with flags as SKIP_IP_BLOCKS' => [
				'1.2.3.4', 0, GlobalBlockLookup::SKIP_IP_BLOCKS, null,
			],
		];
	}

	public function testGetGlobalBlockLookupConditionsForInvalidIP() {
		$this->expectException( InvalidArgumentException::class );
		GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockLookup()
			->getGlobalBlockLookupConditions( 'invalid-ip', 1, 0 );
	}

	public function testGetRangeConditionForInvalidIP() {
		$this->expectException( InvalidArgumentException::class );
		GlobalBlockingServices::wrap( $this->getServiceContainer() )
			->getGlobalBlockLookup()
			->getRangeCondition( 'invalid-ip' );
	}

	public function addDBDataOnce() {
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
		// We can add the DB data once for this class as the service should not modify, insert or delete rows from
		// the database.
		$testUser = $this->getTestSysop()->getUserIdentity();
		$testBlockedUser = $this->getTestUser()->getUser();
		// Insert a range block and single IP block for the test.
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'globalblocks' )
			->row( [
				'gb_id' => 1,
				'gb_address' => '127.0.0.1',
				'gb_target_central_id' => 0,
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20230405060708' ),
				'gb_anon_only' => 1,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20240405060708' ),
				'gb_range_start' => IPUtils::toHex( '127.0.0.1' ),
				'gb_range_end' => IPUtils::toHex( '127.0.0.1' ),
				'gb_create_account' => 0,
			] )
			->row( [
				'gb_id' => 2,
				'gb_address' => '127.0.0.0/24',
				'gb_target_central_id' => 0,
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20220405060708' ),
				'gb_anon_only' => 0,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20250405060708' ),
				'gb_range_start' => IPUtils::toHex( '127.0.0.0' ),
				'gb_range_end' => IPUtils::toHex( '127.0.0.255' ),
				'gb_create_account' => 0,
			] )
			->row( [
				'gb_id' => 3,
				'gb_address' => '77.8.9.10',
				'gb_target_central_id' => 0,
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20080405060708' ),
				'gb_anon_only' => 0,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20240405060708' ),
				'gb_range_start' => IPUtils::toHex( '77.8.9.10' ),
				'gb_range_end' => IPUtils::toHex( '77.8.9.10' ),
				'gb_create_account' => 0,
			] )
			->row( [
				'gb_id' => 4,
				'gb_address' => '88.8.9.0/24',
				'gb_target_central_id' => 0,
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20080405060708' ),
				'gb_anon_only' => 0,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20240405060708' ),
				'gb_range_start' => IPUtils::toHex( '88.8.9.0' ),
				'gb_range_end' => IPUtils::toHex( '88.8.9.255' ),
				'gb_create_account' => 0,
			] )
			->row( [
				'gb_id' => 5,
				'gb_address' => $testBlockedUser->getName(),
				'gb_target_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testBlockedUser ),
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20080405060708' ),
				'gb_anon_only' => 0,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20240405060708' ),
				'gb_range_start' => '',
				'gb_range_end' => '',
				'gb_create_account' => 0,
			] )
			->row( [
				'gb_id' => 6,
				'gb_address' => '4.5.6.0/24',
				'gb_target_central_id' => 0,
				'gb_by_central_id' => $this->getServiceContainer()
					->getCentralIdLookup()
					->centralIdFromLocalUser( $testUser ),
				'gb_by_wiki' => WikiMap::getCurrentWikiId(),
				'gb_reason' => 'test',
				'gb_timestamp' => $this->getDb()->timestamp( '20080405060708' ),
				'gb_anon_only' => 0,
				'gb_expiry' => $this->getDb()->encodeExpiry( '20240406060708' ),
				'gb_range_start' => IPUtils::toHex( '4.5.6.0' ),
				'gb_range_end' => IPUtils::toHex( '4.5.6.255' ),
				'gb_create_account' => 1,
			] )
			->caller( __METHOD__ )
			->execute();
		// Insert a whitelist entry for the range block
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_block_whitelist' )
			->row( [
				'gbw_by' => $testUser->getId(),
				'gbw_by_text' => $testUser->getName(),
				'gbw_reason' => 'test-override',
				'gbw_address' => '127.0.0.0/24',
				'gbw_expiry' => $this->getDb()->encodeExpiry( '20250405060708' ),
				'gbw_id' => 2,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
