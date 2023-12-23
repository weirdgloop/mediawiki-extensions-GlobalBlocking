<?php

namespace MediaWiki\Extension\GlobalBlocking;

use IContextSource;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\MediaWikiServices;
use stdClass;
use User;
use WikiMap;

// FIXME breaks most methods of DatabaseBlock, some if them in dangerous ways.
//   Should subclass AbstractBlock instead.
class GlobalBlock extends DatabaseBlock {
	/** @var int */
	private $id;

	/**
	 * @var array
	 */
	protected $error;

	/**
	 * @param stdClass $block
	 * @param array $error
	 * @param array $options
	 */
	public function __construct( stdClass $block, array $error, $options ) {
		parent::__construct( $options );

		$this->id = $block->gb_id;
		$this->error = $error;
		$this->setGlobalBlocker( $block );
	}

	/** @inheritDoc */
	public function getId( $wikiId = self::LOCAL ): ?int {
		return $this->id;
	}

	/**
	 * @inheritDoc
	 */
	public function getPermissionsError( IContextSource $context ) {
		return $this->error;
	}

	/**
	 * DatabaseBlock requires that the blocker exist or be an interwiki username,
	 * so do some validation to figure out what we need to use (T182344)
	 *
	 * @param stdClass $block DB row from globalblocks table
	 */
	public function setGlobalBlocker( stdClass $block ) {
		$user = User::newFromName( $block->gb_by );
		// If the block was inserted from this wiki, then we know the blocker exists
		if ( $block->gb_by_wiki === WikiMap::getCurrentWikiId() ) {
			$this->setBlocker( $user );
			return;
		}
		// If the blocker is the same user on the foreign wiki and the current wiki
		// then we can use the username
		$lookup = MediaWikiServices::getInstance()
			->getCentralIdLookupFactory()
			->getLookup();
		if ( $user->getId() && $lookup->isAttached( $user )
			&& $lookup->isAttached( $user, $block->gb_by_wiki )
		) {
			$this->setBlocker( $user );
			return;
		}

		// They don't exist locally, so we need to use an interwiki username
		$this->setBlocker( User::newFromName( "{$block->gb_by_wiki}>{$block->gb_by}", false ) );
	}
}
