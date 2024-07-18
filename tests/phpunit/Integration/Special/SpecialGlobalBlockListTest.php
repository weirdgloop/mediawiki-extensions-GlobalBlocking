<?php

namespace MediaWiki\Extension\GlobalBlocking\Test\Integration\Specials;

use MediaWiki\Extension\GlobalBlocking\GlobalBlockingServices;
use MediaWiki\Extension\GlobalBlocking\Special\SpecialGlobalBlockList;
use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlobalBlocking\Special\SpecialGlobalBlockList
 * @covers \MediaWiki\Extension\GlobalBlocking\Special\GlobalBlockListPager
 */
class SpecialGlobalBlockListTest extends SpecialPageTestBase {

	private static array $blockedTargets;
	private static string $globallyBlockedUser;

	protected function setUp(): void {
		parent::setUp();
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->setMwGlobals( 'wgCentralIdLookupProvider', 'local' );
	}

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		$globalBlockingServices = GlobalBlockingServices::wrap( $services );
		return new SpecialGlobalBlockList(
			$services->getUserNameUtils(),
			$services->getCommentFormatter(),
			$services->getCentralIdLookup(),
			$globalBlockingServices->getGlobalBlockLookup(),
			$globalBlockingServices->getGlobalBlockingLinkBuilder(),
			$globalBlockingServices->getGlobalBlockingConnectionProvider(),
			$globalBlockingServices->getGlobalBlockLocalStatusLookup(),
			$services->getUserIdentityLookup(),
			$globalBlockingServices->getGlobalBlockingUserVisibilityLookup()
		);
	}

	public function testViewPageBeforeSubmission() {
		// Need to get the full HTML to be able to check that the subtitle links are present
		[ $html ] = $this->executeSpecialPage( '', null, null, null, true );
		// Check that the form fields exist
		$this->assertStringContainsString( '(globalblocking-search-target', $html );
		$this->assertStringContainsString( '(globalblocking-list-tempblocks', $html );
		$this->assertStringContainsString( '(globalblocking-list-indefblocks', $html );
		$this->assertStringContainsString( '(globalblocking-list-addressblocks', $html );
		$this->assertStringContainsString( '(globalblocking-list-rangeblocks', $html );
		// Verify that the form title is present
		$this->assertStringContainsString( '(globalblocking-search-legend', $html );
		// Verify that the special title and description are correct
		$this->assertStringContainsString( '(globalblocking-list', $html );
		$this->assertStringContainsString( '(globalblocklist-summary', $html );
		// Verify that a list of all active global blocks is shown (even though the form has not been submitted)
		foreach ( self::$blockedTargets as $target ) {
			$this->assertStringContainsString( $target, $html );
		}
	}

	/** @dataProvider provideTargetParam */
	public function testTargetParam( string $target, $expectedTarget ) {
		// Override the CIDR limits to allow IPv6 /18 ranges in the test.
		$this->overrideConfigValue( 'GlobalBlockingCIDRLimit', [ 'IPv4' => 16, 'IPv6' => 17 ] );
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( [ 'target' => $target ] ) );
		if ( $expectedTarget ) {
			$this->assertStringContainsString(
				$expectedTarget, $html, 'The expected block target was not shown in the page'
			);
		} else {
			$this->assertStringContainsString(
				'globalblocking-list-noresults', $html, 'Results shown when no results were expected'
			);
		}
	}

	public function provideTargetParam() {
		return [
			'single IPv4' => [ '1.2.3.4', '1.2.3.4' ],
			'exact IPv4 range' => [ '1.2.3.4/24', '1.2.3.0/24' ],
			'single IPv6' => [ '::1', '0:0:0:0:0:0:0:0/19' ],
			'exact IPv6 range' => [ '::1/19', '0:0:0:0:0:0:0:0/19' ],
			'narrower IPv6 range' => [ '::1/20', '0:0:0:0:0:0:0:0/19' ],
			'wider IPv6 range' => [ '::1/18', false ],
			'unblocked IP' => [ '6.7.8.9', false ],
		];
	}

	public function testTargetParamWithGloballyBlockedUser() {
		$this->testTargetParam( self::$globallyBlockedUser, self::$globallyBlockedUser );
	}

	public function testTargetParamWithNonExistentUser() {
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( [ 'target' => 'NonExistentTestUser1234' ] ) );
		$this->assertStringContainsString(
			'(nosuchusershort', $html, 'The expected block target was not shown in the page'
		);
	}

	public function testIPParam() {
		// Load the page with the B/C 'ip' param for an IP that is not globally blocked and verify that the page
		// displays no results.
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( [ 'ip' => '7.6.5.4' ] ) );
		$this->assertStringContainsString(
			'globalblocking-list-noresults', $html, 'Results shown when no results were expected'
		);
	}

	/** @dataProvider provideViewPageWithOptionsSelected */
	public function testViewPageWithOptionsSelected(
		$selectedOptions, $expectedTargets, $accountIsAnExpectedTargets
	) {
		// Add the globally blocked account to the $expectedTargets array if $accountIsAnExpectedTargets is true.
		// This is required because we do not have access to the globally blocked account name in the data provider,
		// but do once this test runs.
		if ( $accountIsAnExpectedTargets ) {
			$expectedTargets[] = self::$globallyBlockedUser;
		}
		// Load the special page with the selected options.
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( [ 'wpOptions' => $selectedOptions ] ) );
		// Verify that the expected targets are not there.
		foreach ( $expectedTargets as $target ) {
			$this->assertStringContainsString( $target, $html );
		}
		// Assert that no other targets are listed in the page
		$targetsExpectedToNotBePresent = array_diff( $expectedTargets, self::$blockedTargets );
		foreach ( $targetsExpectedToNotBePresent as $target ) {
			$this->assertStringNotContainsString( $target, $html );
		}
		// If no targets are expected, verify that the no results message is shown.
		if ( count( $expectedTargets ) === 0 ) {
			$this->assertStringContainsString(
				'globalblocking-list-noresults', $html, 'Results shown when no results were expected'
			);
		}
	}

	public function provideViewPageWithOptionsSelected() {
		return [
			'Hide IP blocks' => [
				// The value of the wgOptions parameter
				[ 'addressblocks' ],
				// The targets that should appear in the special page once submitting the form.
				[ '1.2.3.0/24', '0:0:0:0:0:0:0:0/19' ],
				// Whether the globally blocked account should also be a target that appears in the special page.
				true
			],
			'Hide range blocks' => [ [ 'rangeblocks' ], [ '1.2.3.4' ], true ],
			'Hide user blocks' => [ [ 'userblocks' ], [ '1.2.3.4', '1.2.3.0/24', '0:0:0:0:0:0:0:0/19' ], false ],
			'Hide IP and range blocks' => [ [ 'addressblocks', 'rangeblocks' ], [], true ],
			'Hide user, IP, and range blocks' => [ [ 'addressblocks', 'rangeblocks', 'userblocks' ], [], false ],
			'Hide temporary blocks' => [ [ 'tempblocks' ], [ '1.2.3.4', '0:0:0:0:0:0:0:0/19' ], true ],
			'Hide indefinite blocks' => [ [ 'indefblocks' ], [ '1.2.3.0/24' ], false ],
			'Hide temporary and indefinite blocks' => [ [ 'tempblocks', 'indefblocks' ], [], false ],
		];
	}

	public function addDBDataOnce() {
		// We don't want to test specifically the CentralAuth implementation of the CentralIdLookup. As such, force it
		// to be the local provider.
		$this->setMwGlobals( 'wgCentralIdLookupProvider', 'local' );
		// Create some testing globalblocks database rows for IPs and IP ranges for use in the above tests. These
		// should not be modified by any code in SpecialGlobalBlockList, so this can be added once per-class.
		$globalBlockManager = GlobalBlockingServices::wrap( $this->getServiceContainer() )->getGlobalBlockManager();
		$testPerformer = $this->getTestUser( [ 'steward' ] )->getUser();
		$this->assertStatusGood(
			$globalBlockManager->block( '1.2.3.4', 'Test reason', 'infinity', $testPerformer )
		);
		$this->assertStatusGood(
			$globalBlockManager->block( '1.2.3.4/24', 'Test reason2', '1 month', $testPerformer )
		);
		$this->assertStatusGood(
			$globalBlockManager->block( '0:0:0:0:0:0:0:0/19', 'Test reason3', 'infinite', $testPerformer )
		);
		// Globally block a username to test handling global account blocks
		$globallyBlockedUser = $this->getMutableTestUser()->getUserIdentity()->getName();
		$this->assertStatusGood(
			$globalBlockManager->block( $globallyBlockedUser, 'Test reason4', 'infinite', $testPerformer )
		);
		self::$blockedTargets = [ '1.2.3.4', '1.2.3.0/24', '0:0:0:0:0:0:0:0/19', $globallyBlockedUser ];
		self::$globallyBlockedUser = $globallyBlockedUser;
	}
}
